# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Sloppy runs on any PHP project.** `vendor/bin/sloppy` analyses a project
  with no framework, discovering its source roots from `composer.json` PSR-4
  autoload entries when there is no configuration file. The Artisan commands
  are unchanged and now share their entire implementation with the binary, so
  the two surfaces cannot drift.
- **`sloppy.framework`**, defaulting to `auto`. The ten `SL2xx` Laravel rules
  run when the analysed project's `composer.json` requires Laravel, and are
  skipped otherwise. **Skipped rules are always reported** — named in the
  console footer and listed under `rules_skipped` in JSON output — because a
  project that silently lost ten rules would score better than its code
  deserves.

### Changed

- `illuminate/console`, `illuminate/contracts` and `illuminate/support` are now
  **dev dependencies**, and `symfony/console` is a runtime one. A Laravel
  application is unaffected: it already has Illuminate, and the service
  provider is only loaded by package discovery. A Symfony or vanilla project no
  longer installs Laravel to use Sloppy.
- `--format` is validated against an enum rather than compared as a string, so
  an unsupported format fails with `exit 2` and a message naming the value.
- **`--min-confidence` now rejects a value outside 0-100** instead of silently
  clamping it. Passing `--min-confidence=200` previously clamped to 100, and
  since no rule reports 100% confidence that produced zero findings and a score
  of 100/100 — a clean report for code that had not been checked. It now exits
  `2` with `--min-confidence must be a number between 0 and 100.`

### Fixed

- **`sloppy:diff` was spending minutes in process startup.** On a 70,000-line
  application with 773 changed files it took 4m01s, of which 4m31s of measured
  work was git: one `git diff` process per changed file to read its hunks
  (144s) and one `git show` per file to read its base contents (126s). The
  analysis those calls feed took 22s. Hunks are now read in batched
  `git diff` calls sized to the platform's command-line limit, and base
  contents come from a single `git cat-file --batch` process fed on stdin. The
  same comparison now runs in **20.8s** — identical findings, identical score.
- **`sloppy:diff` no longer analyses the project twice to discover it has
  nothing to compare.** A diff with no analysable PHP changes in it returns
  immediately instead of parsing every file on both sides first.

## [0.1.0] — 2026-09-10

First release: the deterministic analyser.

Supports **PHP 8.3 and 8.4** with **Laravel 12 and 13**. Laravel 11 is not
supported: its security window has closed, so every 11.x release now carries
advisories that will never be patched and Composer's advisory policy refuses to
install them.

### Added

**Analysis**

- AST-based analyser built on `nikic/php-parser`, with fully qualified name
  resolution and parent/sibling links so rules never re-parse or re-read a
  file. One parse per file per run, shared by every rule.
- Two-pass run: a project index is built from every file before any rule
  executes, so cross-file rules can ask about classes they are not currently
  looking at — implementations of an interface, files referencing a name,
  structurally identical method bodies.
- `NodeHelper`, the shared AST vocabulary: class classification (controller,
  model, form request, service, job, middleware), metrics (statements,
  cyclomatic complexity, nesting depth, call counts, distinct collaborators),
  fluent-chain walking, and structural hashing.
- `ConditionShape`, which reduces `$user === null`, `is_null($user)` and
  `! $user` to one comparable shape so two rules can see through spelling.
- A rule that throws is recorded and the run continues; a file that will not
  parse is recorded and the rest of the project is still analysed.

**Rules** — 23, each with tests for the pattern, for code that must *not*
trigger it, and for its configuration.

- PHP and general: `SL101` God Method, `SL102` God Class, `SL103` Excessive
  Nesting, `SL104` Duplicate Logic, `SL105` Dead Private Method, `SL106`
  Unused Constructor Dependency, `SL107` Swallowed Exception, `SL108`
  Redundant Condition, `SL109` Narrative Comment, `SL110` Defensive
  Programming Noise.
- Laravel: `SL201` Business Logic In Controller, `SL202` Inline Validation,
  `SL203` Possible N+1, `SL204` Query Inside Loop, `SL205` Collection Instead
  Of Database Query, `SL206` Excessive Controller Dependencies, `SL207`
  Excessive Service Dependencies, `SL208` Direct External API Call, `SL209`
  Model Doing Too Much, `SL210` Suspicious `Model::all()`.
- Architecture, all advisory: `SL301` Abstraction Inflation, `SL302` Empty
  Wrapper Class, `SL303` Single-Use Abstraction.

**Commands**

- `php artisan sloppy` — analyse the configured paths, with `--path`,
  `--format`, `--fail-on`, `--min-confidence`, `--rule`, `--explain` and
  `--no-baseline`.
- `php artisan sloppy:diff [base]` — report what a change introduced against a
  git revision, separating new findings from inherited and resolved ones.
  Compares to the working tree, so uncommitted and untracked files are
  included.
- `php artisan sloppy:baseline` — record today's findings as accepted so only
  new ones fail.

**Scoring, output and configuration**

- A documented deterministic slop score, normalised by codebase size and
  weighted by confidence, with configurable weights, multiplier and bands.
- Console output grouped by file, with the measurement, the suggestion and the
  confidence on every finding; the longer rationale is behind `--explain`.
- Stable, versioned JSON output for both commands (`schema: 1`).
- Exit codes `0` / `1` / `2`, distinguishing "found problems" from "could not
  run".
- `config/sloppy.php` as the single configuration mechanism, documenting every
  option of every rule with its default. Rules are enabled by default, so a
  new rule in a new release starts working without a config change.
- Per-rule severity overrides, so a team can downgrade a rule it disagrees
  with instead of switching it off.
- Custom rules via `sloppy.custom_rules`, with `BaseRule` supplying option
  reading, severity overrides and finding construction.

### Notes on design

- **Sloppy makes no claim about authorship.** It detects code-quality patterns
  associated with fast, unreviewed, machine-assisted output. Confidence is the
  analyser's certainty that a pattern is present, never a probability that a
  machine wrote it. No rule reports 100%.
- **No LLM, no network, no API key.** Everything runs locally and
  deterministically; the same input always produces the same report.
- **Baseline identity excludes line numbers.** Entries are keyed on rule, file
  and a rule-supplied fingerprint, so adding an import does not resurrect every
  finding in a file.
- **False positives are treated as the primary risk.** `tests/Fixtures/Good`
  holds deliberately ordinary Laravel code, and a test asserts that all 23
  rules report zero findings on it with a score of 100.

### Sloppy's report on itself

The suite runs Sloppy over its own `src/`, requires a score in the Clean band,
and fails if any finding appears that has not been signed off in
`tests/Feature/SelfCheckTest.php`. Current score: **99/100**, one accepted
finding:

- `SL102` God Class on `src/Ast/NodeHelper.php`. It is the shared AST
  vocabulary every rule is written against, and the rule is right that it is
  large. Splitting it far enough to clear the thresholds would take five
  classes, and a typical rule would import three of them to ask three
  questions — the ceremony this package exists to discourage. The finding
  stands as a recorded decision rather than being hidden by a lower threshold.

Four findings Sloppy raised about its own code during development were genuine
and were fixed rather than accepted: two god methods, one method nested five
levels deep, and one dependency that was not being used.

### Not in this release

Deliberately, so that nothing ships stubbed:

- No `sloppy:fix`. Safe automated fixes want a real transformation engine
  (Rector) behind them; suggestions are text for now.
- No `sloppy:explain`, `sloppy:context` or `sloppy:mcp`.
- No AI-assisted anything. When it arrives it will be opt-in and separate, and
  the deterministic analyser will keep working without it.
- No `Contracts\Analyzer` or `Contracts\Fixer`. Interfaces with a single
  trivial implementation are what `SL303` reports, and shipping them here
  would be the package contradicting itself. `Contracts\Rule`,
  `Contracts\Formatter` and `Contracts\DiffFormatter` exist because each has
  several implementations.

### Known limitations

- **N+1 detection is heuristic.** `SL203` reads eager loading off the
  expression a loop iterates. Loading done elsewhere — a controller that calls
  `load()` before handing the collection to a view composer — is invisible to
  it, which is why findings say "possible" and score below 90.
- **No type inference.** `$order->customer->name` is a relation in a Laravel
  app and an object-graph walk anywhere else. `SL203` requires evidence that
  the loop is walking database rows before reading a plain property chain as a
  relation, and otherwise reports only unmistakably Eloquent accesses.
- **Duplicate detection is structural, not semantic.** `SL104` normalises
  local variable names, literals and the class a static call targets. Two
  methods with the same shape are reported; whether they mean the same thing is
  a judgement it leaves to the reader, which is why it caps confidence at 90.
- **`SL105` only sees the declaring file.** That is sound for private methods,
  and traits are skipped entirely because the composing class is not visible.
- **Diff mode parses the project twice** — once at the working tree and once at
  the base revision — because that is what makes new-versus-inherited accurate.
  Rules still only run on changed files.
- **No caching yet.** Every run re-parses. Fine for the applications measured
  so far; worth revisiting with numbers rather than guesses.

[Unreleased]: https://github.com/heyosseus/sloppy/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/heyosseus/sloppy/releases/tag/v0.1.0
