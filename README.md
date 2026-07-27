# WikiOasis Deploy Portal

A Laravel web app that replaces `mwdeploy`, the Python/curses CLI, as the way
MediaWiki core, extensions, skins and config reach the WikiOasis appserver fleet.

It runs **on the Salt master** and talks to the fleet **only** through the local
`salt` binary. There is no SSH transport in this application. `mwdeploy` itself is
cut down from an orchestrator into `mwdeploy-shim`, a set of atomic,
single-purpose subcommands the portal invokes one at a time, once per server.

```
Browser
  │
  ▼
Laravel (on salt-us-east-021)
  │  MySQL — deploy history, job state, repo/patch registry
  │  queue worker — one RunDeployment job per deployment
  │  Reverb — live dashboard over Laravel Echo
  │
  ├─► symfony/process → /usr/bin/salt --out=json --static <minion> cmd.run_all '<mwdeploy-shim …>'
  └─► git (via the shim, on staging) for branch/commit listings
```

## What is new relative to `mwdeploy`

| Capability | `mwdeploy` | Portal |
|---|---|---|
| Pick a branch **or commit** per repo | no — always `fetch && reset --hard FETCH_HEAD` | yes, per checkout |
| Deploy to one version / several / all | no notion of versions at all | yes; each version keeps its own pinned ref |
| Cut a new core version | by hand, then re-clone ~100 extensions one by one | one form: reconstruct from an existing version |
| **Undeploy** an extension, skin or version | no concept of it | yes, per version, behind its own permissions |
| Deploy history | last run only, in `/var/log/mwdeploy-state.json` | every run, queryable |
| Rollback | no concept of it | yes — and it reverses removals as well as ref changes |
| Users and permissions | whoever is on the Salt master shell | accounts, roles, 18 permissions, enforced TOTP |
| Patch consistency | `--patch`/`--patch-target` retyped each time | registry; target path stored on the patch |
| Repo registration | by hand on disk | form, with a reachability check, then a reviewable clone |
| Blocking canary prompt | curses `Prompter.input()` | web modal the job polls for |

## The version model

A repository is one logical thing — "the Echo extension". It is *checked out* once
per core version it belongs to, and each checkout is what a deployment acts on:

```
repositories          Echo                     (name, type, git_url, default_branch)
repository_versions   Echo @ 1.45  → versions/1.45/extensions/Echo   pinned REL1_45
                      Echo @ 1.46  → versions/1.46/extensions/Echo   pinned REL1_46
```

Each checkout carries its own **ref mode**: `pinned` to a fixed branch (the
default), `default_branch` to follow the repository's, or `floating` to be chosen
every time. So "deploy Echo to all versions" sends REL1_45 to 1.45 and REL1_46 to
1.46 in one action, without the operator retyping anything — and any row can still
be overridden per deployment.

MediaWiki core is modelled the same way: one `mediawiki` repository, whose
checkouts *are* the `versions/<ver>` trees. Config sits outside the version tree
and has one unversioned checkout.

### Creating and removing versions

- **Cut 1.46 from 1.45** — scaffolds `versions/1.46/`, then registers and clones
  core plus every extension and skin 1.45 currently has, at a ref you pick per
  repository. It runs as an ordinary deployment: reviewable call-by-call, live on
  the dashboard, and rollbackable.
- **Undeploy** a checkout, or a whole version. Removal is `rm -rf` on staging and
  on every server, one call per host, guarded so the shim refuses anything outside
  the deploy root and refuses a bare `versions/<ver>` without an explicit flag.
  Undeploying a version is refused while wikis still point at it.

Removal is a **separate grant** from deployment throughout
(`deploy.undeploy_extension`, `deploy.undeploy_skin`, `deploy.undeploy_version`),
and every removal is reversible — see below.

## Layout

```
app/
  Actions/Deployments/     CreateDeployment, RollbackDeployment
  Actions/Repositories/    RegisterRepository, RegisterCheckout
  Actions/Versions/        CreateVersion (reconstruct), UndeployVersion
  Enums/                   statuses, step names, ref types, decisions
  Http/                    controllers, form requests, RequireTwoFactor
  Jobs/RunDeployment.php   the queued job; unique per staging tree
  Policies/                who may deploy, roll back, decide, manage
  Services/Deployment/     the orchestrator — runner, rollout pool, decision gate
  Services/Git/            branch/commit discovery (salt | local | none)
  Services/Salt/           the only code that touches the fleet
  Support/                 DeploymentOptions, PathResolver, Permissions
shim/
  mwdeploy_shim.py         install as /usr/local/bin/mwdeploy-shim on every minion
  tests/                   python unittest suite for the shim
docs/
  OPEN-QUESTIONS.md        section 7 of the handoff spec, answered
  OPERATIONS.md            installing and running the portal
  SALT-INTEGRATION.md      handoff for wiring this into the SaltStack repo
```

The orchestration is worth reading in one sitting, in this order:
`app/Jobs/RunDeployment.php` → `app/Services/Deployment/DeploymentRunner.php` →
`ServerPipeline.php` → `RolloutPool.php`.

## Install

Requires PHP 8.3+, Composer, Node 20+, MySQL 8 (or SQLite for local work), and a
Salt master with the `salt` CLI on `$PATH`.

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
# Edit .env: DB_*, REVERB_*, and the MWDEPLOY_* block at the bottom.

php artisan migrate --force
php artisan db:seed --force            # roles and permissions (idempotent)
php artisan mwdeploy:create-user you@wikioasis.org --role=admin
```

Then, still in `.env`, point the portal at the fleet:

```dotenv
MWDEPLOY_STAGING_TARGET=staging               # Salt minion id of the staging host
MWDEPLOY_SHIM_BINARY=/usr/local/bin/mwdeploy-shim
MWDEPLOY_RSYNC_SOURCE=rsync://staging.wikioasis.org/mediawiki/
```

Register the appservers and proxies under **Targets**. `hostname` must equal the
Salt minion id exactly — check it against `salt-key -L`.

Install the shim on every minion (including staging and the proxies):

```bash
install -m 0755 shim/mwdeploy_shim.py /usr/local/bin/mwdeploy-shim
```

Full operational detail — systemd units, the rsync daemon, sudo rules, HAProxy
socket permissions — is in [`docs/OPERATIONS.md`](docs/OPERATIONS.md). To manage
all of that from Salt, including the nginx vhost and the HAProxy frontend/backend,
follow [`docs/SALT-INTEGRATION.md`](docs/SALT-INTEGRATION.md).

## Running it

Three processes:

```bash
php artisan queue:work --queue=default --tries=1 --timeout=0   # deploy jobs
php artisan reverb:start                                       # live dashboard
# plus php-fpm / nginx for the app itself
```

`--timeout=0` matters: a deploy job blocks for as long as its rsyncs, l10n
rebuilds and canary retries take, and a worker timeout mid-rollout would leave
the fleet half-updated.

## Tests

```bash
php artisan test                                       # 127 tests
python3 -m unittest discover -s shim/tests -t .        # 69 tests
vendor/bin/pint --test                                 # style
```

Nothing in either suite shells out to a real `salt`: `FakeSaltClient` records
every call and answers from a queue of canned results, so the orchestration is
tested against exact call sequences (see
`tests/Feature/RolloutBehaviourTest::a_happy_path_deployment_runs_the_whole_sequence_in_order`).

## How a deployment runs

1. For a version removal, read the farm's wiki → version map and refuse if any
   wiki still uses it (failing closed if the map cannot be read).
2. Read every checkout's current state on staging → `repo_state_snapshots`. This
   happens **before** anything mutates staging, so the undo point is real.
3. For a new core version, scaffold `versions/<ver>/`.
4. Removals on staging: the staging tree *and* the staging host's own production
   copy, or one `rm -rf versions/<ver>` for a whole version.
5. `git-checkout` each ref, cloning first if the checkout is not on disk.
6. Apply selected patches.
7. `rsync-local` staging → production on the staging host, restricted to the paths
   being deployed. Skipped entirely when everything was a removal — "no paths"
   means "the whole tree" to rsync.
8. Optional `l10n-rebuild` on staging.
9. Canary on staging. This gates the fleet rollout.
10. Per appserver, up to `--parallel N` at once: depool from every proxy →
    removals → `rsync-remote` → optional `l10n-rebuild` → canary → repool.
11. Aggregate the per-server results, then reconcile the registry with what is now
    on disk — only on success, since a half-finished deployment leaves a state the
    registry cannot describe.

A canary failure without `--force` parks that one pipeline on a blocking prompt
(Continue / Abort only / Abort and roll back) while the other pipelines keep
moving. An unanswered prompt applies `MWDEPLOY_DECISION_TIMEOUT_DEFAULT` rather
than leaving the fleet parked mid-rollout. Aborting still repools every server it
depooled.

## Rollback

A rollback is just another deployment: `RollbackDeployment` creates one from the
failed deployment's `repo_state_snapshots`, then dispatches the same
`RunDeployment` job. There is no second code path.

Snapshots record **presence** as well as ref, which is what makes one
implementation reverse every direction:

| Before the deployment | Rollback does |
|---|---|
| present at ref X | check X back out |
| present, now removed | clone it back and check out X |
| absent, now present | remove it again |

So undoing an undeploy, undoing a newly added extension, undoing a whole new core
version and undoing a plain ref change are the same code.

- **Automatic** on a canary failure without `--force`, scoped to the servers the
  failed deployment actually reached.
- **Manual** from any past deployment in history. The UI warns when a newer
  deployment has since touched the same repos.
- **Never recursive**: a rollback that fails its own canary is surfaced for
  manual intervention. One automatic hop only.

`--force` is deliberately dropped from a rollback's options — a rollback must not
skip its own canary.

## Out of scope for v1

Salt orchestrate/reactor rollouts (the portal does the sequencing); rolling back
schema migrations or R2 content (rollback is git refs and presence only — if a
deploy included a migration, a human still has to deal with the schema); owning the
wiki → version mapping (that lives in mw-config; the portal only reads it to refuse
an unsafe version removal); and CI-triggered deploys. This is a manual-trigger
portal replacing a manual-trigger CLI.
