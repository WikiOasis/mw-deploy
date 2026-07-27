# Open questions from the handoff spec

Section 7 of the handoff spec listed six questions to resolve before or during the
build. Each is answered below with what the code currently does, so nothing is
blocked on a decision — but four of them are **defaults chosen by the
implementation, not decisions Thomas has made**, and are flagged as such. All four
are one config value away from changing.

---

## 1. How do bits reach each appserver, if not SSH from the orchestrator?

**Decided in code (needs confirmation).** Each appserver **pulls** from an rsync
daemon module exported by the staging host.

The original CLI rsynced *from* the orchestrator to each appserver over SSH as the
deploy user. Because the portal only ever runs commands *on* a minion, the
transfer has to be a pull: `mwdeploy-shim rsync-remote` runs on the appserver with
`--src` pointing at something that appserver can reach.

```dotenv
MWDEPLOY_RSYNC_SOURCE=rsync://staging.wikioasis.org/mediawiki/
```

The shim treats that value as an opaque rsync source, so switching to an NFS
export is a one-line env change (`MWDEPLOY_RSYNC_SOURCE=/mnt/mediawiki-staging/`)
with no code change. The rsync flags and excludes are carried over from the
original `rsync_local`/`rsync_remote` unchanged.

**Why not `cp.push`/`cp.get_file`:** Salt's file-transfer primitives move whole
files through the master's ZeroMQ transport with no delta encoding and no
`--delete`. A MediaWiki tree across 700+ wikis is exactly the shape rsync exists
for, and losing `--delete` means a file removed upstream keeps being loadable on
the appservers. Keeping rsync also keeps the exclude list and the battle-tested
flag set intact.

**What Thomas needs to confirm:** whether an rsync daemon on the staging host is
acceptable, or whether the farm already has an NFS export to point at instead. See
`docs/OPERATIONS.md` for the `rsyncd.conf` this assumes.

---

## 2. Should concurrent deployments be blocked or queued?

**Decided in code: queued, fleet-wide.** Staging is a single working tree, so two
concurrent deployments would stomp the same checkout.

`RunDeployment` implements `ShouldBeUnique` with a constant key:

```php
public function uniqueId(): string
{
    return 'mwdeploy-staging-tree';   // the shared resource, not the deployment
}
```

The lock is deliberately keyed on the *staging tree*, not the deployment id — the
constraint being protected is the shared checkout, so a second deployment queued
while one is running simply waits its turn rather than being rejected. The
dashboard says as much ("Staging is a single working tree, so deployments run one
at a time").

`uniqueFor` is 6 hours, which is a backstop against a crashed worker holding the
lock forever, not an expected deploy duration.

---

## 3. SSO, or a standalone password store?

**Not decided — standalone auth is built, and is the part most likely to be
thrown away.** This is the one question where the answer genuinely should have
landed before the code.

What exists now: Laravel Fortify with email/password, password reset, and
**mandatory TOTP** for any account holding a permission that can change
production (`App\Support\Permissions::requiringTwoFactor()`, enforced by
`App\Http\Middleware\RequireTwoFactor`). Self-registration is disabled; accounts
come from the users screen or `php artisan mwdeploy:create-user`.

If WikiOasis has staff SSO (the spec suggests checking the Steward Portal), it
should be wired in via Socialite and this password store dropped. The blast radius
of that change is small and contained:

- `config/fortify.php` — drop `resetPasswords`, `updatePasswords`
- `App\Actions\Fortify\*` — delete
- `resources/views/auth/{login,forgot-password,reset-password,confirm-password}.blade.php` — delete
- `App\Http\Controllers\UserController::store` — no longer sets a password

**Everything else stays.** Roles, permissions, policies, the per-repo scoping and
the audit trail are all keyed on `users.id` and do not care how someone
authenticated. Keep the TOTP requirement even under SSO unless the SSO enforces
its own MFA — this app can push code to 700+ wikis' production servers.

---

## 4. Do minion ids match `deploy_targets.hostname`?

**Cannot be answered from here — it is an inventory fact, not a design choice.**
The code assumes they match exactly, and makes that assumption loud rather than
silent:

- `deploy_targets.hostname` is passed verbatim as the Salt target.
- The Targets screen says so, and points at `salt-key -L`.
- A target that matches no minion produces a step failure with Salt's own
  "Minion did not return. [No response]" as the log line, not a silent success —
  see `SaltOutputParser::looksLikeSaltFailureNotice()` and its test.

Two related affordances, since minion ids and HAProxy labels often differ:

- `deploy_targets.haproxy_server_name` overrides the label used in
  `set server <backend>/<server>`, falling back to the hostname.
- `deploy_targets.haproxy_backend` overrides the backend per proxy.

**On the `--servers all` nodegroup:** not used, deliberately. An empty server list
means "every active appserver in `deploy_targets`", and the portal expands it to
explicit per-host calls. A nodegroup would move fleet membership into
`/etc/salt/master`, where the portal cannot show it on the review screen or record
it in history. If a nodegroup is the source of truth for "all mw appservers", the
right move is a small sync command that populates `deploy_targets` from it, not
targeting the nodegroup directly.

---

## 5. Patch format: unified diff or git-format?

**Both, per patch.** Rather than guess, `patches.format` is `unified` (default) or
`git`, chosen on the registry form:

| format | apply | dry run |
|---|---|---|
| `unified` | `patch -p1 --forward --batch --fuzz=0` | `… --dry-run` |
| `git` | `git apply --verbose` | `git apply --check` |

**One deliberate change from the original tool:** `--fuzz=0`. GNU `patch` defaults
to a fuzz factor of 2, which means a patch whose context no longer matches
upstream gets applied *somewhere nearby* and exits 0. Silently landing a deploy
patch in the wrong place is worse than refusing it, and section 4.5's "fails
loudly on a bad apply" depends on the refusal. This was verified against real
`patch(1)`: without `--fuzz=0` a patch with entirely wrong context reported
"Hunk #1 succeeded at 1 with fuzz 2" and exit 0. Test:
`shim/tests/test_mwdeploy_shim.py::PatchApplyTest::test_a_stale_patch_is_refused_rather_than_applied_with_fuzz`.

Existing patches under `{prod_path}/scripts/extensions/patches/` should be
re-registered through the form so their format and target path are recorded. Any
that only applied because of fuzz will now fail their dry run — which is the point
of the "Dry run" button in the patch registry.

---

## 6a. The version model, undeploy, and what those settled

Added after the first pass, when the multiversion requirements were spelled out.
These were **decisions Thomas made**, recorded here so the reasoning is not lost.

**A repository is not a checkout.** `repositories` holds the logical thing (Echo);
`repository_versions` holds one row per core version it is checked out in, each with
its own path and its own ref pin. The alternative — one row per (name, version) —
was cheaper but meant "Echo" was never one thing in the database, and every later
feature would have worked around that.

**Each checkout pins its own ref, and the pin is a default not a restriction.**
`ref_mode` is `pinned` (the usual case: 1.45 tracks REL1_45), `default_branch`
(follow the repository's), or `floating` (choose every time). "Deploy Echo to all
versions" therefore sends the right branch to each version with no retyping, and
any row can still be overridden per deployment, or in bulk from the wizard.

**Undeploy is per version, and reversible.** Removing Echo from 1.46 leaves 1.45
alone. The `repository_versions` row survives as `undeployed`, so restoring it needs
no re-registration — and because snapshots record presence as well as ref, the
ordinary rollback path restores it at the ref it was on. A repository that ends up in
no version deactivates automatically; deactivating a repository through the registry
form deliberately does *not* delete anything, and says so.

**Removal is a separate permission from deployment, at every level.**
`deploy.undeploy_extension`, `deploy.undeploy_skin`, `deploy.undeploy_config`,
`deploy.undeploy_version`. Per-repository scoping applies on top of all of them, so
a maintainer scoped to Echo can remove Echo and nothing else. The seeder gives
`deployer` none of them, and `deploy.undeploy_version` only to `admin`.

**A new version is reconstructed from an existing one.** "Cut 1.46 from 1.45"
registers and clones core plus every extension and skin 1.45 has, each at a ref you
pick. Copied checkouts inherit the source version's pin, which is right for a
moving branch and wrong for a release branch — so the review screen lists every ref
before a single clone runs, and there is a per-repository override. Deriving the set
from mw-config's enabled-extension list was considered and rejected for v1: it needs
knowledge of that file's location and format, and breaks when either changes.

**Version creation and removal are ordinary deployments.** Same job, same review
screen, same live dashboard, same rollback. Rolling back a version-create removes
the version; rolling back a version-undeploy rebuilds it. That falls out of the
snapshot's presence columns rather than being special-cased.

## 6b. Safety decisions around removal

These were not asked about, but `rm -rf` on a portal-supplied path warrants stating.

**The shim refuses unsafe paths itself.** `repo-remove --path P --root R` resolves
both sides through `realpath` and refuses: a path outside the root, the root itself,
the `versions/` directory, a bare `versions/<ver>` without `--allow-version-root`, a
relative path, a path containing `..`, a symlink pointing out of the root, and
anything that is not a directory. A sibling that merely shares a name prefix
(`/srv/mediawiki-old` against a `/srv/mediawiki` root) is refused too. Eleven tests
cover exactly these.

**Removal does not rely on rsync `--delete` propagating a deletion.** It would
probably work, but the semantics under a path-restricted include set are subtle and
change entirely under NFS. Instead an explicit `repo-remove` runs on staging and on
each appserver — deterministic, attributable per host, and transport-independent.

**A removal-only deployment does not rsync at all.** "No paths" means "the whole
tree" to rsync, so conflating "nothing to sync" with "sync everything" would turn
removing one extension into a full-fleet tree walk. `SyncPlan` models the three
cases explicitly.

**Undeploying a version fails closed.** The runner reads the farm's wiki → version
map through the shim and refuses if any wiki still uses that version — and also
refuses if the map cannot be read or is in a shape the shim does not recognise.
Guessing there means deleting the version everything is running on.
`MWDEPLOY_REQUIRE_WIKIVERSION_CHECK` can disable it for a farm whose map is
genuinely unreachable, and the versions page shows a warning when it is off.

**Where the map lives is still a fact we need.** `MWDEPLOY_WIKIVERSIONS_PATH`
defaults to `/srv/mediawiki/config/wikiversions.json` and the shim accepts
`{"wiki": "1.45"}`, `{"wiki": "php-1.45"}` and `{"wiki": {"version": "1.45"}}`.
**Confirm the real path and format**, because until that is right, version removal is
blocked by design rather than by accident.

**The registry is only reconciled on success.** A deployment that failed halfway
leaves the fleet in a state the registry cannot describe, so presence is not updated
— claiming otherwise would make the next rollback wrong.

## 6. Coarse per-type permissions, or per-repo scoping?

**Both are built; coarse is what you get unless you opt in per repository.**
`repository_permissions` exists but is inert until used, so the complexity cost is
only paid by teams that want it.

The semantics are the important part:

> A repository with **no** rows in `repository_permissions` is governed purely by
> its coarse `deploy.<type>` permission. A repository with **at least one** row
> additionally requires the actor to match one of those rows.

That way turning on scoping for one extension does not silently lock everyone out
of every other extension — the failure mode that makes per-object permissions
miserable to adopt. Tested in
`tests/Feature/DeploymentWizardTest::per_repository_scoping_narrows_a_maintainer_to_their_own_extensions`.

For a list screen the check is `User::deployableRepositories()`, which resolves the
whole set in two queries rather than two per repository — worth knowing before
someone registers two hundred extensions.

**Recommendation for v1:** ship with coarse permissions only and add scoping rows
when a specific maintainer asks. The `extension-maintainer` role exists in the
seeder for exactly that case.

---

## Decisions taken that were not in the spec's list

Flagging these because they are also judgement calls, not requirements.

**`cmd.run_all`, not `cmd.run`.** The spec says `cmd.run`. `cmd.run` returns only
stdout, so a shim that crashed before printing its JSON is indistinguishable from
one that succeeded silently. `cmd.run_all` returns `retcode`/`stdout`/`stderr`
separately. `SaltOutputParser` handles both shapes and the module is configurable
(`MWDEPLOY_SALT_MODULE`), so switching back is an env change.

**An unanswered canary prompt aborts and rolls back.** The spec says both "don't
just abort — automatically enqueue a rollback" and "the prompt should offer 'Abort
only' vs 'Abort and roll back' rather than assuming either". Those pull in opposite
directions for an *unattended* failure, so: an operator always chooses, and only a
prompt nobody answers within `MWDEPLOY_DECISION_TIMEOUT` (default 15 min) falls
back to `MWDEPLOY_DECISION_TIMEOUT_DEFAULT` (default `abort_and_rollback`). The
reasoning is the spec's own: leaving the farm mid-rollout on a broken ref is the
worse outcome.

**A staging failure before rsync also rolls back.** The spec mandates automatic
rollback on canary failure. The runner extends it to any staging-phase failure
that happened *after* at least one successful `git-checkout` — a half-checked-out
staging tree is not a state to leave behind, and rolling it back is cheap because
production has not been touched yet. Tracked by `DeploymentRunner::$stagingMutated`.

**A failed depool aborts that server.** The original tool's `_depool` was
best-effort. Here a depool failure stops that server's pipeline before the rsync,
because syncing a box that is still taking traffic on some proxy defeats the
purpose of the rollout flag. Whatever *was* depooled is repooled.

**Ref discovery defaults to `salt`, not `local`.** The spec prefers shelling to git
locally. That is only correct when the Salt master and the staging tree are the
same machine. The `salt` driver asks the staging minion via `mwdeploy-shim
git-refs` and is correct either way; `local` remains available and is cheaper
(`MWDEPLOY_GIT_DRIVER=local`).

**`git-refs` and `git-head` are new shim verbs.** Not in the spec's table, but both
are atomic and single-purpose in the same spirit: `git-head` is what makes
`repo_state_snapshots` possible, and `git-refs` backs the ref picker without
needing a GitHub token.

**Checkout paths are immutable after registration.** They are stored on the
`repository_versions` row rather than recomputed, because they are what
`repo-remove` is pointed at: a path that can drift is a path that can delete the
wrong directory. The edit form says so and offers no way to change them.

**A branch checkout lands on a branch, not a detached HEAD.** `git-checkout --ref
master` creates/resets a local branch tracking `origin/master`. If it detached,
`git-head` would record a SHA and the rollback would pin to that commit instead of
restoring the branch — the snapshot would be technically accurate and practically
wrong.
