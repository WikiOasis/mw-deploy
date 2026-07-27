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

        // Salt execution module used to run the shim. `cmd.run_all` is the
        // default because it returns retcode/stdout/stderr separately, which is
        // what lets us distinguish "shim failed" from "shim printed nothing".
        // `cmd.run` is supported by the parser too (it only yields stdout).
        'command_module' => env('MWDEPLOY_SALT_MODULE', 'cmd.run_all'),

        // Passed to `salt --timeout=N`. Per-step overrides live in 'timeouts'.
        'default_timeout' => (int) env('MWDEPLOY_SALT_TIMEOUT', 300),

        // Hard ceiling on the local subprocess, independent of Salt's own
        // timeout. Always give the subprocess more room than Salt so that a
        // Salt-side timeout surfaces as a Salt error rather than a PHP kill.
        'process_timeout_slack' => (int) env('MWDEPLOY_PROCESS_SLACK', 60),

        // Extra arguments appended to every salt invocation.
        'extra_args' => array_values(array_filter(
            explode(' ', (string) env('MWDEPLOY_SALT_EXTRA_ARGS', ''))
        )),

        // Per-step Salt timeouts in seconds. Keys are step names from
        // App\Enums\StepName.
        'timeouts' => [
            'git-checkout' => 300,
            'git-pull' => 300,
            'git-head' => 60,
            'git-refs' => 120,
            'repo-register' => 900,
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
    ],

];
