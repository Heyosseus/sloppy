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
    | This file is also the defaults the standalone binary and the phar read,
    | and neither of those has Laravel's `env()` in scope -- so the helper is
    | used where it exists and skipped where it does not. Outside Laravel
    | there is no `.env` being loaded for it to read anyway; set `enabled`
    | directly in a `sloppy.php` at your project root instead.
    |
    */

    'enabled' => function_exists('env') ? env('SLOPPY_ENABLED', true) : true,

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
    | Architecture
    |--------------------------------------------------------------------------
    |
    | Which role each class plays. The rules about where code belongs read
    | these roles rather than guessing from names: SL201, SL202 and SL206 look
    | at "controller", SL207 at "service", and SL208 at "controller", "model",
    | "form-request" and "middleware".
    |
    | The "laravel" preset defines those five the way Sloppy always has. Add
    | your own roles under "roles"; they are tried first, in the order you
    | write them, and a class takes the first role it matches. A role with a
    | preset role's name replaces it, and false removes it:
    |
    |   'roles' => [
    |       'controller' => ['namespace' => 'App\Ui\Http\*', 'suffix' => 'Controller'],
    |       'service' => false,
    |   ],
    |
    | Matchers: namespace, path, parent, extends, implements, uses and
    | attribute take globs ("*" is any text); suffix and kind ("class",
    | "interface", "trait", "enum") are compared as written. Keys in one role
    | must all match; "any" takes a list of alternatives and "not" negates.
    | Run `sloppy architecture` to see what each role matched.
    |
    | Presets: "laravel", "none"
    |
    */

    'architecture' => [
        'preset' => 'laravel',
        'roles' => [],
    ],

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
    | Tests are excluded for the same reason -- long setup, many public methods
    | and near-identical bodies are what good tests look like. The capitalised
    | entries cover module layouts such as Modules/Billing/Database/Migrations
    | and Modules/Billing/Tests.
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
        'Database/Migrations',
        'Database/Factories',
        'Database/Seeders',
        'tests',
        'Tests',
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
    |   penalty(rule)   = Σ weight(severity) × confidence / 100
    |   density(rule)   = penalty(rule) / (lines / lines_per_unit)
    |   coverage        = share of analysed lines covered by findings,
    |                     each line counted once
    |   deduction(rule) = density(rule) × penalty_multiplier × (1 + coverage)
    |   capped(rule)    = rule_cap × (1 − e^(−deduction(rule) / rule_cap))
    |   score           = 100 − min(100, Σ capped(rule))
    |
    | `rule_cap` gives each rule diminishing returns: no single rule can take
    | more than that many points off, however often it fires. Set it to 0 to
    | count every rule in full.
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

        'rule_cap' => 30.0,

        'bands' => [
            'clean' => 90,
            'healthy' => 75,
            'needs_attention' => 60,
            'sloppy' => 40,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk
    |--------------------------------------------------------------------------
    |
    | Risk answers a different question from the slop score. The score measures
    | quality: density-normalised, baseline-compatible, about the codebase.
    | Risk measures attention: absolute, change-aware, about what a reviewer
    | should read next. Nothing here moves a score or a baseline entry.
    |
    |   risk = severity_weight x (confidence / 100) x novelty x proximity
    |            x reach x exposure x activity
    |
    |   reach     = 1 + log10(1 + blast_radius) x reach_weight
    |   novelty   = new 1.0 | inherited 0.25
    |   proximity = inside a changed hunk 1.0 | elsewhere in a touched file 0.3
    |   exposure  = 1 + (1 - coverage) x exposure_weight
    |   activity  = 1 + log10(1 + recent changes) x churn_weight
    |
    | Every factor is 1.0 when it cannot be measured, so a project with no
    | coverage report ranks exactly as it did before exposure existed.
    |
    | Run any command with --explain-risk to see the arithmetic for every
    | finding, including the intermediate values. A number you can watch the
    | tool derive is not a magic number.
    |
    */

    'risk' => [
        // Severity weights default to the score's, so the two measures agree
        // about which findings are serious and disagree only about what they
        // then do with that.
        'severity_weights' => [
            'critical' => 20.0,
            'high' => 10.0,
            'medium' => 4.0,
            'low' => 1.5,
            'info' => 0.5,
        ],

        // How much the blast radius -- how many files reach the code a finding
        // sits in -- is allowed to matter. Reach is logarithmic because a class
        // with two hundred callers is not two hundred times more urgent than
        // one with a single caller; the tenth caller costs less new attention
        // than the first. At 1.0, one usage yields 1.30, ten yields 2.04 and a
        // hundred yields 3.00 -- a 2.3x spread across two orders of magnitude,
        // which is roughly the spread a reviewer actually feels. Set it to 0.0
        // to rank on severity and confidence alone.
        'reach_weight' => 1.0,

        // How much a changed file the tests never execute outranks an
        // identical covered one. At 0.5 an untested file ranks 1.5x -- enough
        // to lift it past a slightly worse finding in well-tested code, not
        // enough to let coverage dominate severity. Set it to 0.0 to ignore
        // coverage entirely.
        'exposure_weight' => 0.5,

        // A clover or cobertura report. Null checks build/logs/clover.xml,
        // coverage.xml, build/coverage/clover.xml, coverage/clover.xml and
        // build/logs/cobertura.xml. It lives here rather than at the top level
        // because risk is the only thing it feeds: coverage changes the order
        // findings are read in and never the slop score, because a build
        // artefact that may be absent or stale must not move a number two
        // people are expected to compare.
        'coverage' => null,

        // How much a file that keeps changing outranks one nobody touches:
        //   activity = 1 + log10(1 + recent changes) x churn_weight
        // At 0.5, a file changed once ranks 1.15x, ten times 1.52x and a
        // hundred times 2.0x. Outside git, or at 0.0, it is 1.0 and git is not
        // asked. Like coverage, history moves the reading order and never the
        // score.
        'churn_weight' => 0.5,

        // How many recent commits the history is read from. Commits, not
        // months, so the same checkout ranks the same way on any day.
        'churn_commits' => 500,
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
    |   'tier'     => 'defect'     where `sloppy scan` puts it: defect,
    |                              maintainability or advisory
    |
    | plus its own options, listed below with their defaults. Prefer lowering
    | a severity to disabling a rule: the finding stays visible without
    | failing the build.
    |
    | Tiers decide only how a scan lays its findings out. Defects -- error
    | handling, performance, dead code and suppression rules -- are listed
    | one by one, highest risk first. Maintainability findings -- size,
    | duplication, readability, dependencies, Laravel structure -- are
    | summarised per file. Advisory findings -- the architecture rules -- are
    | counted. Every tier still counts towards the score, the baseline and
    | fail_on.
    |
    */

    'rules' => [

        // ---- PHP and general -------------------------------------------

        'SL101' => [
            // God Method. Fires when two of these are exceeded, or when lines
            // or complexity are more than double their limit. max_calls and
            // max_collaborators are one signal between them: many calls to
            // many things is one measurement, and a short method that only
            // delegates is not a god method.
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
            // Getters and fluent setters never count as methods, and a class
            // within both max_dependencies and max_collaborators needs one
            // signal more than min_signals.
            'max_lines' => 300,
            'max_methods' => 20,
            'max_public_methods' => 15,
            'max_statements' => 200,
            'max_dependencies' => 8,
            'max_collaborators' => 15,
            'min_signals' => 2,
            'model_leniency' => 1.5,
            // Classes extending one of these, directly or through a project
            // base class, get `framework_leniency` times the size limits: the
            // framework decides their public surface. Add your own here.
            'framework_leniency' => 1.5,
            'framework_bases' => [
                'Filament\Actions\Action',
                'Filament\Forms\Components\Component',
                'Filament\Forms\Components\Field',
                'Filament\Infolists\Components\Entry',
                'Filament\Pages\Page',
                'Filament\Resources\Pages\Page',
                'Filament\Resources\Resource',
                'Filament\Schemas\Components\Component',
                'Filament\Tables\Columns\Column',
                'Filament\Tables\Filters\BaseFilter',
                Filament\Widgets\Widget::class,
                'Livewire\Component',
            ],
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

        'SL111' => [
            // Copy-Paste Drift. Bodies shorter than this are too small for
            // near-identity to mean anything -- the same floor SL104 uses.
            'min_statements' => 8,

            // How many token edits apart two bodies may be and still count as
            // siblings. This is a cost knob, not a precision knob: swept on a
            // 73,737-line application with the ratio held open, the findings
            // kept at ratio 0.08 were 4 at k=16, 5 at k=24, and 6 from k=28
            // onward -- unchanged at k=32, 40, 56 and 80 -- while the time grew
            // from 0.12s at k=24 to 95.92s at k=80. 28 is where precision
            // saturates, and it costs 0.28s. 24 misses one genuine finding: two
            // OTP methods in one service, 28 tokens and 3.37% apart.
            'max_token_distance' => 28,

            // The precision knob. On that same corpus every genuine divergence
            // sat at 5.61% or below and the one false positive was a 103-token
            // body at 23.3%; raising this to 0.12 admits two more pairs that are
            // plausible but weaker. False positives are this package's primary
            // risk, so the default stays tight and the comment says what
            // loosening it buys.
            'max_divergence_ratio' => 0.08,

            // A hard ceiling on how many band-limited comparisons one run may
            // perform. It counts only the comparisons that reach the matrix --
            // the expensive ones -- and not the candidate pairs rejected before
            // it by the length, hash and token-frequency gates. That
            // distinction is the whole point: on a 73,737-line application
            // 11,063 pairs arrive at those gates and only 348 get past, so a
            // ceiling measured in arrivals rather than survivors is 32x tighter
            // than it looks. Set to 5,000 this never binds on real code (348
            // needed, 0.29s) but does bound a corpus of same-length near-clones,
            // where the frequency gate cannot reject anything: 600 such bodies
            // would otherwise make 179,700 comparisons and take 318s, and are
            // capped to 8.8s. Wall-clock for a capped run scales with body
            // length -- roughly 1.4 ms per comparison at 186 tokens and 4 ms at
            // 500 -- so the ceiling is a count, not a number of seconds. A run
            // that hits it says so in every finding it emits.
            'max_comparisons' => 5000,
        ],

        'SL112' => [
            // Placeholder Implementation. Fires only on bodies that say they
            // are unfinished: an elided-code comment, a "not implemented"
            // throw, or a TODO over an empty or constant return.
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
            // Classes extending one of these, directly or through your own
            // base class, are a convention rather than a layer: they neither
            // count towards a stack nor get reported. Add your project's own
            // pattern bases here -- a data mapper base, say.
            'convention_bases' => [
                Illuminate\Database\Eloquent\Factories\Factory::class,
                Illuminate\Database\Seeder::class,
                Illuminate\Foundation\Http\FormRequest::class,
                Illuminate\Http\Resources\Json\JsonResource::class,
                Illuminate\Http\Resources\Json\ResourceCollection::class,
                Illuminate\Support\ServiceProvider::class,
                'League\Fractal\TransformerAbstract',
            ],
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

        // ---- Suppression -----------------------------------------------

        'SL502' => [
            // Baseline Growth. Other tools' baselines, watched for entries
            // added by a change -- read from git across two revisions and
            // never written to. Not Sloppy's own baseline, which is the
            // `baseline` key above.
            'files' => [
                'phpstan-baseline.neon',
                'psalm-baseline.xml',
            ],
        ],

        'SL503' => [
            // Weakened Test. Directories whose PHP files are tests, compared
            // across two revisions for skips, lost assertions, assertions that
            // cannot fail and deleted tests. Any `*Test.php` counts wherever
            // it lives. Like SL502 it only runs where there is a base to
            // compare with: diff, ci, review and the agent hooks.
            'paths' => [
                'tests',
            ],
        ],

        'SL501' => [
            // Unexplained Suppression. Which annotations count as silencing an
            // analyser. Every tool spells this differently and more of them
            // keep appearing, so add your own here rather than waiting for a
            // release. The rule fires only when no reason follows the
            // annotation in the same comment -- a defended suppression is a
            // reviewed decision, not a finding.
            'annotations' => [
                '@phpstan-ignore',
                '@psalm-suppress',
                '@mago-expect',
                '@noinspection',
                'phpcs:ignore',
                '@SuppressWarnings',
            ],
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

    /*
    |--------------------------------------------------------------------------
    | Health snapshot
    |--------------------------------------------------------------------------
    |
    | `sloppy:health`, the Filament widget and a NativePHP menu bar all read
    | the same cached snapshot, because analysing a project takes seconds and a
    | dashboard has milliseconds. Keep it warm with a scheduled
    | `sloppy:health --fresh`; the surfaces refresh it themselves when it goes
    | stale, and `ttl` is how long "fresh" means here.
    |
    */

    'health' => [
        'cache' => '.sloppy-health.json',
        'ttl' => 900,
        'top' => 5,
    ],

];
