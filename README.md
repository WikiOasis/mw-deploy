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
| Pick a branch **or commit** per repo | no — always `fetch && reset --hard FETCH_HEAD` | yes (`deployment_repo_refs`) |
| Deploy history | last run only, in `/var/log/mwdeploy-state.json` | every run, queryable |
| Rollback | no concept of it | yes, automatic and manual (`repo_state_snapshots`) |
| Users and permissions | whoever is on the Salt master shell | accounts, roles, 13 permissions, enforced TOTP |
| Patch consistency | `--patch`/`--patch-target` retyped each time | registry; target path stored on the patch |
| Repo/version registration | by hand on disk | form + `repo-register` on staging, with a reachability check |
| Blocking canary prompt | curses `Prompter.input()` | web modal the job polls for |

## Layout

```
app/
  Actions/Deployments/     CreateDeployment, RollbackDeployment
  Actions/Repositories/    RegisterRepository
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
socket permissions — is in [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

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
php artisan test                                       # 111 tests
python3 -m unittest discover -s shim/tests -t .        # 44 tests
vendor/bin/pint --test                                 # style
```

Nothing in either suite shells out to a real `salt`: `FakeSaltClient` records
every call and answers from a queue of canned results, so the orchestration is
tested against exact call sequences (see
`tests/Feature/RunDeploymentTest::a_happy_path_deployment_runs_the_whole_section_5_sequence`).

## How a deployment runs

1. Read every repo's current `HEAD` on staging → `repo_state_snapshots`. This
   happens **before** anything mutates staging, so the undo point is real.
2. `git-checkout` each selected ref on staging.
3. Apply selected patches.
4. `rsync-local` staging → production on the staging host, restricted to the
   paths the deployment touches (a core version bump syncs the whole tree).
5. Optional `l10n-rebuild` on staging.
6. Canary on staging. This gates the fleet rollout.
7. Per appserver, up to `--parallel N` at once: depool from every proxy →
   `rsync-remote` → optional `l10n-rebuild` → canary → repool.
8. Aggregate the per-server results.

A canary failure without `--force` parks that one pipeline on a blocking prompt
(Continue / Abort only / Abort and roll back) while the other pipelines keep
moving. An unanswered prompt applies `MWDEPLOY_DECISION_TIMEOUT_DEFAULT` rather
than leaving the fleet parked mid-rollout. Aborting still repools every server it
depooled.

## Rollback

A rollback is just another deployment: `RollbackDeployment` creates one whose
`deployment_repo_refs` come from the failed deployment's
`repo_state_snapshots.previous_ref_value`, then dispatches the same
`RunDeployment` job. There is no second code path.

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
schema migrations or R2 content (rollback is git refs only — if a deploy included
a migration, a human still has to deal with the schema); and CI-triggered
deploys. This is a manual-trigger portal replacing a manual-trigger CLI.
