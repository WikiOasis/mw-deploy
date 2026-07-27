# Integrating the deploy portal into the SaltStack repository

**Audience:** an agent (or engineer) with write access to the WikiOasis SaltStack
repository and knowledge of its layout. You are adding Salt states that deploy and
manage this portal on the Salt master, distribute `mwdeploy-shim` to the minions,
and front the portal through nginx and the existing HAProxy edge.

**Read first:** [`README.md`](../README.md) for what the app is,
[`OPERATIONS.md`](OPERATIONS.md) for the runtime requirements this document
automates, and [`OPEN-QUESTIONS.md`](OPEN-QUESTIONS.md) for the decisions that are
still defaults rather than choices.

This document does **not** know your repo's layout, naming conventions, pillar
structure or secrets backend. Wherever it says "match existing conventions", do
that rather than following the example literally. Every concrete hostname, domain
and password below is a placeholder.

---

## 0. Before you write anything

### Facts to gather

You cannot complete this correctly without these. Get them from the repo or from a
human; do not guess.

| # | What | Where to look | Used for |
|---|---|---|---|
| 1 | Salt master's minion id and FQDN | `salt-key -L`, existing pillar | which minion the portal state targets |
| 2 | Exact minion ids of every mw appserver | `salt-key -L` | seeding `deploy_targets` |
| 3 | Exact minion ids of every HAProxy host | `salt-key -L` | seeding `deploy_targets` |
| 4 | The staging host's minion id | existing mwdeploy config | `MWDEPLOY_STAGING_TARGET` |
| 5 | HAProxy backend name the appservers sit in | `haproxy.cfg` in the repo | `MWDEPLOY_HAPROXY_BACKEND` |
| 6 | The `server` labels HAProxy knows each appserver by | `haproxy.cfg` | `deploy_targets.haproxy_server_name` — these often differ from minion ids |
| 7 | HAProxy stats socket path and mode | `haproxy.cfg` | shim depool/repool |
| 8 | How secrets are stored (GPG pillar? Vault? sops?) | existing pillar tree | `APP_KEY`, DB password, `REVERB_APP_SECRET` |
| 9 | Whether nginx or Apache fronts PHP on the master already | existing states | reuse vs. add |
| 10 | The domain to serve the portal on | a human | everything TLS-related |
| 11 | Whether the farm already has an NFS export of the MediaWiki tree | a human / mount states | decides section 5 |
| 12 | Path and format of the **wiki → core version map** | mw-config / `wikiversions.json` | `MWDEPLOY_WIKIVERSIONS_PATH`; blocks version removal until right |
| 13 | Which core versions exist right now, and each one's release branch | the staging tree / existing mwdeploy config | seeding versions and their pins |

### Decisions that need a human, not you

Flag these and proceed with the stated default:

- **Domain.** This document assumes `deploy.wikioasis.org`. Confirm it, and confirm
  whether the existing wildcard cert covers it.
- **Staging → appserver transport.** Default is an rsync daemon on the staging
  host (section 5). If an NFS export already exists, use it instead — it is one
  env var, no code change.
- **How `www-data` is allowed to run `salt`.** Section 4. This is the security
  decision in the whole integration; do not paper over it.
- **SSO.** The portal currently has its own password store. See
  [`OPEN-QUESTIONS.md#3`](OPEN-QUESTIONS.md). If staff SSO exists, that work should
  land before you create real accounts.
- **Disk headroom for a second core version.** Each version is a full MediaWiki
  tree plus its own copy of every extension and skin. Cutting 1.46 alongside 1.45
  roughly doubles `/srv/mediawiki` and `/srv/mediawiki-staging`, on the staging host
  *and* every appserver. Confirm there is room before anyone uses the "cut a
  version" form, and consider a disk-space check in the state.

---

## 1. Shape of the work

Five states, in dependency order. Sections below map 1:1 onto these.

| State | Target | What it does |
|---|---|---|
| `mwdeploy_shim` | `*` (all minions) | ships `/usr/local/bin/mwdeploy-shim` + sudoers |
| `mwdeploy_portal` | Salt master | clones the app, builds it, `.env`, MySQL, systemd units |
| `mwdeploy_portal.nginx` | Salt master | vhost, php-fpm pool, websocket proxy locations |
| `mwdeploy_staging` | staging host | rsync daemon exporting the staged tree |
| `haproxy` (existing) | proxies | new frontend ACL + `deploy_portal` backend; stats socket mode |

Order matters: the shim must exist on the staging host before the portal's first
repository registration, and the rsync daemon must exist before the first rollout.

---

## 2. Ship `mwdeploy-shim` to every minion

The shim is a single Python file, standard library only, Python 3.9+. It goes on
**every** minion the portal will touch: the staging host, every appserver, and
every proxy.

Source it from this repo rather than vendoring a copy — a divergent shim is a
debugging nightmare. Either add the repo as a `gitfs` remote, or have the portal
state place the file into the master's `file_roots` and let minions pull from
`salt://`.

```yaml
# salt/mwdeploy/shim.sls   (adapt paths to your file_roots layout)

mwdeploy-shim:
  file.managed:
    - name: /usr/local/bin/mwdeploy-shim
    - source: salt://mwdeploy/files/mwdeploy_shim.py
    - mode: '0755'
    - user: root
    - group: root

# The shim shells out to these; a missing one shows up as a step failure at
# deploy time rather than at highstate time, so require them here.
mwdeploy-shim-deps:
  pkg.installed:
    - pkgs:
      - git
      - rsync
      - patch
      - curl
      - python3
```

Then apply to all minions in your top file, or a nodegroup covering
staging + appservers + proxies.

**Verify:**

```bash
salt '*' cmd.run 'mwdeploy-shim --version'
```

Every minion should print `mwdeploy-shim 2.1.0`. The version matters: `tree-scan`,
which is what the portal reads an existing farm with, arrived in 2.1.0, and a minion
still on 2.0.0 will fail the import screen with a usage error rather than something
self-explanatory.

### Sudoers

The shim runs git, rsync, patch and php **as the web user** so files land owned
correctly, and re-asserts ownership afterwards — behaviour carried over from the
original `mwdeploy`. If the Salt minion runs as root (the usual case), it needs
passwordless sudo to reach `www-data`:

```yaml
mwdeploy-sudoers:
  file.managed:
    - name: /etc/sudoers.d/mwdeploy
    - mode: '0440'
    - user: root
    - group: root
    # Refuse to install a syntactically broken sudoers file — a bad one locks
    # everyone out of sudo on the box.
    - check_cmd: /usr/sbin/visudo -c -f
    - contents: |
        Defaults!/usr/local/bin/mwdeploy-shim !requiretty
        root ALL=(www-data) NOPASSWD: /usr/bin/git, /usr/bin/rsync, /usr/bin/patch, /usr/bin/php, /bin/mkdir
        root ALL=(root)     NOPASSWD: /bin/chown
```

If the tree is not owned by `www-data`, set `MWDEPLOY_WEB_USER` in the minion
environment (`/etc/default/salt-minion` or the minion config's `environment`) and
adjust the sudoers `(user)` accordingly.

If a minion already runs as `www-data`, the shim detects that and skips the sudo
wrapper entirely — no sudoers file needed there.

---

## 3. Deploy the portal onto the Salt master

### 3.1 Packages

```yaml
mwdeploy-portal-packages:
  pkg.installed:
    - pkgs:
      - php8.4-fpm
      - php8.4-cli
      - php8.4-mysql
      - php8.4-mbstring
      - php8.4-xml
      - php8.4-curl
      - php8.4-intl
      - php8.4-zip
      - php8.4-bcmath
      - nginx
      - mariadb-server        # or point at an existing DB host
      - nodejs
      - npm
      - git
      - composer
```

PHP **8.3 or newer** is required (`composer.json` says `^8.3`; developed on 8.4).

### 3.2 Clone and build

```yaml
mwdeploy-portal-clone:
  git.latest:
    - name: https://github.com/WikiOasis/mw-deploy.git
    - target: /srv/deploy-portal
    - rev: main                     # pin to a tag once you cut releases
    - user: www-data
    - force_reset: True             # the tree is a deployment artefact, not a workspace
    - require:
      - pkg: mwdeploy-portal-packages

mwdeploy-portal-composer:
  cmd.run:
    - name: composer install --no-dev --optimize-autoloader --no-interaction
    - cwd: /srv/deploy-portal
    - runas: www-data
    - env:
      - COMPOSER_HOME: /srv/deploy-portal/.composer
    - onchanges:
      - git: mwdeploy-portal-clone

# public/build is gitignored — the bundle is built here, and it bakes in the
# VITE_REVERB_* values, so it must run *after* .env exists.
mwdeploy-portal-assets:
  cmd.run:
    - name: npm ci && npm run build
    - cwd: /srv/deploy-portal
    - runas: www-data
    - onchanges:
      - git: mwdeploy-portal-clone
    - require:
      - file: mwdeploy-portal-env
```

**The asset build ordering is not incidental.** `VITE_REVERB_APP_KEY`,
`VITE_REVERB_HOST`, `VITE_REVERB_PORT` and `VITE_REVERB_SCHEME` are compiled into
the JS bundle. Changing them in `.env` without re-running `npm run build` leaves
the browser connecting to the old websocket URL, and the live deployment view
silently falls back to polling. Make `mwdeploy-portal-assets` watch the `.env` file.

**The asset build is also not optional.** The interface is a Vue single-page app:
without `public/build/manifest.json` every page returns a 500 from the `@vite`
directive rather than degrading. If `mwdeploy-portal-assets` fails, the portal is
down — treat it like the composer install, not like a nice-to-have. It needs no
network beyond the npm registry at build time; nothing is fetched from a CDN at
runtime, which is deliberate on a host that may not be allowed out to the internet.

### 3.3 Directory ownership

```yaml
mwdeploy-portal-writable:
  file.directory:
    - names:
      - /srv/deploy-portal/storage
      - /srv/deploy-portal/bootstrap/cache
    - user: www-data
    - group: www-data
    - mode: '0775'
    - recurse: [user, group, mode]
```

### 3.4 Database

```yaml
mwdeploy-portal-db:
  mysql_database.present:
    - name: mwdeploy
    - character_set: utf8mb4
    - collate: utf8mb4_unicode_ci

mwdeploy-portal-db-user:
  mysql_user.present:
    - name: mwdeploy
    - host: localhost
    - password: {{ salt['pillar.get']('mwdeploy:db_password') }}

mwdeploy-portal-db-grants:
  mysql_grants.present:
    - grant: all privileges
    - database: mwdeploy.*
    - user: mwdeploy
    - host: localhost
```

The portal's tables are small — deploy history, step logs, a registry. Sizing is
not a concern; the largest column is `deployment_steps.log`.

### 3.5 `.env`

Template it. Never commit a rendered `.env` — it contains `APP_KEY`, the DB
password and `REVERB_APP_SECRET`.

```yaml
mwdeploy-portal-env:
  file.managed:
    - name: /srv/deploy-portal/.env
    - source: salt://mwdeploy/files/portal.env.jinja
    - template: jinja
    - user: www-data
    - group: www-data
    - mode: '0640'                  # www-data reads it; nobody else needs to
    - show_changes: False           # keep secrets out of highstate output
    - require:
      - git: mwdeploy-portal-clone
```

The full annotated template is [`.env.example`](../.env.example) — start from that
file rather than from the table below, and read its comments. What follows is only
the values you must **change** from the shipped defaults, and why.

#### Framework block

| Variable | Value | Notes |
|---|---|---|
| `APP_NAME` | `"WikiOasis Deploy Portal"` | shown in the browser tab |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | **critical** — a debug stack trace on this app leaks `.env`, including the DB password |
| `APP_URL` | `https://deploy.wikioasis.org` | must match the real scheme+host or generated links break behind the proxy |
| `APP_KEY` | from pillar | `php artisan key:generate --show` once, then store it. Rotating it invalidates every session and every encrypted 2FA secret |
| `LOG_CHANNEL` / `LOG_STACK` | `stack` / `daily` | |
| `LOG_LEVEL` | `info` | `debug` logs every Salt argv; useful while bringing this up, noisy afterwards |

#### Database, queue, cache, session

| Variable | Value | Notes |
|---|---|---|
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` | `127.0.0.1` / `3306` / `mwdeploy` / `mwdeploy` | |
| `DB_PASSWORD` | from pillar | same value as the `mysql_user` state |
| `QUEUE_CONNECTION` | `database` | Redis is fine too; database avoids another dependency for a queue that holds one job at a time |
| `CACHE_STORE` | `database` | **must be shared, not `file`/`array`** — the "only one deployment at a time" lock lives here |
| `SESSION_DRIVER` | `database` | |
| `BROADCAST_CONNECTION` | `reverb` | set to `log` to bring the app up before Reverb exists; the dashboard falls back to polling |

#### Reverb — the part that is easy to get wrong

Four groups of variables, and they are **not** interchangeable:

| Variable | Value | Which leg |
|---|---|---|
| `REVERB_APP_ID` | `mwdeploy` | identifier, any string |
| `REVERB_APP_KEY` | from pillar (`openssl rand -hex 24`) | shared; **public** — it ends up in the JS bundle |
| `REVERB_APP_SECRET` | from pillar (`openssl rand -hex 24`) | shared; **secret** — app ↔ Reverb only |
| `REVERB_SERVER_HOST` | `127.0.0.1` | where the Reverb process **listens** |
| `REVERB_SERVER_PORT` | `8080` | where the Reverb process **listens** |
| `REVERB_HOST` | `deploy.wikioasis.org` | the **public** host, for the browser |
| `REVERB_PORT` | `443` | the **public** port |
| `REVERB_SCHEME` | `https` | the **public** scheme |
| `REVERB_INTERNAL_HOST` | `127.0.0.1` | where **this app** reaches Reverb |
| `REVERB_INTERNAL_PORT` | `8080` | where **this app** reaches Reverb |
| `REVERB_INTERNAL_SCHEME` | `http` | Reverb speaks plain HTTP; nginx adds TLS |
| `VITE_REVERB_*` | interpolated from `REVERB_HOST`/`PORT`/`SCHEME` | compiled into the bundle at build time |

Bind Reverb to `127.0.0.1`, not `0.0.0.0`. Reverb has no TLS and no
authentication of its own beyond the app key/secret; anything that can reach the
port can attempt to subscribe. nginx is the only thing that should talk to it.

The `REVERB_INTERNAL_*` split exists because Laravel's default wires the
server-side leg to `REVERB_HOST` too, which behind a proxy means the app dials out
through HAProxy to reach a service on its own loopback. See the comment in
`config/broadcasting.php`.

#### Portal block — the fleet-specific values

These are the ones only you can fill in, from the facts gathered in section 0:

| Variable | Value | Source |
|---|---|---|
| `MWDEPLOY_SALT_BINARY` | `/usr/bin/salt` | or your sudo wrapper — see section 4 |
| `MWDEPLOY_SALT_MODULE` | `cmd.run_all` | leave it; see OPEN-QUESTIONS |
| `MWDEPLOY_SALT_TIMEOUT` | `300` | per-step overrides live in `config/mwdeploy.php` |
| `MWDEPLOY_SHIM_BINARY` | `/usr/local/bin/mwdeploy-shim` | must match section 2 |
| `MWDEPLOY_STAGING_TARGET` | **fact 4** | the staging host's *minion id*, not its FQDN |
| `MWDEPLOY_STAGING_PATH` | `/srv/mediawiki-staging` | confirm against the existing tree |
| `MWDEPLOY_PROD_PATH` | `/srv/mediawiki` | confirm against the existing tree |
| `MWDEPLOY_PATCH_PATH` | `/srv/mediawiki/scripts/extensions/patches` | where the shim reads patches **on staging** |
| `MWDEPLOY_PATCH_STORAGE` | same as above, if the master is the staging host | where the portal *writes* uploads — see section 6 |
| `MWDEPLOY_RSYNC_SOURCE` | `rsync://<staging fqdn>/mediawiki/` | section 5 |
| `MWDEPLOY_HAPROXY_BACKEND` | **fact 5** | default backend for depool/repool |
| `MWDEPLOY_CANARY_VHOST` | e.g. `meta.wikioasis.org` | a wiki that returns 200 with `wikioasis` in the body |
| `MWDEPLOY_L10N_WIKI` | `testwiki` | |
| `MWDEPLOY_DEFAULT_PARALLEL` | `1` | start conservative |
| `MWDEPLOY_MAX_PARALLEL` | `8` | ceiling the UI will offer |
| `MWDEPLOY_GIT_DRIVER` | `salt` | `local` only if the master *is* the staging host |
| `MWDEPLOY_WIKIVERSIONS_PATH` | **fact 12** | read on the staging host to refuse removing a version wikis still use |
| `MWDEPLOY_CONFIG_DIR` | `config` | where mw-config is checked out, relative to the deploy root |
| `MWDEPLOY_SALT_HOME` | `storage/framework/salt` | HOME for the salt subprocess; the CLI creates `~/.salt` and dies if it cannot. Leave unset, or point it at a directory www-data owns |
| `MWDEPLOY_SCAN_ROOT` | `staging` | which tree the import screen inventories: `staging` or `production` |
| `MWDEPLOY_REQUIRE_WIKIVERSION_CHECK` | `true` | leave it on; see the warning below |
| `MWDEPLOY_DECISION_TIMEOUT` | `900` | how long a canary prompt waits for a human |
| `MWDEPLOY_DECISION_TIMEOUT_DEFAULT` | `abort_and_rollback` | what happens if nobody answers |

**On `MWDEPLOY_WIKIVERSIONS_PATH`:** undeploying a core version reads this file (via
the shim, on the staging host) and refuses if any wiki still points at that version.
It also refuses if the file cannot be read or is in a shape the shim does not
recognise — failing closed, because guessing means deleting the version everything
is running on. The shim accepts `{"wiki": "1.45"}`, `{"wiki": "php-1.45"}` and
`{"wiki": {"version": "1.45"}}`.

Get this path right. If it is wrong, version removal is *blocked* rather than
dangerous — which is the correct failure direction, but it will look like a bug.
`MWDEPLOY_REQUIRE_WIKIVERSION_CHECK=false` disables the check; do not set it unless
the map genuinely cannot be reached, and expect the versions page to display a
standing warning while it is off.

### 3.6 Migrate, seed, cache

```yaml
mwdeploy-portal-migrate:
  cmd.run:
    - name: php artisan migrate --force && php artisan db:seed --force
    - cwd: /srv/deploy-portal
    - runas: www-data
    - onchanges:
      - git: mwdeploy-portal-clone
    - require:
      - mysql_grants: mwdeploy-portal-db-grants
      - file: mwdeploy-portal-env

# Config and route caches must be rebuilt on every deploy, and cleared before
# migrating — a stale config cache pins the old DB credentials.
mwdeploy-portal-optimise:
  cmd.run:
    - name: php artisan optimize
    - cwd: /srv/deploy-portal
    - runas: www-data
    - onchanges:
      - git: mwdeploy-portal-clone
      - file: mwdeploy-portal-env
```

`db:seed` runs `RolesAndPermissionsSeeder` only — roles and the 13 permissions. It
is idempotent (`updateOrCreate`), so it is safe on every highstate, and re-running
it is how new permissions arrive after an upgrade. It creates **no users**.

### 3.7 systemd units

Two long-running services beyond php-fpm. Both are in
[`OPERATIONS.md`](OPERATIONS.md); ship them via `file.managed` +
`service.running` with `watch` on the unit file and on `.env`.

```yaml
mwdeploy-worker-unit:
  file.managed:
    - name: /etc/systemd/system/mwdeploy-worker.service
    - source: salt://mwdeploy/files/mwdeploy-worker.service

mwdeploy-worker:
  service.running:
    - name: mwdeploy-worker
    - enable: True
    - watch:
      - file: mwdeploy-worker-unit
      - file: mwdeploy-portal-env
      - git: mwdeploy-portal-clone

mwdeploy-reverb-unit:
  file.managed:
    - name: /etc/systemd/system/mwdeploy-reverb.service
    - source: salt://mwdeploy/files/mwdeploy-reverb.service

mwdeploy-reverb:
  service.running:
    - name: mwdeploy-reverb
    - enable: True
    - watch:
      - file: mwdeploy-reverb-unit
      - file: mwdeploy-portal-env
```

Three things about the worker unit that are load-bearing:

- **`--timeout=0`.** A deploy job blocks for as long as its rsyncs and l10n
  rebuilds take. A worker timeout mid-rollout kills the job with the fleet half
  updated and no rollback triggered.
- **`--tries=1`.** A deployment is not safely retryable from the top. A retry
  would re-run the whole pipeline against a staging tree that is already checked
  out to the new ref.
- **Exactly one worker.** Two workers cannot run two deployments concurrently
  (`RunDeployment` is `ShouldBeUnique`), but the lock lives in `CACHE_STORE`, so
  that only holds while the cache is shared. Do not template this unit into an
  instance unit with `%i`.

Restarting the worker mid-deployment orphans the running deployment: it stays
`running` in the database forever, because nothing else marks it failed. If your
repo has a "restart services on highstate" convention, exempt this one, or gate
the restart on there being no in-flight deployment.

---

## 4. Letting the portal run `salt` — the security decision

The portal runs as `www-data` and executes the `salt` CLI. Out of the box `salt`
requires root, because it needs the master's sockets under `/var/run/salt/master`.

**Understand what you are granting.** Whatever mechanism you pick, `www-data` on
the Salt master ends up able to run commands on minions. If the portal is
compromised, so is the fleet. Pick deliberately.

### Option A — `publisher_acl` (recommended)

Salt's own mechanism, and the only one that actually narrows the grant:

```yaml
# master config, e.g. /etc/salt/master.d/mwdeploy-acl.conf
publisher_acl:
  www-data:
    - cmd.run_all

# Required for a non-root user to read the master's PKI and cache directories.
permissive_pki_access: True
```

Tighten it per-target if your Salt version supports the nested form:

```yaml
publisher_acl:
  www-data:
    - 'mw-*':
      - cmd.run_all
    - 'proxy-*':
      - cmd.run_all
    - 'staging':
      - cmd.run_all
```

**Be honest about the limit:** `cmd.run_all` is arbitrary command execution on
those minions. The ACL bounds *which minions* and *which Salt function*, not what
runs inside it. The shim is the intended surface, not an enforced one.

To enforce the surface, write a **custom Salt execution module** that exposes only
the shim verbs (`mwdeploy.git_checkout`, `mwdeploy.rsync_remote`, …) and grant
`www-data` only `mwdeploy.*`. That is a real hardening step and a natural follow-up
— it would need a matching `SaltClient` in the portal, so raise it as an issue
rather than attempting it as part of this integration.

### Option B — sudo wrapper

Simpler to reason about, but grants strictly more:

```sudoers
www-data ALL=(root) NOPASSWD: /usr/bin/salt
```

```yaml
# then, in .env
MWDEPLOY_SALT_BINARY=/usr/local/bin/salt-portal
```

```bash
#!/bin/sh
# /usr/local/bin/salt-portal
exec sudo -n /usr/bin/salt "$@"
```

This is unrestricted remote execution on every minion. Only choose it if
`publisher_acl` proves impractical, and say so in the commit message.

### Verify, as `www-data`, before going further

```bash
sudo -u www-data /usr/bin/salt 'staging' test.ping
sudo -u www-data /usr/bin/salt --out=json --static 'staging' cmd.run_all 'mwdeploy-shim --version'
```

The second must return JSON with `retcode: 0`. If it fails as `www-data` but works
as root, the ACL or the socket permissions are wrong — fix that here, not by
falling back to Option B without comment.

---

## 5. rsync daemon on the staging host

The appservers **pull** the staged tree. This is the one behavioural inversion
from the old CLI, which pushed over SSH from the orchestrator — see
[`OPEN-QUESTIONS.md#1`](OPEN-QUESTIONS.md).

**Skip this section entirely if the farm already has an NFS export of the tree.**
Set `MWDEPLOY_RSYNC_SOURCE=/mnt/mediawiki-staging/` and move on; the shim treats
the value as an opaque rsync source.

```yaml
# salt/mwdeploy/staging.sls — applies to the staging host only

mwdeploy-rsyncd-conf:
  file.managed:
    - name: /etc/rsyncd.conf
    - contents: |
        uid = www-data
        gid = www-data
        use chroot = yes
        max connections = 16
        read only = yes
        log file = /var/log/rsyncd.log

        [mediawiki]
            path = /srv/mediawiki
            comment = Staged MediaWiki tree for appserver pulls
            # This module is the deploy path for the entire farm. Restrict it to
            # the appserver subnet; it must never be world-readable.
            hosts allow = 10.0.0.0/8
            hosts deny = *

mwdeploy-rsyncd:
  service.running:
    - name: rsync
    - enable: True
    - watch:
      - file: mwdeploy-rsyncd-conf
```

Two things worth being deliberate about:

**The module exports `/srv/mediawiki`, not `/srv/mediawiki-staging`.** The pipeline
is: git checkout into the *staging* tree → `rsync-local` staging→production **on
the staging host** → appservers pull that *production* tree. The staging host's own
production copy is the canonical artefact.

**`hosts allow` is the only access control here.** rsyncd in this configuration has
no authentication. If the appservers are not on a trusted subnet, use
`auth users` + `secrets file` and put the credentials in
`MWDEPLOY_RSYNC_SOURCE` — or use NFS.

**Verify from an appserver, not from the staging host:**

```bash
salt 'mw-us-east-011' cmd.run_all 'rsync --list-only rsync://<staging fqdn>/mediawiki/'
```

---

## 6. Patch file storage

Uploaded patches are written by the portal (on the master) and read by the shim
(on the staging host). Two env vars, and they must agree on content:

- `MWDEPLOY_PATCH_STORAGE` — where the portal writes.
- `MWDEPLOY_PATCH_PATH` — where the shim reads, on staging.

**If the master is also the staging host**, set both to
`/srv/mediawiki/scripts/extensions/patches` and you are done.

**If they are different hosts**, they must be kept in sync. Options, best first:

1. NFS-mount the patches directory from staging onto the master, and point
   `MWDEPLOY_PATCH_STORAGE` at the mount.
2. Keep the portal's storage local and add a Salt state that syncs
   `/srv/deploy-portal/storage/app/patches/` → the staging path. Fragile: a patch
   uploaded between highstates is not there when a deploy references it.

The portal passes only the **basename** to the shim, which is why stored filenames
get a random suffix — they must be unique within one flat directory.

---

## 7. nginx on the Salt master

```nginx
# /etc/nginx/sites-available/deploy-portal
server {
    listen 80;
    listen [::]:80;
    server_name deploy.wikioasis.org;

    root /srv/deploy-portal/public;
    index index.php;

    charset utf-8;
    client_max_body_size 4m;          # patch uploads are capped at 2 MB by the app

    # TLS is terminated at HAProxy, so trust its forwarded headers rather than
    # what the client claimed. Without this, Laravel generates http:// URLs and
    # the browser blocks them as mixed content.
    set_real_ip_from 10.0.0.0/8;      # your HAProxy hosts
    real_ip_header X-Forwarded-For;
    real_ip_recursive on;

    access_log /var/log/nginx/deploy-portal.access.log;
    error_log  /var/log/nginx/deploy-portal.error.log;

    # ---- Reverb: the browser's websocket ----
    # laravel-echo with the reverb broadcaster connects to /app/{key}.
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade    $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host       $host;
        proxy_set_header X-Real-IP  $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $http_x_forwarded_proto;

        # A deployment can sit idle for minutes between steps. The default 60s
        # read timeout would drop the socket mid-deploy and the dashboard would
        # silently degrade to polling.
        proxy_read_timeout  3600s;
        proxy_send_timeout  3600s;
        proxy_buffering off;
    }

    # ---- Reverb: the server-side HTTP API ----
    # Only reachable from the loopback in the recommended config (the app dials
    # REVERB_INTERNAL_HOST directly), but proxied here so a fallback to the
    # public host also works.
    location /apps {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        allow 127.0.0.1;
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm-deploy-portal.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT   $realpath_root;
        fastcgi_hide_header X-Powered-By;

        # The review screen and history can be slow with a long deploy history,
        # but nothing here should take minutes — the long work is in the queue.
        fastcgi_read_timeout 120s;
    }

    # Nothing outside public/ is web-reachable, but be explicit: .env sits one
    # directory up and a misconfiguration that exposed it would leak the DB
    # password and APP_KEY.
    location ~ /\.(?!well-known).* { deny all; }
}
```

Give it its own php-fpm pool rather than sharing `www.conf`, so a slow page here
cannot starve anything else on the master:

```ini
; /etc/php/8.4/fpm/pool.d/deploy-portal.conf
[deploy-portal]
user = www-data
group = www-data
listen = /run/php/php8.4-fpm-deploy-portal.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
php_admin_value[error_log] = /var/log/php8.4-fpm-deploy-portal.log
php_admin_flag[log_errors] = on
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 4M
php_admin_value[post_max_size] = 8M
```

Add `nginx` and `php8.4-fpm` `service.running` states with `watch` on both files,
and use `check_cmd: nginx -t` on the vhost so a bad config is rejected rather than
taking nginx down on reload.

### If the master already serves something on port 80

Do not fight over it. Either add this as another `server` block on the existing
nginx (fine — `server_name` disambiguates), or give the portal a distinct port
(`listen 8081;`) and point the HAProxy backend there. The latter is cleaner if the
master's existing web server is something other than nginx.

---

## 8. HAProxy: domain and websocket proxying

The portal is served through the same HAProxy edge as the wikis, on its own
backend.

### 8.1 The critical constraint

**The portal's backend must not be the backend the portal depools.**
`MWDEPLOY_HAPROXY_BACKEND` (fact 5) names the appserver backend. The portal's own
backend must be a separate one — otherwise a rollout will depool the box serving
the portal and you lose the dashboard mid-deploy.

Name it something that could never be confused with the appserver backend, and put
a comment in `haproxy.cfg` saying why.

### 8.2 Frontend

Add to the existing HTTPS frontend rather than creating a new one, so the portal
inherits the existing certificate and HSTS setup:

```haproxy
frontend https_in
    # ... existing config ...

    # Deploy portal. Deliberately NOT in the mediawiki backend: a rollout depools
    # servers from that backend, and depooling the box that serves this portal
    # would take the dashboard down mid-deploy.
    acl host_deploy hdr(host) -i deploy.wikioasis.org
    use_backend deploy_portal if host_deploy
```

If the certificate is a wildcard covering `*.wikioasis.org`, nothing more is
needed. If not, add the cert to the frontend's `crt` list and coordinate issuance
with whoever manages certs.

### 8.3 Backend

```haproxy
backend deploy_portal
    mode http
    balance first

    # X-Forwarded-* is how the portal knows it is behind TLS; nginx's
    # set_real_ip_from must list these proxies for it to be believed.
    option forwardfor
    http-request set-header X-Forwarded-Proto https
    http-request set-header X-Forwarded-Port 443

    # The live dashboard holds a websocket open for the length of a deployment,
    # which can be tens of minutes. Without a raised tunnel timeout HAProxy drops
    # it and the dashboard silently degrades to polling.
    timeout tunnel 1h
    timeout server 120s

    # Laravel's health endpoint, added by the framework.
    option httpchk GET /up
    http-check expect status 200

    server salt-us-east-021 10.x.x.x:80 check inter 10s fall 3 rise 2
```

`mode http` handles the `Upgrade` handshake natively — no special ACL is needed for
websockets, only the `timeout tunnel`. If your frontend has a low global
`timeout client`, raise it for this backend too, or the browser side of the socket
gets dropped instead of the server side.

### 8.4 Single point of failure

With one `server` line the portal has no redundancy, and HAProxy being down means
no dashboard. That is usually acceptable — the portal is an operator tool, not
user-facing — but be deliberate:

- Note in the runbook that `https://<master fqdn>` (or an SSH tunnel to
  `127.0.0.1:80`) reaches the portal directly if the edge is down.
- Do **not** add a second `server` line pointing at another host unless you have
  actually deployed the portal there; the DB and the queue lock are not shared by
  default.

### 8.5 Stats socket

The shim's depool/repool writes to the HAProxy stats socket on each proxy. Confirm
fact 7 and make sure the mode allows the minion's user:

```haproxy
global
    stats socket /run/haproxy/admin.sock mode 660 level admin
    stats timeout 30s
```

`level admin` is required — `set server ... state` is an admin-level command. If
the socket path differs from `/run/haproxy/admin.sock`, set
`MWDEPLOY_HAPROXY_SOCKET` in the **proxy minions'** environment, not in the
portal's `.env`.

---

## 9. Seeding the inventory

The portal's `deploy_targets` table is its view of the fleet, and `hostname` must
equal the Salt minion id **exactly** — it is passed verbatim as the Salt target.

There is no importer; add them through the Targets screen. Per target:

| Field | Value |
|---|---|
| `hostname` | the minion id from `salt-key -L` — **not** the FQDN unless they are the same |
| `role` | `appserver`, `proxy`, or `staging` |
| `haproxy_server_name` | appservers only, if HAProxy's `server` label differs from the minion id (fact 6) |
| `haproxy_backend` | proxies only, if it differs from `MWDEPLOY_HAPROXY_BACKEND` |
| `canary_vhost` | appservers only, if it differs from the global default |
| `sort_order` | rollout order, lowest first |

**If the salt repo is the source of truth for fleet membership** — and it probably
is — a small artisan command that reads a pillar and upserts `deploy_targets` would
be a genuine improvement over hand entry. It does not exist yet. Raise it as an
issue; do not hand-maintain the list in two places silently.

A mistyped hostname is not silent: the step fails with Salt's own
`Minion did not return. [No response]` in the log.

---

## 10. Bringing it up

In this order. Do not skip the staging-only smoke test.

```bash
# 1. Shim everywhere
salt '*' state.apply mwdeploy.shim
salt '*' cmd.run 'mwdeploy-shim --version'

# 2. rsync daemon on staging (unless using NFS)
salt 'staging' state.apply mwdeploy.staging

# 3. The portal
salt 'salt-us-east-021' state.apply mwdeploy.portal

# 4. Edge
salt 'proxy-*' state.apply haproxy
```

Then verify the transport as `www-data` before trusting the UI:

```bash
sudo -u www-data /usr/bin/salt --out=json --static 'staging' \
    cmd.run_all 'mwdeploy-shim git-head --path /srv/mediawiki-staging/config'

sudo -u www-data /usr/bin/salt --out=json --static 'mw-us-east-011' \
    cmd.run_all 'rsync --list-only rsync://<staging fqdn>/mediawiki/'

sudo -u www-data /usr/bin/salt --out=json --static 'proxy-1' \
    cmd.run_all 'mwdeploy-shim haproxy repool --proxy proxy-1 --backend mediawiki --server mw-us-east-011'
```

Each must print one JSON object with `"ok": true`. A failure here is a failure the
portal will hit too, just with more ceremony around it.

Create the first account:

```bash
cd /srv/deploy-portal
sudo -u www-data php artisan mwdeploy:create-user you@wikioasis.org --role=admin
```

Then in the UI, in order:

1. **Targets** — staging, every appserver, every proxy (section 9).
2. **Repositories** — the current core version first, so `versions/<ver>/` exists,
   then extensions, skins, config. Each save clones onto staging, so a bad remote
   fails here rather than mid-deploy.
4. **Patches** — re-register anything in `scripts/extensions/patches/`, pointing
   each at the specific *checkout* it applies to, then hit **Dry run** on each.
   Expect some failures: the shim uses `patch --fuzz=0`, so a patch that previously
   only applied because of GNU patch's default fuzz factor is now refused. That is
   the intended behaviour, not a regression.
5. **A staging-only deployment of one small extension.** Tick "staging only", pick
   one extension in one version, review the plan, run it. Confirm every step goes
   green and the live dashboard updates without a page refresh (proving the
   websocket path through HAProxy → nginx → Reverb works).
6. **A staging-only undeploy of that same extension, then roll it back.** This is
   the cheapest way to prove the removal guards and the restore path work before
   anyone needs them in anger. Check the review screen shows the literal
   `repo-remove --path … --root …` before you confirm.
7. Only then a real deployment, with `parallel = 1` and one server selected.

---

## 11. Acceptance checklist

Report against this rather than "done".

**Shim**
- [ ] `salt '*' cmd.run 'mwdeploy-shim --version'` returns on every minion
- [ ] `git-head` succeeds on staging **as `www-data`**
- [ ] `haproxy repool` succeeds against a real proxy socket

**Portal**
- [ ] `https://deploy.wikioasis.org` serves the login page through HAProxy
- [ ] `APP_DEBUG=false`, `.env` is mode 0640 and owned by `www-data`
- [ ] `/up` returns 200 and HAProxy's health check is passing
- [ ] `systemctl is-active mwdeploy-worker mwdeploy-reverb` → `active` for both
- [ ] the live dashboard updates without a page refresh — a **polling** fallback
      means the websocket path is broken, and the page will look fine
- [ ] `public/build` exists and was built *after* `.env` was written

**Fleet**
- [ ] an appserver can `rsync --list-only` the staging module
- [ ] `deploy_targets` hostnames match `salt-key -L` exactly
- [ ] `haproxy_server_name` set wherever HAProxy's label differs from the minion id
- [ ] the portal's HAProxy backend is **not** the backend a rollout depools

**End to end**
- [ ] a staging-only deployment of one extension goes green
- [ ] a one-server production deployment goes green, and the server is repooled
- [ ] the deployment appears in history with per-server logs and an undo point

**Flagged for a human**
- [ ] domain and certificate confirmed
- [ ] transport choice (rsyncd vs NFS) confirmed
- [ ] `salt`-as-`www-data` mechanism chosen, with the trade-off recorded
- [ ] SSO decision made before real accounts are created

---

## 12. Things that will bite you

Collected because each one presents as something other than its cause.

**The dashboard "works" but never updates live.** The websocket failed and the
polling fallback took over silently. Check `timeout tunnel` on the HAProxy backend,
the `Upgrade`/`Connection` headers in the nginx `/app` location, and whether
`public/build` was rebuilt after `VITE_REVERB_*` last changed.

**Every deployment sits at "Queued".** The queue worker is not running, or is
running with a stale config cache pointing at the wrong queue connection.
`php artisan queue:work` in the foreground will tell you in one line.

**Broadcasting throws a Guzzle connection error.** `REVERB_INTERNAL_*` is pointing
somewhere unreachable, or Reverb is bound to `0.0.0.0` while the app dials
`127.0.0.1` on a different port.

**A deployment is stuck `running` with no steps advancing.** The worker was
restarted mid-deployment. Nothing self-heals this: the row stays `running`.
Mark it failed and roll back from history. Exempt the worker from
restart-on-highstate.

**Salt works as root, fails as `www-data`.** `publisher_acl` or
`permissive_pki_access` is missing. Fix it there — do not quietly switch to the
sudo wrapper.

**Mixed-content errors or `http://` links.** `APP_URL` does not match the real
scheme/host, or nginx is not trusting HAProxy's `X-Forwarded-Proto` because
`set_real_ip_from` does not list the proxy addresses.

**A rollout depools the portal.** Its HAProxy backend is the same one the
appservers are in. Separate them.

**Patch dry runs fail after migration.** Expected. `--fuzz=0` refuses patches whose
context no longer matches; they were previously applying to approximately the
right place. Fix the patch files, do not re-enable fuzz.

**Undeploying a version is refused and you are sure no wiki uses it.** The map at
`MWDEPLOY_WIKIVERSIONS_PATH` could not be read, or is in a shape the shim does not
recognise. Check the step log — the failure quotes the reason. Fix the path or the
format rather than disabling the check.

**A new version's extensions are all on the wrong branch.** Copied checkouts inherit
the source version's pin, which for a release branch means 1.46 got 1.45's REL1_45.
The review screen lists every ref for exactly this reason; use the per-repository
override on the create form, or fix the pins afterwards under Repositories.

**Disk fills up during a version cut.** Each version is a full tree. There is no
pre-flight check yet — see the flagged decision in section 0.
