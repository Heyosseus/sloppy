<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Turn Sloppy off without removing the package. The commands stay
    | registered and exit successfully, which keeps CI green while you decide.
    |
    */

    'enabled' => env('SLOPPY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Framework
    |--------------------------------------------------------------------------
    |
    | Which framework's rules apply. On "auto" this is read from your
    | composer.json, so a Laravel project gets the SL2xx rules and a vanilla
    | PHP project does not. Set it to "laravel" to force them on, or "none" to
    | run only the framework-independent rules.
    |
    | Supported: "auto", "laravel", "none"
    |
    */

    'framework' => 'auto',

    /*
    |--------------------------------------------------------------------------
    | What to analyse
    |--------------------------------------------------------------------------
    |
    | Paths are relative to the project root. Directories are scanned
    | recursively for .php files; individual files may be listed too.
    |
    */

    'paths' => [
        'app',
    ],

    /*
    |--------------------------------------------------------------------------
    | What to skip
    |--------------------------------------------------------------------------
    |
    | Matched as path fragments against the project-relative path, so
    | "storage" excludes everything beneath it. Entries containing "*" are
    | matched as globs instead.
    |
    | Migrations and factories are excluded by default: they are legitimately
    | repetitive and long, and flagging them teaches people to ignore reports.
    |
    */

    'exclude' => [
        'vendor',
        'storage',
        'bootstrap/cache',
        'node_modules',
        'public',
        'database/migrations',
        'database/factories',
        'database/seeders',
        '*.blade.php',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure threshold
    |--------------------------------------------------------------------------
    |
    | The lowest severity that makes `sloppy` and `sloppy:diff` exit non-zero:
    | critical, high, medium, low, info -- or null to never fail on findings.
    |
    | In diff mode this applies only to NEW findings, so inherited debt cannot
    | fail a build that did not cause it.
    |
    */

    'fail_on' => 'high',

    /*
    |--------------------------------------------------------------------------
    | Confidence floor
    |--------------------------------------------------------------------------
    |
    | Findings below this confidence are dropped before anything is reported.
    | Confidence is how sure the analyser is that the pattern it describes is
    | really present -- it is NOT a probability that the code was written by an
    | AI. Raising this is the quickest way to quieten a noisy first run.
    |
    */

    'min_confidence' => 0,

    /*
    |--------------------------------------------------------------------------
    | Baseline file
    |--------------------------------------------------------------------------
    |
    | Written by `php artisan sloppy:baseline` and read by `sloppy`. Commit it,
    | so existing debt does not fail CI and new debt does.
    |
    */

    'baseline' => '.sloppy-baseline.json',

    /*
    |--------------------------------------------------------------------------
    | Slop score
    |--------------------------------------------------------------------------
    |
    | Deterministic, documented in the README, and never a claim about
    | authorship. In short:
    |
    |   penalty  = Σ weight(severity) × confidence / 100
    |   density  = penalty / (lines / lines_per_unit)
    |   coverage = share of analysed lines covered by findings
    |   score    = 100 − min(100, density × penalty_multiplier × (1 + coverage))
    |
    | `bands` give the minimum score for each label. Anything below the lowest
    | is "Severe slop".
    |
    */

    'score' => [
        'weights' => [
            'critical' => 20.0,
            'high' => 10.0,
            'medium' => 4.0,
            'low' => 1.5,
            'info' => 0.5,
        ],

        'lines_per_unit' => 1000,

        'penalty_multiplier' => 1.0,

        'bands' => [
            'clean' => 90,
            'healthy' => 75,
            'needs_attention' => 60,
            'sloppy' => 40,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rules
    |--------------------------------------------------------------------------
    |
    | Every rule is on unless it says otherwise here, so new rules in new
    | releases start working without a config change. Each rule accepts:
    |
    |   'enabled'  => false        turn it off entirely
    |   'severity' => 'low'        keep the finding, lower the stakes
    |
    | plus its own options, listed below with their defaults. Prefer lowering
    | a severity to disabling a rule: the finding stays visible without
    | failing the build.
    |
    */

    'rules' => [

        // ---- PHP and general -------------------------------------------

        'SL101' => [
            // God Method. Fires when two of these are exceeded, or when lines
            // or complexity are more than double their limit.
            'max_lines' => 80,
            'max_complexity' => 15,
            'max_statements' => 40,
            'max_nesting' => 4,
            'max_calls' => 25,
            'max_collaborators' => 8,
            'min_signals' => 2,
        ],

        'SL102' => [
            // God Class. Models get `model_leniency` times the size limits,
            // because many small relations and accessors are normal for them.
            'max_lines' => 300,
            'max_methods' => 20,
            'max_public_methods' => 15,
            'max_statements' => 200,
            'max_dependencies' => 8,
            'max_collaborators' => 15,
            'min_signals' => 2,
            'model_leniency' => 1.5,
        ],

        'SL103' => [
            // Excessive Nesting. Closures do not count as a level.
            'max_depth' => 4,
        ],

        'SL104' => [
            // Duplicate Logic. Bodies shorter than this are too small for a
            // structural match to mean anything.
            'min_statements' => 8,
            'ignore_methods' => ['__construct', '__invoke', 'up', 'down', 'definition'],
        ],

        'SL105' => [
            // Dead Private Method.
        ],

        'SL106' => [
            // Unused Constructor Dependency.
        ],

        'SL107' => [
            // Swallowed Exception.
        ],

        'SL108' => [
            // Redundant Condition.
        ],

        'SL109' => [
            // Narrative Comment. The most subjective rule in the set -- set
            // 'enabled' => false if it does not match how your team writes.
            'max_words' => 8,
            'detect_step_comments' => true,
        ],

        'SL110' => [
            // Defensive Programming Noise.
        ],

        // ---- Laravel ---------------------------------------------------

        'SL201' => [
            // Business Logic In Controller. Business concerns are weighted and
            // summed; `min_score` is how much has to pile up before it counts.
            'min_score' => 6,
            'min_statements' => 8,
            'max_complexity' => 8,
            'max_statements' => 25,
        ],

        'SL202' => [
            // Inline Validation.
            'max_rules' => 6,
            'controllers_only' => true,
        ],

        'SL203' => [
            // Possible N+1.
            'ignore_relations' => ['pivot', 'attributes', 'relations'],
        ],

        'SL204' => [
            // Query Inside Loop.
        ],

        'SL205' => [
            // Collection Instead Of Database Query.
        ],

        'SL206' => [
            // Excessive Controller Dependencies.
            'max_dependencies' => 4,
        ],

        'SL207' => [
            // Excessive Service Dependencies -- deliberately more generous
            // than SL206, because coordinating is a service's job.
            'max_dependencies' => 7,
        ],

        'SL208' => [
            // Direct External API Call.
        ],

        'SL209' => [
            // Model Doing Too Much.
            'max_method_lines' => 40,
            'min_signals' => 1,
        ],

        'SL210' => [
            // Suspicious Model::all(). Add your genuinely small reference
            // tables here rather than turning the rule off.
            'ignore_models' => [
                'Country', 'Currency', 'Setting', 'Role', 'Permission',
                'Language', 'Timezone', 'State', 'Locale',
            ],
        ],

        // ---- Architecture ----------------------------------------------

        'SL301' => [
            // Abstraction Inflation. Advisory: it reports layers that are not
            // earning their place *for the current usage*.
            'min_layers' => 3,
            'min_signals' => 2,
            'trivial_max_statements' => 12,
            'trivial_max_methods' => 3,
        ],

        'SL302' => [
            // Empty Wrapper Class.
            'min_methods' => 2,
            'min_delegation_ratio' => 0.8,
        ],

        'SL303' => [
            // Single-Use Abstraction. Advisory.
            'max_methods' => 3,
            'max_usages' => 1,
            'skip_layer_stacks' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Custom rules
    |--------------------------------------------------------------------------
    |
    | Any class implementing Heyosseus\Sloppy\Contracts\Rule. Extending
    | Heyosseus\Sloppy\Rules\BaseRule gives you option reading, severity
    | overrides and finding construction for free. Options come from the
    | 'rules' array above, keyed by whatever the rule's id() returns.
    |
    */

    'custom_rules' => [
        // App\Sloppy\NoFacadesInDomainRule::class,
    ],

];
