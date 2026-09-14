<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Analysis Paths
    |--------------------------------------------------------------------------
    |
    | When RaceGuard is asked to scan the whole project (via the --all flag),
    | only files inside these paths are analysed. By default RaceGuard is
    | Git-aware and inspects the files you are currently changing, so these
    | paths mostly act as a safety net for full-project scans.
    |
    */

    'paths' => [
        'app',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths
    |--------------------------------------------------------------------------
    |
    | Files whose path contains any of these fragments are never analysed,
    | even if Git reports them as changed. Useful for generated code and
    | third-party directories.
    |
    */

    'exclude' => [
        'vendor',
        'storage',
        'bootstrap/cache',
        'node_modules',
    ],

    /*
    |--------------------------------------------------------------------------
    | Minimum Severity
    |--------------------------------------------------------------------------
    |
    | Findings below this severity are hidden from the report. This lets you
    | keep the signal-to-noise ratio high in CI while still surfacing every
    | finding locally. One of: critical, high, medium, low.
    |
    */

    'minimum_severity' => 'medium',

    /*
    |--------------------------------------------------------------------------
    | Fail On
    |--------------------------------------------------------------------------
    |
    | The command exits with a non-zero status code when a finding of at
    | least this severity is present. Set to null to always exit 0, which
    | is useful when you want RaceGuard to report without blocking a commit.
    |
    */

    'fail_on' => 'high',

    /*
    |--------------------------------------------------------------------------
    | Rules
    |--------------------------------------------------------------------------
    |
    | Toggle individual detection rules on or off. Remove or set to false to
    | disable a rule entirely.
    |
    */

    'rules' => [
        // RG001–RG013 — see RULES.md for the full catalogue and RG codes.
        'read_modify_write' => true,           // RG001
        'check_then_act' => true,              // RG002
        'check_then_create' => true,           // RG003
        'unsafe_balance_update' => true,       // RG004
        'unsafe_state_transition' => true,     // RG005
        'missing_idempotency' => true,         // RG006
        'transaction_without_lock' => true,    // RG007
        'external_side_effect_before_commit' => true, // RG008
        'overlapping_job' => true,             // RG009
        'missing_database_uniqueness' => true, // RG010
        'non_atomic_counter' => true,          // RG011
        'guard_then_save' => true,             // RG012
        'non_atomic_cache' => true,            // RG013
    ],

    /*
    |--------------------------------------------------------------------------
    | Git Scope
    |--------------------------------------------------------------------------
    |
    | Which set of Git changes to inspect by default:
    |   'dirty'    - staged + unstaged + untracked (working tree changes)
    |   'staged'   - only files staged for commit
    |   'unstaged' - only unstaged working-tree changes
    |
    */

    'git_scope' => 'dirty',

    /*
    |--------------------------------------------------------------------------
    | Diff Granularity
    |--------------------------------------------------------------------------
    |
    | How much of each changed file to analyse:
    |   'file'  - scan the whole changed file (report races anywhere in it)
    |   'lines' - only report findings that sit on the lines you changed
    |
    | 'file' is the default: if a file is touched at all, the entire file is
    | checked. Override per-run with --whole-file or --changed-lines.
    |
    */

    'diff_granularity' => 'file',

];
