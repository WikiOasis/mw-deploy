# Operating WikiOasis Console

Everything here is about the hosts, not the code. For architecture see the
[README](../README.md); for the decisions behind these defaults see
[OPEN-QUESTIONS.md](OPEN-QUESTIONS.md).

## Hosts and what runs where

| Host | Role | Needs |
|---|---|---|
| Salt master (`salt-us-east-021`) | the portal | php-fpm, nginx, MySQL, queue worker, Reverb, `salt` CLI |
| staging | preparation | `mwdeploy-shim`, git, rsync **daemon**, php (l10n), the staging + production trees |
| `mw-*` appservers | rollout | `mwdeploy-shim`, rsync, php, curl |
| `proxy-*` | pooling | `mwdeploy-shim`, read/write on the HAProxy stats socket |

The portal never connects to any of these directly. Every arrow is
`salt '<minion>' cmd.run_all '<mwdeploy-shim …>'` from the master.

## Installing the shim

On every minion, including staging and the proxies:

```bash
install -m 0755 shim/mwdeploy_shim.py /usr/local/bin/mwdeploy-shim
mwdeploy-shim --version
```

It is a single file with no dependencies beyond the Python 3.9+ standard library.
Ship it with the same Salt state that manages the rest of the box.

### Sudo rules

The shim runs git, rsync and patch as the web user so files land owned correctly,
and re-asserts ownership afterwards (carried over from the original tool's
`chown www-data` fix-up). It shells to `sudo -n -u www-data …`, so the account
Salt's minion runs as needs passwordless sudo for those:

```sudoers
# /etc/sudoers.d/mwdeploy
Defaults!/usr/local/bin/mwdeploy-shim !requiretty
root ALL=(www-data) NOPASSWD: /usr/bin/git, /usr/bin/rsync, /usr/bin/patch, /usr/bin/php, /bin/mkdir
root ALL=(root)     NOPASSWD: /bin/chown
```

If the minion already runs as `www-data`, the shim skips the wrapper entirely
(`_current_user()`), and no sudo rules are needed.

Set `MWDEPLOY_WEB_USER` in the minion environment if the tree is not owned by
`www-data`.

## Getting bits from staging to the appservers

The appservers **pull**. Export the staged tree from the staging host:

```ini
# /etc/rsyncd.conf on staging
uid = www-data
gid = www-data
use chroot = yes
max connections = 16
read only = yes

[mediawiki]
    path = /srv/mediawiki
    comment = Staged MediaWiki tree for appserver pulls
    # Restrict to the appserver subnet; this module is the deploy path for the
    # whole farm and must not be world-readable.
    hosts allow = 10.0.0.0/8
    hosts deny = *
```

Then point the portal at it:

```dotenv
MWDEPLOY_RSYNC_SOURCE=rsync://staging.wikioasis.org/mediawiki/
```

Note the module exports `/srv/mediawiki` (production on staging), not
`/srv/mediawiki-staging`. The pipeline is: git checkout into the staging tree →
`rsync-local` staging→production **on the staging host** → appservers pull that
production tree. The staging host's own production copy is the canonical artefact.

Using NFS instead is a config change only:

```dotenv
MWDEPLOY_RSYNC_SOURCE=/mnt/mediawiki-staging/
```

## HAProxy stats socket

Depool/repool sends `set server <backend>/<server> state maint|ready` to the
socket. The minion's user needs read/write on it:

```
# /etc/haproxy/haproxy.cfg
global
    stats socket /run/haproxy/admin.sock mode 660 level admin
    stats timeout 30s
```

Override the path per minion with `MWDEPLOY_HAPROXY_SOCKET` if it differs.

HAProxy answers an accepted command with an empty line. Anything resembling a
complaint (`No such server`, `Unknown`, `Permission denied`) is treated as a
failure — silently accepting "No such server" would leave a live box being rsynced
while it takes traffic.

## Portal services

Three units. All three matter; the app renders without the worker but nothing
deploys.

```ini
# /etc/systemd/system/mwdeploy-worker.service
[Unit]
Description=WikiOasis Console queue worker
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/srv/deploy-portal
# --timeout=0 is required, not a preference: a deploy job blocks for as long as
# its rsyncs and l10n rebuilds take, and a worker timeout mid-rollout would leave
# the fleet half-updated.
# --tries=1 because a deployment is not safely retryable from the top.
# --timeout=0 only stops this *process* from killing the job. The queue
# connection's own retry_after (config/queue.php, default 90s) separately
# assumes an unfinished job died and redelivers it — which then fails outright
# with "has been attempted too many times or run too long" since tries=1.
# DB_QUEUE_RETRY_AFTER (or REDIS_QUEUE_RETRY_AFTER, if that connection is used)
# must be set well past the longest deployment, not just this flag.
ExecStart=/usr/bin/php artisan queue:work --queue=default --tries=1 --timeout=0 --sleep=1
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```ini
# /etc/systemd/system/mwdeploy-reverb.service
[Unit]
Description=WikiOasis Console websocket server
After=network.target

[Service]
User=www-data
WorkingDirectory=/srv/deploy-portal
ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=8080
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Put nginx in front of both: the app on `/`, and a websocket proxy on `/app` and
`/apps` to `127.0.0.1:8080` with `Upgrade`/`Connection` headers passed through.
A full vhost, and the HAProxy backend in front of it, are in
[SALT-INTEGRATION.md](SALT-INTEGRATION.md) sections 7 and 8.

Reverb's env vars split three ways and are not interchangeable:

| Variable | Meaning |
|---|---|
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | where the Reverb process **listens** — keep it on the loopback |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | the **public** URL the browser opens a websocket to |
| `REVERB_INTERNAL_*` | where **this app** reaches Reverb, server-side |

The last group exists because Laravel's default wires the server-side leg to
`REVERB_HOST` too, which behind a proxy means dialling out through the edge to
reach a service on this host's own loopback.

`VITE_REVERB_*` is compiled into the JS bundle by `npm run build`, so changing the
public URL needs a rebuild — a `config:clear` will not do it, and the symptom is a
dashboard that quietly falls back to polling.

Reverb is optional. With `BROADCAST_CONNECTION=log` the live dashboard falls back
to polling `/deployments/{id}/state` every 3 seconds — degraded, but a deploy is
exactly the wrong time to be blocked on a websocket server, so the fallback is
always present (`resources/js/deployment-monitor.js`).

### Only ever run one worker

`RunDeployment` is `ShouldBeUnique` on a fleet-wide key, so a second worker cannot
run a second deployment concurrently — but the lock lives in the cache store, so
`CACHE_STORE` must be shared (`database` or `redis`, never `array`/`file` across
hosts).

## Disk, and the cost of a core version

Each MediaWiki version is a complete tree — core plus its own checkout of every
extension and skin — under `versions/<ver>/`. Cutting a new version therefore
roughly doubles both `/srv/mediawiki-staging` and `/srv/mediawiki`, on the staging
host **and** on every appserver.

There is no pre-flight disk check in the portal. Before anyone uses the "cut a
version" form:

```bash
salt 'staging' cmd.run 'du -sh /srv/mediawiki-staging /srv/mediawiki; df -h /srv'
salt 'mw-*'    cmd.run 'df -h /srv'
```

Removing an old version reclaims it in one `rm -rf versions/<ver>` per host. The
portal does not check whether any wiki still points at the version first — confirm
that separately before undeploying.

## Patch files

Uploads land on the `patches` disk. The shim reads them from
`config('mwdeploy.paths.patches')` **on the staging host**, so in production the
disk root should be that directory:

```dotenv
MWDEPLOY_PATCH_STORAGE=/srv/mediawiki/scripts/extensions/patches
MWDEPLOY_PATCH_PATH=/srv/mediawiki/scripts/extensions/patches
```

If the portal is not on the staging host, keep the two in sync — an NFS mount, or a
Salt state that ships the directory. The portal only ever passes the **basename**
to the shim, which is why stored filenames get a random suffix
(`PatchController::storeFile`).

## First run checklist

```bash
php artisan migrate --force
php artisan db:seed --force                       # roles + permissions, idempotent
php artisan mwdeploy:create-user you@wikioasis.org --role=admin
```

If the farm already has MediaWiki on disk — which it does, unless this is a brand
new farm — fill the registry in from the tree rather than by hand. This needs the
shim installed on the staging host and nothing else; it is read-only:

```bash
php artisan mwdeploy:import-tree                  # what it found, and what it would do
php artisan mwdeploy:import-tree --apply --as=you@wikioasis.org
```

Read the dry run before applying it. What it prints is the diff between the tree and
the registry, and the two rows worth pausing on are:

- **Cannot import** — a directory with no git remote. The portal leaves these alone
  because it could never update or restore them; if one of them matters, give it a
  remote (or keep managing it by hand, knowingly).
- **Update pin** — the tree is on a different ref than the registry pins. Not applied
  unless you pass `--repin`, because the pin may be the deliberate answer and the
  tree the thing that is behind.

`--apply` records every checkout it adopts as *already deployed*, pinned to the ref
it is actually on, and writes nothing to the tree. The first deployment of each one
is therefore a normal ref change, not a clone over a live directory.

Then in the UI, in this order:

1. **Targets** — add staging, every appserver, every proxy. Hostnames must equal
   Salt minion ids; verify against `salt-key -L`.
2. **Import** — if you did not run the command above, do it here: **Repositories →
   Import from disk**. The scan and the plan are the same ones; the screen just lets
   you tick rows. Re-run it any time; it is the only way to see drift between the
   registry and the tree.
3. **Repositories** — for anything the tree does *not* have yet. Register the core
   version first (so `versions/<ver>/` exists), then extensions and skins. Each save
   clones onto staging, so a bad remote fails here rather than during a deploy.
4. **Config repository** — **Repositories → Config repository**, paste the git URL.
   If `config/` is already checked out it is adopted rather than cloned over.
5. **Patches** — re-register anything currently living in
   `scripts/extensions/patches/`, then hit **Dry run** on each. Patches that only
   applied because of GNU patch's default fuzz factor will fail now, which is the
   point.
6. **Users and access** (on `/access`, outside the apps) — create accounts and
   assign roles. A role only puts the Deployments tile on someone's launcher if it
   holds `apps.deployments.access` or one of the app's own permissions. Anyone with
   a deploy permission is redirected to `/two-factor/setup` until they enrol TOTP.
7. A **staging-only** deployment of one small extension, as a smoke test, before
   anything targets production.

## The web user's home directory

The `salt` CLI writes its own log file under `~/.salt` and **creates that directory
while parsing its arguments**. php-fpm does not set `HOME`, so the value comes from
the passwd entry — `/var/www` for www-data, which is usually root-owned. Run as that
user, salt dies like this before contacting any minion:

```
salt: error: Error while processing <function LogLevelMixIn.__setup_logfile_logger_config …>
PermissionError: [Errno 13] Permission denied: '/var/www/.salt'
```

Exit 64, no minion involved, and it reads exactly like a broken fleet. Anything the
portal runs synchronously from a web request hits it first: the import scan, the
remote reachability check when registering a repository, a patch dry run, a manual
depool. Queued deployments may keep working, because the worker runs under a
different unit with its own environment — which makes the failure look stranger than
it is.

The portal defends itself: it hands the subprocess `HOME=storage/framework/salt`
(overridable with `MWDEPLOY_SALT_HOME`), which the web user owns by definition. If
you would rather fix it at the host level, either is fine:

```bash
# Give the web user a home it owns…
install -d -o www-data -g www-data -m 0750 /var/lib/mwdeploy
# …and tell php-fpm and the units about it
#   php-fpm pool:  env[HOME] = /var/lib/mwdeploy
#   systemd unit:  Environment=HOME=/var/lib/mwdeploy
# then set MWDEPLOY_SALT_HOME= (empty) to inherit it.

# …or just let salt have its directory where it already looks
install -d -o www-data -g www-data -m 0750 /var/www/.salt
```

Whatever you choose, `storage/` must stay writable by the web user — it already has
to be, for the framework's caches and the queue.

## Verifying the transport before trusting it

```bash
# 1. Can the master reach the minion at all?
salt 'staging' test.ping

# 2. Is the shim installed and are the sudo rules right?
salt 'staging' cmd.run_all 'mwdeploy-shim git-head --path /srv/mediawiki-staging/config'

# 3. Can an appserver pull from the staging rsync module?
salt 'mw-us-east-011' cmd.run_all 'rsync --list-only rsync://staging.wikioasis.org/mediawiki/'

# 4. Can a proxy talk to its stats socket?
salt 'proxy-1' cmd.run_all 'mwdeploy-shim haproxy repool --proxy proxy-1 --backend mediawiki --server mw-us-east-011'

# 5. Can the tree be inventoried? (This is what the import screen runs.)
salt 'staging' cmd.run_all 'mwdeploy-shim tree-scan --root /srv/mediawiki-staging --no-metadata'
#   → reports every version, extension and skin it can see. Read-only.

# 6. Do the removal guards hold? A dry run deletes nothing.
salt 'staging' cmd.run_all 'mwdeploy-shim repo-remove --path /srv --root /srv/mediawiki'
#   → must fail with "outside the deploy root"
salt 'staging' cmd.run_all 'mwdeploy-shim repo-remove --path /srv/mediawiki/versions/1.45 --root /srv/mediawiki'
#   → must fail with "without --allow-version-root"
```

Each should print a single JSON object with `"ok": true`. Anything else is worth
fixing before the first real deploy — the portal will report the same failure, just
with more ceremony around it.

## When something goes wrong mid-deploy

- **A server is stuck depooled.** Use the Depool/Repool buttons on the deployment
  dashboard, or `salt '<proxy>' cmd.run_all 'mwdeploy-shim haproxy repool …'`. The
  pipeline repools on the abort path too, so this should be rare.
- **The prompt is waiting and nobody can answer.** It resolves itself after
  `MWDEPLOY_DECISION_TIMEOUT` (default 900s) by applying
  `MWDEPLOY_DECISION_TIMEOUT_DEFAULT` (default: abort and roll back).
- **The worker died mid-deployment.** The deployment stays `running` with no
  progress. Its steps are already in the database, so the log survives; mark it
  failed and roll back from history. `ShouldBeUnique`'s lock expires after 6 hours,
  or clear it with `php artisan cache:forget` on the unique key.
- **A rollback failed its own canary.** By design nothing further is automatic.
  The deployment's failure reason says "Manual intervention required" — the
  previous ref is in `repo_state_snapshots` for the original deployment if you need
  to drive it by hand.
