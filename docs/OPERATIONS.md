# Operating the deploy portal

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
Description=WikiOasis deploy portal queue worker
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/srv/deploy-portal
# --timeout=0 is required, not a preference: a deploy job blocks for as long as
# its rsyncs and l10n rebuilds take, and a worker timeout mid-rollout would leave
# the fleet half-updated.
# --tries=1 because a deployment is not safely retryable from the top.
ExecStart=/usr/bin/php artisan queue:work --queue=default --tries=1 --timeout=0 --sleep=1
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```ini
# /etc/systemd/system/mwdeploy-reverb.service
[Unit]
Description=WikiOasis deploy portal websocket server
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

Removing an old version reclaims it in one `rm -rf versions/<ver>` per host, but the
portal refuses that while any wiki still points at the version.

## The wiki → version map

Undeploying a core version reads the farm's own wiki → version mapping and refuses
if any wiki still uses that version:

```dotenv
MWDEPLOY_WIKIVERSIONS_PATH=/srv/mediawiki/config/wikiversions.json
MWDEPLOY_REQUIRE_WIKIVERSION_CHECK=true
```

The file is read **on the staging host**, through the shim, and must be a JSON object
mapping wiki to version. All three of these are accepted:

```json
{"metawiki": "1.45"}
{"metawiki": "php-1.45"}
{"metawiki": {"version": "1.45"}}
```

Anything else is reported as unparseable and the removal is refused — failing closed,
because guessing here means deleting the version every wiki is running on. Verify it
before you need it:

```bash
salt 'staging' cmd.run_all 'mwdeploy-shim wiki-versions --file /srv/mediawiki/config/wikiversions.json'
```

`MWDEPLOY_REQUIRE_WIKIVERSION_CHECK=false` turns the check off for a farm whose map
is genuinely unreachable. The versions page displays a standing warning while it is
off, and it should stay on otherwise: it is the only thing between a typo and
deleting a live version.

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

Then in the UI, in this order:

1. **Targets** — add staging, every appserver, every proxy. Hostnames must equal
   Salt minion ids; verify against `salt-key -L`.
2. **Repositories** — register the current core version first (so
   `versions/<ver>/` exists), then extensions and skins, then config. Each save
   clones onto staging, so a bad remote fails here rather than during a deploy.
3. **Patches** — re-register anything currently living in
   `scripts/extensions/patches/`, then hit **Dry run** on each. Patches that only
   applied because of GNU patch's default fuzz factor will fail now, which is the
   point.
4. **Users** — create accounts and assign roles. Anyone with a deploy permission
   is redirected to `/two-factor/setup` until they enrol TOTP.
5. A **staging-only** deployment of one small extension, as a smoke test, before
   anything targets production.

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

# 5. Can the wiki → version map be read? (Blocks version removal if not.)
salt 'staging' cmd.run_all 'mwdeploy-shim wiki-versions --file /srv/mediawiki/config/wikiversions.json'

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
