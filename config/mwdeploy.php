<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Salt CLI
    |---------------------------------------------------------------------------
    |
    | The portal runs on the Salt master and talks to the fleet exclusively by
    | shelling out to the local `salt` binary. There is no SSH transport in this
    | application; if you find yourself wanting one, you want a shim subcommand.
    |
    */

    'salt' => [
        'binary' => env('MWDEPLOY_SALT_BINARY', '/usr/bin/salt'),

        // The runner CLI, used only to poll a job started with `salt --async`
        // (`salt-run jobs.lookup_jid <jid>`) — a separate binary from `salt`
        // itself, and one that must be run on the same host as the master's job
        // cache (the same host `salt.binary` runs on).
        'run_binary' => env('MWDEPLOY_SALT_RUN_BINARY', '/usr/bin/salt-run'),

        // Salt execution module used to run the shim. `cmd.run_all` is the
        // default because it returns retcode/stdout/stderr separately, which is
        // what lets us distinguish "shim failed" from "shim printed nothing".
        // `cmd.run` is supported by the parser too (it only yields stdout).
        'command_module' => env('MWDEPLOY_SALT_MODULE', 'cmd.run_all'),

        // Passed to `salt --timeout=N`. Per-step overrides live in 'timeouts'.
        'default_timeout' => (int) env('MWDEPLOY_SALT_TIMEOUT', 300),

        // Ceilings on the *local* subprocess for starting an async job and for
        // each poll of it — independent of how long the job itself is allowed to
        // run once scheduled (that's still 'timeouts' below). Both are local,
        // synchronous CLI calls the portal can safely make inline in an HTTP
        // request: `salt --async` only waits for the master to accept the job,
        // and `salt-run jobs.lookup_jid` only reads its job cache.
        'async_start_timeout' => (int) env('MWDEPLOY_SALT_ASYNC_START_TIMEOUT', 30),
        'async_poll_timeout' => (int) env('MWDEPLOY_SALT_ASYNC_POLL_TIMEOUT', 30),

        // Hard ceiling on the local subprocess, independent of Salt's own
        // timeout. Always give the subprocess more room than Salt so that a
        // Salt-side timeout surfaces as a Salt error rather than a PHP kill.
        'process_timeout_slack' => (int) env('MWDEPLOY_PROCESS_SLACK', 60),

        // Extra arguments appended to every salt invocation.
        'extra_args' => array_values(array_filter(
            explode(' ', (string) env('MWDEPLOY_SALT_EXTRA_ARGS', ''))
        )),

        /*
        |-----------------------------------------------------------------------
        | HOME for the salt subprocess
        |-----------------------------------------------------------------------
        |
        | The salt CLI, run as any non-root user, puts its own log file under
        | `~/.salt` and *creates that directory* while parsing its arguments. If
        | $HOME is not writable it exits 64 with a Python traceback about
        | os.makedirs before it has spoken to a single minion — which reads like a
        | broken deployment and is actually a broken home directory.
        |
        | php-fpm does not set HOME, so the value comes from the passwd entry:
        | /var/www for www-data, which is usually root-owned. Rather than depend on
        | the pool config being right, the portal hands the subprocess a directory
        | it is guaranteed to own.
        |
        | Set to an empty string to inherit HOME from the parent process instead.
        |
        */
        'home' => env('MWDEPLOY_SALT_HOME', storage_path('framework/salt')),

        // Per-step Salt timeouts in seconds. Keys are step names from
        // App\Enums\StepName.
        'timeouts' => [
            'git-checkout' => 300,
            'git-pull' => 300,
            'git-fetch' => 300,
            'git-head' => 60,
            'git-refs' => 120,
            'git-resolve' => 60,
            'git-ls-tree' => 60,
            'git-show-blob' => 60,
            'git-remote-check' => 120,
            // A farm scan walks several hundred directories and reads a manifest
            // out of each. It is all local file I/O with no subprocesses, but the
            // tree can be large and NFS-backed, so it gets more room than the
            // other read-only steps.
            'tree-scan' => 20,
            'repo-register' => 900,
            'repo-remove' => 300,
            'version-scaffold' => 120,
            'patch-apply' => 120,
            'rsync-local' => 1800,
            'rsync-remote' => 1800,
            'l10n-rebuild' => 3600,
            'canary' => 180,
            'haproxy-depool' => 60,
            'haproxy-repool' => 60,
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Shim
    |---------------------------------------------------------------------------
    */

    'shim' => [
        // Absolute path (or bare name on $PATH) of mwdeploy-shim on the minions.
        'binary' => env('MWDEPLOY_SHIM_BINARY', '/usr/local/bin/mwdeploy-shim'),
    ],

    /*
    |---------------------------------------------------------------------------
    | MediaWiki tree layout
    |---------------------------------------------------------------------------
    */

    'paths' => [
        'staging' => env('MWDEPLOY_STAGING_PATH', '/srv/mediawiki-staging'),
        'production' => env('MWDEPLOY_PROD_PATH', '/srv/mediawiki'),

        // Where patch files land on the staging host so the shim can reach
        // them. Laravel writes uploads here via the 'patches' filesystem disk.
        'patches' => env('MWDEPLOY_PATCH_PATH', '/srv/mediawiki/scripts/extensions/patches'),

        /*
         * Where the config repository (mw-config) is checked out, relative to the
         * deploy root. It sits outside versions/ because one config serves every
         * core version.
         *
         * Configurable because farms disagree: "config", "mw-config" and
         * "wikiconfig" are all in the wild, and the import screen has to look in
         * the right place to find it.
         */
        'config_dir' => env('MWDEPLOY_CONFIG_DIR', 'config'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Adopting an existing farm
    |---------------------------------------------------------------------------
    |
    | The portal is not usually installed onto an empty disk. `mwdeploy-shim
    | tree-scan` reads the MediaWiki tree that is already there — every
    | versions/<ver>, every extension and skin inside it, their git remotes and
    | current refs, and each extension.json — and the import screen turns that
    | into registry rows. Nothing in this path writes to the tree.
    |
    */

    'discovery' => [
        // Which tree to inventory. Staging is the right answer: it is the tree the
        // portal deploys *from*, so its refs are the ones worth pinning. Set to
        // 'production' for a farm that has no staging host yet.
        'scan_root' => env('MWDEPLOY_SCAN_ROOT', 'staging'),

        // Ceiling on checkouts reported by one scan. A large farm is ~3 core
        // versions × ~200 extensions; the default leaves generous headroom and
        // still bounds the payload Salt has to carry back.
        'limit' => (int) env('MWDEPLOY_SCAN_LIMIT', 5000),

        // Parse extension.json/skin.json to get each extension's declared name and
        // version. Turn off for a faster scan that names things after directories.
        'read_manifests' => (bool) env('MWDEPLOY_SCAN_MANIFESTS', true),

        /*
         * Default name for the config repository when one is registered from the
         * import screen or the one-field config form. Only the registry label;
         * the checkout path comes from paths.config_dir.
         */
        'config_repository_name' => env('MWDEPLOY_CONFIG_REPO_NAME', 'mw-config'),

        /*
         * Import a directory that is on disk but is not a git checkout?
         *
         * Off, and it should stay off: such a directory has no remote to pin, so
         * the portal could never update or restore it, and a registry row claiming
         * otherwise is worse than no row. They are reported as warnings on the
         * import screen instead.
         */
        'import_non_git' => (bool) env('MWDEPLOY_IMPORT_NON_GIT', false),
    ],

    /*
    |---------------------------------------------------------------------------
    | Staging → appserver transport
    |---------------------------------------------------------------------------
    |
    | Open question 1 from the handoff spec. The original CLI rsynced *from* the
    | orchestrator to each appserver over SSH as the deploy user. Because the
    | portal only ever runs commands *on* a minion, the rsync has to be a pull:
    | each appserver pulls the freshly-staged tree from a source it can reach.
    |
    | The default is an rsync daemon module exported by the staging host, which
    | keeps the existing rsync flags and excludes working unchanged. Swap this
    | for an NFS mount path if the farm already has one; the shim treats the
    | value as an opaque rsync source either way.
    |
    */

    'transport' => [
        'rsync_source' => env('MWDEPLOY_RSYNC_SOURCE', 'rsync://staging.wikioasis.org/mediawiki/'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Targets
    |---------------------------------------------------------------------------
    */

    'targets' => [
        // Minion id of the staging host. Every preparation step (git checkout,
        // patching, local rsync, staging canary) runs here.
        'staging' => env('MWDEPLOY_STAGING_TARGET', 'staging'),

        // Address the staging canary connects to directly (the vhost is sent
        // as a Host header, not resolved). Left unset, the shim falls back to
        // 127.0.0.1 — fine as long as the staging host's web server listens
        // on loopback, but if it doesn't the canary fails to connect at all
        // rather than reporting a content problem.
        'staging_ip' => env('MWDEPLOY_STAGING_IP'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Rollout behaviour
    |---------------------------------------------------------------------------
    */

    'rollout' => [
        'default_parallel' => (int) env('MWDEPLOY_DEFAULT_PARALLEL', 1),
        'max_parallel' => (int) env('MWDEPLOY_MAX_PARALLEL', 8),

        // HAProxy backend name that appservers are pooled into.
        'haproxy_backend' => env('MWDEPLOY_HAPROXY_BACKEND', 'mediawiki'),

        // Canary vhost checked after each server is updated.
        'canary_vhost' => env('MWDEPLOY_CANARY_VHOST', 'meta.wikioasis.org'),
        'canary_retries' => (int) env('MWDEPLOY_CANARY_RETRIES', 3),

        // Case-insensitive marker expected in the canary body. Every successful
        // MediaWiki skin render emits <meta name="generator" content="MediaWiki
        // x.xx.x">, regardless of the wiki's own branding or sitename — unlike a
        // hardcoded site name, this default is correct for any vhost.
        'canary_expect' => env('MWDEPLOY_CANARY_EXPECT', 'content="MediaWiki'),

        // Wiki used for l10n cache rebuild smoke runs.
        'l10n_wiki' => env('MWDEPLOY_L10N_WIKI', 'testwiki'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Blocking operator decisions
    |---------------------------------------------------------------------------
    |
    | Replaces the curses Prompter. When a canary fails and --force was not set
    | the job parks, records a pending decision and polls the database until an
    | operator answers in the UI or the timeout expires.
    |
    */

    'decisions' => [
        'poll_interval' => (int) env('MWDEPLOY_DECISION_POLL', 2),
        'timeout' => (int) env('MWDEPLOY_DECISION_TIMEOUT', 900),

        // Applied when nobody answers before the timeout. Per the handoff spec
        // an unattended canary failure must not leave the farm mid-rollout on a
        // broken ref, so the default is to abort *and* roll back.
        'timeout_default' => env('MWDEPLOY_DECISION_TIMEOUT_DEFAULT', 'abort_and_rollback'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Git ref discovery
    |---------------------------------------------------------------------------
    |
    | Branch/commit listings for the ref picker. No API tokens or rate limits are
    | involved either way — the staging tree already has every repo cloned.
    |
    |   salt  — ask the staging minion via `mwdeploy-shim git-refs`. Correct
    |           regardless of whether staging lives on this host. Default.
    |   local — shell out to git against the staging path on this host. Cheaper,
    |           but only valid when the Salt master and the staging tree are the
    |           same machine.
    |   none  — no discovery; the ref picker becomes free-text only.
    |
    */

    'git' => [
        'driver' => env('MWDEPLOY_GIT_DRIVER', 'salt'),
        'commit_limit' => (int) env('MWDEPLOY_GIT_COMMIT_LIMIT', 30),
        'binary' => env('MWDEPLOY_GIT_BINARY', '/usr/bin/git'),
        'process_timeout' => (int) env('MWDEPLOY_GIT_TIMEOUT', 60),

        // Ceiling on a blob read by the file browser. Matches the shim's own
        // --max-bytes default; kept here too so the Local driver (which never
        // goes through the shim) enforces the same cap.
        'blob_max_bytes' => (int) env('MWDEPLOY_GIT_BLOB_MAX_BYTES', 2 * 1024 * 1024),

        // Blob content above this size is written to storage/app/git-file-cache
        // instead of the git_file_cache_entries.payload JSON column.
        'blob_disk_threshold' => (int) env('MWDEPLOY_GIT_BLOB_DISK_THRESHOLD', 65536),
    ],

];
