# WikiOasis Console

A Laravel single-page app that hosts WikiOasis's operational tooling as a set of
**apps**, with one central place for accounts and access. You sign in, you get a
launcher, and you open what you have been granted.

Today there is one app: **Deployments** — the replacement for `mwdeploy`, the
Python/curses CLI, as the way MediaWiki core, extensions, skins and config reach
the WikiOasis appserver fleet.

It runs **on the Salt master** and talks to the fleet **only** through the local
`salt` binary. There is no SSH transport in this application. `mwdeploy` itself is
cut down from an orchestrator into `mwdeploy-shim`, a set of atomic,
single-purpose subcommands the deployments app invokes one at a time, once per
server.

```
Browser — Vue 3 single-page app (one shell view, everything else client-routed)
  │  fetch /api/* — session cookie, CSRF, no API tokens
  ▼
Laravel (on salt-us-east-021)
  │  MySQL — deploy history, job state, repo/patch registry
  │  queue worker — one RunDeployment job per deployment
  │  Reverb — live deployment view over Laravel Echo, with a poll behind it
  │
  ├─► symfony/process → /usr/bin/salt --out=json --static <minion> cmd.run_all '<mwdeploy-shim …>'
  ├─► git (via the shim, on staging) for branch/commit listings
  └─► mwdeploy-shim tree-scan (read-only) to inventory a farm that already exists
```

Sign-in, the TOTP challenge and password resets stay **server-rendered**. Fortify
owns those flows, and an ops tool whose login page depends on a JavaScript bundle
having loaded is an ops tool you cannot get into on the day the bundle is what
broke. Everything behind sign-in is the SPA: one shell view containing nothing but
a mount point, and no page loads after it.

## Apps

The console is a shell around a set of apps. An app is a submodule with a boundary
around it:

* **its own permissions.** Every permission belongs to exactly one app — see
  `App\Support\Permissions::groups()`. `deploy.core` is the deployments app's;
  `users.manage` is the console's.
* **its own access grant.** `apps.<id>.access` opens the app and grants nothing
  inside it: that is what the `viewer` role holds. Holding any of the app's own
  permissions also implies access, so granting `deploy.core` is enough on its own.
* **its own API routes**, in `routes/apps/<id>.php`, loaded inside
  `app.access:<id>` middleware. An account with no grant in an app cannot reach
  any of it — not merely the screens the launcher hides.
* **its own screens**, under `resources/js/apps/<id>/`, mounted at its own path
  prefix, contributing their own nav and chrome buttons through a manifest.

```
/                       the launcher — the apps this account may open
/access                 accounts, roles, and which app permissions each role grants
/deployments            the deployments app (overview, history, wizard, registry…)
```

Installing an app is one line in `config/console.php`. Nothing else knows the
list: the launcher, the nav, the API route table and the permission admin are all
built from the registry (`App\Apps\AppRegistry`) and its client-side mirror
(`resources/js/apps/index.js`). `CONSOLE_DISABLED_APPS` switches one off for an
install — it vanishes from the launcher and its API answers 404.

Access is granted centrally, on `/access`: accounts hold roles, roles hold
permissions, and ticking an app's access permission is what puts its tile on its
members' launcher. After adding an app, re-run `php artisan db:seed --force` —
that is how new permissions reach the existing roles.

## What is new relative to `mwdeploy`

| Capability | `mwdeploy` | Deployments app |
|---|---|---|
| Pick a branch **or commit** per repo | no — always `fetch && reset --hard FETCH_HEAD` | yes, per checkout |
| Deploy to one version / several / all | no notion of versions at all | yes; each version keeps its own pinned ref |
| Cut a new core version | by hand, then re-clone ~100 extensions one by one | one form: reconstruct from an existing version |
| **Undeploy** an extension, skin or version | no concept of it | yes, per version, behind its own permissions |
| Ship staging as it stands | the only mode there was | yes, as an explicit mode behind its own permission |
| Deploy history | last run only, in `/var/log/mwdeploy-state.json` | every run, queryable |
| Rollback | no concept of it | yes — and it reverses removals as well as ref changes |
| Users and permissions | whoever is on the Salt master shell | accounts, roles, 22 permissions across the console and its apps, enforced TOTP |
| Patch consistency | `--patch`/`--patch-target` retyped each time | registry; target path stored on the patch |
| Repo registration | by hand on disk | form, with a reachability check, then a reviewable clone |
| Adopting an existing farm | n/a — the CLI *was* the farm's history | `tree-scan` reads the tree and fills the registry in from it |
| Registering wiki config | edit paths by hand | one field: paste the git URL |
| Blocking canary prompt | curses `Prompter.input()` | web modal the job polls for |
| Interface | curses, one terminal at a time | single-page app over a JSON API, live over websockets |

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

The same idea one axis over is what a core upgrade is made of: the wizard can select
**every extension — or every skin — in one version and put them all on one ref**, so
"put 1.46 on REL1_46" is three answers rather than a hundred ticked boxes. The rows
it selects are ordinary line items — each appears with its own ref, individually
editable, and the review step still lists every Salt call before anything runs.

MediaWiki core is modelled the same way: one `mediawiki` repository, whose
checkouts *are* the `versions/<ver>` trees. Config sits outside the version tree
and has one unversioned checkout.

## Adopting a farm that already exists

The deployments app is not usually installed onto an empty disk. A farm that has been
running for years already has `versions/1.45/extensions/…` checked out, on refs
somebody chose, from remotes somebody picked — and asking an operator to retype a
hundred extensions into a form to describe code that is already there is not a
migration path.

So it reads the tree instead. `mwdeploy-shim tree-scan` walks the deploy
root and reports what it finds:

```
versions/<ver>                    core, plus MW_VERSION out of includes/Defines.php
versions/<ver>/extensions/<Name>  extension
versions/<ver>/skins/<Name>       skin
extensions/, skins/               unversioned checkouts, for a farm mid-migration
<config_dir>                      wiki config
```

For each one it reads, straight off disk and without shelling out to git per
repository: the remote from `.git/config`, the current branch or detached commit
from `.git/HEAD` (following the `gitdir:` pointer when `.git` is a file, which it is
for anything that was ever a submodule), the commit from the loose ref or
`packed-refs`, and the declared name, version and licence from `extension.json` or
`skin.json` — so the inventory knows that the directory called `Echo` contains the
extension *Notifications*. Several hundred checkouts cost one process, not a few
thousand.

**Import** then diffs that against the registry and shows you the difference, one
row at a time:

| | |
|---|---|
| Register | on disk, nothing in the registry knows about it |
| Add checkout | the repository is registered, but not in this core version |
| Adopt | the row says undeployed, but the checkout is right there |
| Update pin | registered and present, sitting on a different ref than it pins |
| Update remote | the registered git URL is not the one the checkout came from |
| Mark undeployed | the registry says present; the tree does not have it |
| Cannot import | a directory with no git remote — nothing could describe how to deploy it |

The additive rows are ticked by default; the three that rewrite an existing
decision are not. **Nothing in the import path touches the tree** — no clone, no
checkout, no rsync, no removal. It writes registry rows describing code that is
already on disk, which is why an imported checkout is recorded as *already
deployed*, pinned to the ref it is actually on. Getting that wrong in the other
direction would make the first deployment of every extension a clone over a live
tree.

The same thing works before anyone has signed in:

```bash
php artisan mwdeploy:import-tree                  # show the plan, change nothing
php artisan mwdeploy:import-tree --apply          # adopt everything additive
php artisan mwdeploy:import-tree --apply --repin  # also match pins to the tree
```

Reading the tree is also the only chance to record what it looks like, so every
scan — including the dry runs nobody applies — updates the *observation* columns on
each checkout. Those are kept strictly apart from the pin, which is what lets the
repository screen say "the registry pins REL1_45 but staging is on REL1_44" instead
of quietly overwriting one with the other. The dashboard counts those disagreements;
zero is the healthy answer.

### Wiki config, in one field

`mw-config` is the repository every farm has exactly one of, always at the same
place in the tree, never versioned — so the general form's name, type, version and
pin questions all have exactly one possible answer. The config screen asks for the
git URL and works the rest out, including which situation the farm is in:

- the checkout is already on disk → **adopt** it, registry only, no clone over the
  top of a live config repository;
- it is not → **register and clone** it onto staging, as an ordinary reviewable,
  rollbackable deployment.

Where it lives is configurable (`MWDEPLOY_CONFIG_DIR`), because farms disagree:
`config`, `mw-config` and `wikiconfig` are all in the wild.

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

### Deploying staging as it stands

**Sync staging** (`/deployments/sync`) deploys the staging tree exactly as it is
on disk: no fetch, no checkout, no selection. It is the escape hatch for work that
never came from a ref — a file edited directly on staging, a patch applied by
hand, a checkout repaired in place — and the plan is just the rsync of the whole
tree, plus the same canary gate and depool/repool rollout every other deployment
gets.

Two things follow from having no line items. It is **not** scoped to a repository,
so it ships whatever anyone else has staged too and is gated on its own permission
(`deploy.sync_staging`) rather than on the per-type deploy grants. And it records
no refs, so there is no undo point: rollback is not offered for it, and the way
back is another deployment.

## Layout

```
app/
  Apps/                    the app registry: ConsoleApp, BaseApp, AppRegistry
  Apps/Deployments/        the deployments app's definition (id, path, routes)
  Actions/Deployments/     CreateDeployment, RollbackDeployment
  Actions/Import/          ApplyImport — the only writer of "already deployed" rows
  Actions/Repositories/    RegisterRepository, RegisterCheckout, RegisterConfigRepository
  Actions/Versions/        CreateVersion (reconstruct), UndeployVersion
  Enums/                   statuses, step names, ref types, decisions
  Http/Controllers/Api/    the JSON API the SPA talks to; one controller per screen
  Http/Resources/          payload shapes, so a field is named once
  Http/                    form requests, RequireTwoFactor, EnsureAppAccess, the shell
  Jobs/RunDeployment.php   the queued job; unique per staging tree
  Policies/                who may deploy, roll back, decide, manage
  Services/Deployment/     the orchestrator — runner, rollout pool, decision gate
  Services/Discovery/      tree scan → import plan; reads the farm, writes nothing
  Services/Git/            branch/commit discovery (salt | local | none)
  Services/Salt/           the only code that touches the fleet
  Support/                 DeploymentOptions, PathResolver, Permissions
resources/js/
  app.js, router.js        the SPA entry point; console routes + each app's
  api.js, store.js, live.js  fetch wrapper, session/app/flash state, Echo + polling
  components/              the shared component library: panels, fields, combobox
  console/                 the chrome, the launcher, the access screen
  apps/index.js            the client-side app registry, mirroring config/console.php
  apps/deployments/        that app's manifest, screens and own components
  auth.js                  a few kB for the server-rendered sign-in pages
routes/
  web.php                  the SPA shell and TOTP enrolment
  api.php                  the console's API, plus one guarded group per app
  apps/deployments.php     the deployments app's own endpoints
shim/
  mwdeploy_shim.py         install as /usr/local/bin/mwdeploy-shim on every minion
  tests/                   python unittest suite for the shim
docs/
  OPEN-QUESTIONS.md        section 7 of the handoff spec, answered
  OPERATIONS.md            installing and running the console
  SALT-INTEGRATION.md      handoff for wiring this into the SaltStack repo
```

The orchestration is worth reading in one sitting, in this order:
`app/Jobs/RunDeployment.php` → `app/Services/Deployment/DeploymentRunner.php` →
`ServerPipeline.php` → `RolloutPool.php`.

The adoption path is a shorter read, in this one: `shim/mwdeploy_shim.py`'s
`cmd_tree_scan` → `app/Services/Discovery/TreeScanner.php` → `ImportPlanner.php` →
`app/Actions/Import/ApplyImport.php`.

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

`npm run build` is not optional: the interface is a bundled single-page app, and the
`@vite` directive fails loudly rather than serving a broken page if the manifest is
missing. Nothing is fetched from a CDN at runtime — the Salt master is not
necessarily allowed to reach the internet.

If the farm already has MediaWiki on disk, fill the registry in from it rather than
by hand:

```bash
php artisan mwdeploy:import-tree                  # what it found, and what it would do
php artisan mwdeploy:import-tree --apply --as=you@wikioasis.org
```

Then, still in `.env`, point the deployments app at the fleet:

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
php artisan test                                       # 158 tests
python3 -m unittest discover -s shim/tests -t .        # 88 tests
vendor/bin/pint --test                                 # style
npm run build                                          # the SPA bundle
```

Nothing in the PHP suite shells out to a real `salt`: `FakeSaltClient` records
every call and answers from a queue of canned results, so the orchestration is
tested against exact call sequences (see
`tests/Feature/RolloutBehaviourTest::a_happy_path_deployment_runs_the_whole_sequence_in_order`).
`TreeImportTest` uses the same mechanism to assert the stronger claim about
importing: that the only Salt call an import makes is the read-only scan.

The shim suite does the opposite — it builds real git repositories in a temporary
directory and runs the shim as a subprocess, because `tree-scan` is an assertion
about what git leaves on disk and a hand-written `.git/config` would only prove the
parser agrees with itself.

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

Salt orchestrate/reactor rollouts (the app does the sequencing); rolling back
schema migrations or R2 content (rollback is git refs and presence only — if a
deploy included a migration, a human still has to deal with the schema); owning the
wiki → version mapping (that lives in mw-config; the app only reads it to refuse
an unsafe version removal); and CI-triggered deploys. This is a manual-trigger
console app replacing a manual-trigger CLI.

Two more that the adoption path invites and does not do. It never **writes** to the
tree to reconcile it — an import that noticed drift will tell you, and moving the
tree is still a deployment you review. And it does not scan on a schedule: a scan is
a deliberate act with a five-minute cache behind it, not a background job quietly
repinning the registry while nobody is looking. `mwdeploy:import-tree` in cron is
yours to decide on, and `--repin` is why you should think about it first.
