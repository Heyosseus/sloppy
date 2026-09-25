# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] — 2026-09-25

First stable release. The CLI, configuration keys, rule IDs, finding
fingerprints and JSON output are now covered by semantic versioning.

### Fixed

- **SL203 no longer reads Collection methods as relations.** In
  `$group->pluck('id')->toArray()` inside a loop over a grouped collection,
  `pluck` was reported as a lazy-loaded relation with the suggestion
  `->with('pluck')`. A name that is itself a Collection or query builder
  method (`pluck`, `map`, `filter`, `where`, `count`...) is no longer taken
  for a relation. Reported in #19.
- **SL204 gives write advice for writes.** A `create()`, `update()` or
  `insert()` inside a loop was told to use `whereIn(...)->get()->keyBy(...)`,
  which only fixes reads. Writes now say "writes to" and suggest one
  `insert($rows)` or `upsert($rows, ...)` after the loop, and findings carry a
  `kind` metric of `read` or `write`. An `insert`, `insertOrIgnore` or
  `upsert` once per chunk of `array_chunk(...)` or `->chunk(...)` is the bulk
  pattern and is no longer reported. Reported in #22.
- **SL101 no longer flags declarative builders.** A method that is a single
  statement building a value, such as a Filament form or table definition,
  was reported on line count, calls and collaborators alone. It is now only
  reported when complexity, statement count or nesting is also over its
  limit. Reported in #20.
- **SL102 judges framework classes by what they do, not the surface the
  framework gives them.** Reported in #23:
  - Getters and fluent setters (`return $this->label;`,
    `return $this->evaluate($this->label);`,
    `$this->label = $label; return $this;`) no longer count towards the
    method or public method count. They are reported as a separate
    `accessors` metric.
  - Classes extending a Filament resource, page, widget, action, form
    component, table column or filter, or a Livewire component, directly or
    through a project base class, get `framework_leniency` (1.5) times the
    size limits, as models already did. The list is the new
    `framework_bases` option.
  - A class within both `max_dependencies` and `max_collaborators` needs one
    more signal than `min_signals` before it is reported.
  - The message no longer says "0 injected dependencies"; the count only
    appears when there is at least one.

## [0.9.0] — 2026-09-24

### Fixed

- **SL105 and SL106 now see what traits do.** A private method called only
  from a trait the class uses (often one the trait declares
  `abstract private`), or a constructor property read only by such a trait
  (`$this->id` in a `HasIdentity::getId()`), was reported as dead. Traits
  used through other traits count too. A trait that reaches members
  dynamically makes both rules skip the class, as they already do for the
  class itself. Only traits inside the analysed paths are resolved. Reported
  in #18, where this accounted for 110 of 113 findings from the two rules.
- **SL204 no longer flags a memoized lookup.** `$units[$code] ??= Unit::where(...)->first()`
  queries once per distinct key, not once per iteration, which is the
  in-memory lookup the rule's own suggestion asks for. Only `??=` into an
  array key or a property counts; a plain `=` is still reported.
- **SL107 rates parse-or-reject helpers low instead of high.** A catch whose
  only statement is `return false` in a `bool` function, or `return null` in
  a nullable one when a specific exception was caught, answers with the value
  the signature already uses for "no" (`isValidPhoneNumber(): bool`,
  `verify(string $token): ?Payload`, a validation rule's `passes()`). These
  are still reported, but at `low`, so they no longer fail CI. A broad
  `catch (Throwable)` returning null stays `high`, since there null cannot be
  told apart from a crash. A project that configured SL107 lower than `low`
  keeps its own setting. Reported in #18.
- **SL207 counts collaborators, not the data a class is built from.** A
  constructor parameter typed as an enum, a value object (a `readonly` class,
  or one built only from readonly promoted properties), an Eloquent model, a
  date (`Carbon`, `DateTimeImmutable`, ...) or a `Collection` no longer counts
  as a dependency. A domain entity taking its fields was being reported as an
  over-coupled service: all 38 SL207 findings in #18 were entities or value
  objects.
- **A lookup table is one decision, not one per row.** A `match` whose arms
  all map constants to constants (literals, constants, enum cases, arrays of
  those), or a `switch` whose cases only `return` such a value, adds 1 to
  cyclomatic complexity instead of 1 per arm, and its lines no longer count
  towards SL101's size signal. An enum's `label()` with 53 cases was reported
  at complexity 54, and a calling-code lookup at 206. A `match` with any arm
  that computes something is counted as before. This affects SL101 and SL201.
- **SL301 leaves framework conventions out of a layer stack.** A class that
  extends an Eloquent `Factory`, a Fractal `TransformerAbstract`, an API
  resource, a form request, a seeder or a service provider -- directly or
  through a project base class -- is one class the framework expects per
  concept, not indirection the team added. It no longer counts as a layer or
  gets reported. The list is SL301's new `convention_bases` option, where a
  project can add its own pattern bases, such as a data mapper base. 89 of
  the 110 SL301 findings in #18 were factories and transformers.
- **SL303 names the implementation in full when it shares the abstraction's
  short name.** `Domain\ChargeRepository` implemented by
  `Infrastructure\ChargeRepository` was reported as "implemented only by
  ChargeRepository", which reads as an interface implementing itself.

### Changed

- **Test directories are excluded by default.** `tests` and `Tests` join the
  default `exclude` list, so module layouts such as `Modules/Billing/Tests`
  are skipped too. Long setup, many public methods and near-identical bodies
  are what good tests look like, and they made up 60 percent of the first-run
  findings in #18. Projects with their own `exclude` list keep it as is.
- **Module layouts' migrations, factories and seeders are excluded by
  default.** `Database/Migrations`, `Database/Factories` and
  `Database/Seeders` join the list, so nwidart modules
  (`Modules/Billing/Database/Migrations`) are skipped like
  `database/migrations` already was. Chunked backfills in module migrations
  were the largest share of SL204 findings in #18.

## [0.8.0] — 2026-09-23

### Added

- **`sloppy rules --format=boost` writes the rules as a Laravel Boost
  guideline.** Boost regenerates `CLAUDE.md` and `AGENTS.md` and suggests
  keeping them out of git, so a Sloppy block merged there was never committed
  and never reached the rest of the team. The new format writes
  `.ai/guidelines/sloppy.blade.php`, which Boost composes into every agent file
  it manages. It is the default where Boost is installed (`laravel/boost` in
  `composer.json`, or a `boost.json`); `--format=claude` still writes
  `CLAUDE.md`. The rules are wrapped in `@verbatim`, since Boost renders
  guidelines through Blade, and a guideline Sloppy did not write is replaced
  only with `--force`. ([#14](https://github.com/Heyosseus/sloppy/issues/14))

### Fixed

- **`SL303` no longer reports abstractions that declare nothing.** An empty
  abstract class or interface has no signature to duplicate, so the cost the
  rule measures is not there. The visible case was Laravel 11's scaffolded
  `abstract class Controller {}`, reported on any fresh application with a
  single controller. Declaring one method, property, constant or trait brings
  it back under the rule. ([#13](https://github.com/Heyosseus/sloppy/issues/13))

## [0.7.0] — 2026-09-15

Signals from the tools you already run.

Sloppy now reads what PHPStan, Psalm and your test suite leave behind and
treats it as evidence about a change. It still never runs them: `sloppy fix`
remains the only place this package starts another process, and it starts one
to fix, not to measure.

### Added

- **`SL501` Unexplained Suppression** — flags `@phpstan-ignore`,
  `@psalm-suppress`, `@mago-expect`, `@noinspection`, `phpcs:ignore` and
  `@SuppressWarnings` written with no reason after them. A suppression with a
  reason is a reviewed decision someone can check and eventually delete; a bare
  one is permanent, because no later reader can tell what it was protecting.

  Deliberately not a density rule. A file carrying ten suppressions that each
  name a bad vendor stub is a file whose author did the work, and one carrying
  a single bare `@psalm-suppress` is not — density scores those backwards.

  The vocabulary lives in `sloppy.rules.SL501.annotations`, so an analyser we
  have not heard of needs a config line rather than a release.

- **`SL502` Baseline Growth** — reports entries a change added to
  `phpstan-baseline.neon` or `psalm-baseline.xml`. A baseline that grew inside
  a diff is not a type error; it is a record that someone chose not to fix one,
  and nothing else in the ecosystem reports it because reporting it requires
  knowing what changed.

  Compares entry counts per source path rather than file text, so
  `phpstan --generate-baseline` reports nothing however different the bytes
  are. Introducing a baseline for the first time reports once rather than once
  per file. A baseline that shrank reports nothing.

  The finding lands on the source file the entries were added for, with the
  baseline named in its metrics — a finding parked in a config file is a
  finding nobody sees.

- **Coverage-aware reading order** — pass `--coverage=build/logs/clover.xml` to
  `sloppy diff` or `sloppy review`, set `sloppy.risk.coverage`, or let Sloppy
  find the usual build paths. Clover and Cobertura are both read, told apart by
  content rather than filename. A changed file the tests never execute ranks
  1.5x an identical covered one, and `--explain-risk` shows the new factor
  alongside the others.

- `Contracts\Detector`, the metadata half of `Contracts\Rule`, so a finding can
  come from something that is not a rule. Every existing rule satisfies it
  unchanged.

### Changed

- `RiskConfiguration` gains `exposure_weight` and `coverage`. Both live in the
  `risk` block because risk is the only thing either one feeds, and `SL502`'s
  watched baseline files are a rule option beside `SL501`'s vocabulary. Adding
  them as top-level keys instead pushed `Configuration` past the god-class
  threshold `SL102` reports — the tool caught it in its own self-check, and
  moving each setting next to what reads it was the fix.

### Notes

- **Two numbers, two rules, and they have not changed.** `SL501` moves the slop
  score, because a suppression sitting in a file is a property of the tree.
  `SL502` and coverage never touch it: `SL502` exists only relative to a base
  revision, and a coverage report is a build artefact that may be absent or
  stale. Letting either in would make `sloppy scan` and `sloppy diff main`
  disagree about the same working tree.
- `SL502` still counts against `--fail-on`, because a build may legitimately
  refuse a change that silenced three errors.
- Coverage staleness is reported by `sloppy health` and `--explain-risk` — the
  report's path and the date it was written — and never acted on. A staleness
  heuristic would be a number that cannot show its arithmetic.
- No new runtime dependencies. `ext-xml` is suggested rather than required;
  without it coverage is skipped and nothing else changes.

## [0.6.0] — 2026-09-15

Watch while you work, and run anywhere.

### Added

- **`sloppy watch` / `php artisan sloppy:watch`** — the score, the breakdown
  and what to read first, left on screen and redrawn as files change. Put it
  beside the agent writing the code and the cost of a change shows up while the
  change is still being made, rather than the next time somebody remembers to
  run a command.

  A tick is not an approximation. It re-parses only the file that moved and
  then runs every rule over the whole project, so its numbers are identical to
  `sloppy scan` of the same tree — reporting on fewer files would be faster and
  would make `SL303` and the duplication rules quietly wrong. Watching is done
  by polling modification time and length, so no extension is needed and it
  behaves the same everywhere; `r` re-reads the tree for the rare edit that
  changes neither.

  `↑↓` moves through the findings, `↵` opens the selected one at its line in
  `$VISUAL` or `$EDITOR`, and `q` leaves with the terminal exactly as it was
  found. Where a terminal cannot give up a single keypress — PHP has no way to
  put a Windows console into raw mode — the dashboard still redraws on every
  change and says `Ctrl+C to quit` rather than offering keys that would never
  arrive.

- **A standalone `sloppy.phar`, attached to every release**, alongside
  `composer global require heyosseus/sloppy`. Both run against a project that
  has never heard of Sloppy: it finds the project by walking up to the nearest
  `composer.json` and analyses the PSR-4 roots declared there when no
  `config/sloppy.php` says otherwise. `sloppy fix` still drives Rector and
  Pint, because it resolves them from the analysed project rather than from
  its own install. The release build refuses to publish a binary whose version
  disagrees with its tag.

- `Analyzer::analyzeParsed()`, for analysing files something else has already
  parsed. This is the seam `watch` rests on; `analyze()` and `analyzeSources()`
  are unchanged and still funnel through the same code.

## [0.5.1] — 2026-09-14

### Fixed

- **A scan of a large project no longer takes minutes.** `SL111` searches the
  whole project for drifting bodies, and that search is a property of the
  project rather than of the file being reported on -- but rules are handed
  one file at a time, so the search was being redone for every file and its
  result thrown away for all but one. On a 1,075-file application that was
  0.28s of work repeated 1,075 times: the rule alone took 5m57s, and a full
  scan around a quarter of an hour. The corpus is now searched once per
  project and handed to each file pre-sorted, which takes the same rule to
  6.6s and the full scan to 18.4s, reporting exactly the same findings.

  Nothing about what `SL111` reports has changed: same pairs, same family
  sizes, same truncation point, same order. If you turned the rule off to get
  your scan back, turn it on again.

## [0.5.0] — 2026-09-14

Automation: run everywhere, fix what can be fixed, and teach the agents.

Sloppy could tell a person what to read. This release is about the places
nobody reads: a pipeline, a test suite, a dashboard, and the model writing the
next file.

### Added

- **`sloppy ci` / `php artisan sloppy:ci`.** One command for a pipeline step,
  which reads the environment rather than asking you to describe it: on a
  GitHub pull request it compares against the target branch and annotates the
  changed lines; on a push it analyses the project; on GitLab it writes a Code
  Quality report the merge request widget renders; anywhere else it prints the
  ranked console report. It also fills the GitHub job summary and writes
  `score`, `score-delta`, `findings`, `new-findings`, `resolved-findings`,
  `status` and `mode` to `$GITHUB_OUTPUT`.

- **An official GitHub Action**, shipped as this repository's `action.yml`, so
  adding Sloppy to a pipeline is three lines of YAML. It fetches the base
  branch itself, because `actions/checkout` clones one commit and diff mode
  without the target branch reports inherited debt as if the author wrote it.

- **A GitLab CI template** at `resources/ci/gitlab-ci.yml`, includable by URL,
  producing a `codequality` artifact.

- **`--format=gitlab`**, the Code Climate issue format GitLab reads, with our
  severities and categories mapped onto the ones it defines.

- **`sloppy fix` / `php artisan sloppy:fix`.** Sloppy does not rewrite code:
  Rector and Pint already do that well. What Sloppy knows that they do not is
  which of their rules this project currently needs, so it generates a Rector
  configuration scoped to the files that actually have findings, runs it,
  formats the result with Pint, and reports what is left for a person. Neither
  tool is a dependency; without them you get the configuration and a message
  naming the one to install. `--format=rector` writes the configuration alone.

- **A Pest plugin**: `expectCleanSloppyDiff('main')`,
  `expectCleanSloppyScan()` and `expectSloppyScoreAtLeast(80)`. New debt now
  fails in the same red-green loop as everything else about the code, and the
  failure names the file, the line and the rule.

- **`sloppy health` / `php artisan sloppy:health`**, and the cached snapshot
  behind it. Analysing a project takes seconds and a dashboard has
  milliseconds, so every "how are we doing?" surface reads one cached
  snapshot, configured under a new `sloppy.health` key.

- **A Filament plugin and widget** (`SloppyPlugin::make()`), rendering the
  score, the severity breakdown and what to read first. Filament is not a
  dependency: the classes are only loaded by a panel that asks for them.

- **NativePHP support** through `DesktopHealth`: a menu-bar label, the menu
  behind it, and a notification for a score that dropped -- strings your app
  hands to NativePHP, with no NativePHP classes referenced here.

- **`sloppy rules` / `php artisan sloppy:rules`**, which writes this project's
  rules into `CLAUDE.md`, `.cursorrules`, `AGENTS.md`,
  `.github/copilot-instructions.md`, `.windsurfrules`, Markdown or JSON. Each
  rule gets a line an agent can act on, and the file describes the configured
  run rather than the shipped defaults. Existing files keep everything outside
  a marked block, so a team's own notes survive.

- **`sloppy guide` / `php artisan sloppy:help`**, which explains what each
  command is for and when to reach for it, grouped by the moment rather than
  listed flat, and naming both surfaces for every command so a reader who
  found one of them can find the other. Symfony's `sloppy help <command>` and
  Artisan's `--help` still answer the question after that one, which is what
  the options are. It is named `guide` on the standalone binary because
  `help` there already belongs to Symfony.

- **An MCP server**: `vendor/bin/sloppy-mcp`, `sloppy mcp` or
  `php artisan sloppy:mcp`, offering `sloppy_scan`, `sloppy_diff`,
  `sloppy_rules` and `sloppy_health` over stdio. The server's own instructions
  tell the agent to check its work before reporting a task finished, which is
  the moment a finding is cheapest to fix.

### Changed

- `Git::attempt()` answers "no" instead of throwing when the configured project
  directory does not exist, so a path typo produces a message rather than a
  stack trace.
- Baseline read and write failures are reported in this package's words rather
  than as a PHP warning followed by a vaguer error.
- The framework detector also recognises NativePHP and Filament, for the
  integrations that ask.
- The line-coverage floor is now 100%, and the suite covers every branch the
  package can reach.

## [0.4.0] — 2026-09-13

Attention: what to read first, and where to read it.

Sloppy could already tell you what was wrong. It could not tell you what to
look at, and it could only tell you in its own terminal. Both of those change
here.

### Added

- **A risk model, and `--explain-risk` to show its arithmetic.** Risk answers a
  different question from the slop score. The score measures quality --
  density-normalised, baseline-compatible, about the codebase. Risk measures
  attention -- absolute, change-aware, about the reader's next ten minutes:

      risk = severity_weight x (confidence / 100) x novelty x proximity x reach

  Nothing here moves a score or a baseline entry. Every factor is configurable
  under a new `sloppy.risk` key, every default is defended in the config file,
  and `--explain-risk` prints the whole derivation for every finding:

      10.0 (high) x 0.91 (confidence) x 1.00 (novelty unknown) x 1.00 (whole file) x 2.45 (27 usages) = 22.27

  The criticism this answers is a fair one: a tool that weights findings owes
  the reader its weights. A number you can watch the tool derive is not a magic
  number.

- **Blast radius.** How many files reach the code a finding sits in, resolved
  from the finding's own location through the project index and added to every
  finding's metrics. No rule had to be changed for this: the class declaration
  enclosing a reported line is what callers depend on, which is true for all
  twenty-four rules without any of them saying so. Reach is **logarithmic** --
  a class with two hundred callers is not two hundred times more urgent than
  one with a single caller, and the tenth caller costs less new attention than
  the first. A finding whose subject cannot be resolved gets **no**
  `blast_radius` at all, because zero would read as "nothing uses this", which
  is a claim rather than a measurement.

- **`sloppy review [base]`** (`php artisan sloppy:review`). `sloppy:diff`
  answers "did this change make it worse?". This answers the question a
  reviewer has immediately afterwards: of everything the change touched, what
  deserves reading? Findings are ranked by risk and the changed files fall into
  three tiers, so a reviewer knows where to stop:

      161 file(s) changed, 12,198 lines  ·  score 5 → 12 (+7)

      Read in this order
        1. app/Actions/Product/CloneProductToTenantAction.php   risk 70.5
             new SL111 Copy-Paste Drift  (76%, risk 9.9, in hunk)
             new SL102 God Class  (73%, risk 9.5, in hunk)
             and 9 more in this file, 2 x SL104, 7 x SL204
      Skim
        4 file(s), risk under 5
      No attention needed
        132 file(s) changed with no findings
      Resolved by this change
        3 finding(s) no longer reported

  Same analysis, same score, same exit code as `sloppy:diff` -- only the
  presentation differs, so adopting the reading order changes no build outcome.

  **`in hunk` is the part nothing else can do.** A god method a change created
  and a god method it merely stood next to are not the same finding, and no
  other tool can distinguish them because no other tool has the diff's hunks in
  hand at rule time. Proximity weights the first at 1.0 and the second at 0.3.

- **`--format=sarif`** -- SARIF 2.1.0, and the answer to "why not SonarQube"
  without running a dashboard. Findings land in GitHub code scanning, VS Code
  and the JetBrains IDEs for the cost of one serialiser: no server, no
  database, no second tool to keep alive. The hard half was already built --
  GitHub deduplicates findings across runs using `partialFingerprints`, and it
  wants exactly what `Finding::identity()` already is, a stable hash of rule,
  file and fingerprint that deliberately excludes the line number.

- **`--format=github`** -- GitHub Actions workflow commands, so findings appear
  beside the lines they are about in the review the author is already looking
  at. A report in a CI log is a report nobody reads.

- **`--format=markdown`** -- a pull-request comment body. On `scan` it is
  risk-ordered with five findings above the fold and the rest inside a
  collapsed block; on `review` it is the same three-tier reading order,
  rendered as markdown rather than for a terminal. Short enough to read in the
  timeline, complete enough to be the only comment needed.

  The review renderer branches on decoration alone -- headings, emphasis and
  code spans -- so the tier logic exists once. A second formatter duplicating
  that structure is precisely what `SL111` would report about it.

- **`risk` on every finding in JSON output**, plus `risk_factors` and
  `risk_arithmetic` under `--explain-risk`. Additive within schema 1, which the
  contract permits: a consumer reading the documented keys is unaffected, and
  one that wants to rank findings no longer has to reimplement the model.

### Changed

- `--format` now accepts `console`, `json`, `sarif`, `markdown` and `github`.
  An unknown value still exits `2` naming the value.
- The shared option-reading and run logic behind `diff` and `review` moved into
  a base class on each surface. `SL111` reported the two command pairs as
  near-duplicates of each other, which they were; the rule found this in code
  written minutes earlier, and the fix was the one the rule suggested.

### Fixed

- **`sloppy --version` reported `0.2.0` throughout the `0.3.0` release.** The
  binary carried its own hardcoded copy of the version, and the SARIF report
  identified the tool as `dev` regardless. Both now read one constant,
  `Sloppy::VERSION`, so the number a user sees, the number a SARIF consumer
  records and the number in this file cannot disagree again.

### Known limitations

- **Risk ranks; it does not prove.** Every factor is a measurement, but the
  product is a reading order rather than a verdict, and a finding low in the
  order is not a finding the tool has cleared. Risk exists because a list of
  twenty-four findings in no particular order gets read top to bottom or not at
  all -- not because the first one is always the one that matters most.

- **Blast radius counts static references inside the analysed paths.** A class
  reached only through a container binding, a string class name, a route file
  or a view outside the configured paths is counted as unreached, so reach can
  understate. It never invents the other direction: an unresolvable subject is
  reported as unmeasured rather than as zero.

- **Novelty and proximity are only available where a diff is.** `scan` has
  neither, so both default to 1.0 and risk there is severity, confidence and
  reach alone. The full model needs `review` or `diff`.


## [0.3.0] — 2026-09-12

Copy-paste drift: the space between "identical" and "different" that
structural duplication detection cannot see.

### Added

- **`SL111` Copy-Paste Drift.** Rector, PHPStan, SonarQube and Mago each
  report either identical code or different code; copy-paste bugs live in
  between — five handlers copied from one another with a guard added to four
  of them and forgotten in the fifth. `SL111` finds two kinds of near-miss
  among a class's own methods, both bounded by the same edit-distance search
  `SL104` already indexes:
  - **Shape drift** — two bodies whose token streams sit within a bounded
    Levenshtein distance of each other but are not identical, the way a
    missing `if` guard or a flipped comparison looks.
  - **Masked drift** — bodies that hash *identically* under `SL104`'s
    structural hash (which masks literals and constructed class names) but
    disagree at exactly one masked position where every other sibling agrees
    — `new StripeGateway()` in four copies and `new PaypalGateway()` in a
    fifth is invisible to hashing and is exactly what this path is for.

  Every finding names both bodies, the token distance, and where the two
  streams first part company, and rises in confidence with the size of the
  agreeing family: two near-identical methods are as likely to be
  independent as copied, four agreeing and a fifth diverging is an argument.
  The rule never claims the majority is correct, only that it is more likely
  to be — confirming which copy is right is left to the reader.

- **`sloppy.rules.SL111`**, three options: `min_statements` (default `8`,
  the same floor `SL104` uses — bodies shorter than this are too small for
  near-identity to mean anything), `max_token_distance` (default `28`) and
  `max_divergence_ratio` (default `0.08`). Swept on a 1,075-file,
  73,737-line Laravel application at an 8-statement floor: of 552 eligible
  bodies and 9,526 window comparisons at a budget of 24, 14 pairs survived,
  8 of them exact duplicates that are `SL104`'s to report. Of the rest, 3
  sat at 0.5–1.2% divergence, 2 at 1.9–5.6%, and one 103-token pair at
  23.3% was the only false positive found — which is why the ratio default
  stays at a tight 0.08 rather than the 0.12 that would additionally admit
  two weaker, plausible-but-unconvincing pairs. Widening the budget past 28
  bought nothing on that corpus (unchanged at 32, 40, 56 and 80, up to
  95.92s at 80) except at 28 itself, which surfaces one more genuine pair a
  budget of 24 misses — two OTP methods 28 tokens and 3.37% apart — for
  0.28s. At the shipped defaults the same application produced exactly six
  findings with no truncation in 0.29s: two payment-refund actions 11
  tokens apart, three slug helpers 1 token apart, `Product` and
  `Category::resolveRouteBindingQuery` 3 tokens apart, two auth listeners 18
  apart, and the two OTP methods 28 apart.

- **`max_comparisons`** (default `5,000`), a hard ceiling on how many
  band-limited comparisons one search may perform, counted only from
  comparisons that reach the edit-distance matrix — the expensive step —
  and never from the candidate pairs the length, hash and token-frequency
  gates reject first. That distinction matters by a factor of 32: on the
  measured application, 11,063 candidate pairs reach those gates and only
  348 get past them to the matrix, so a ceiling counted honestly never binds
  on code that looks like that (348 needed of 5,000, 0.29s), while it does
  bound the pathological case those gates cannot help with — a corpus of
  many equal-length near-clones, where the frequency gate rejects nothing.
  600 such bodies would otherwise run 179,700 comparisons; capped, the same
  search stops in a fraction of a second. A search that hits the ceiling
  says so: every finding it still emits that run carries
  `"search_truncated": true` in its metrics, because this package's position
  is that analysis the user silently lost is worse than analysis they were
  told about.

### Changed

- **Progress and status messages now go to stderr, not stdout.** A new
  `notice()` channel on the runner output port carries anything written for the
  reader rather than for the report, and both the Artisan and standalone
  surfaces route it to standard error. Console output looks the same; what
  changes is that redirecting stdout now gives you the report and nothing else.

- **`sloppy scan` names the project root and configuration source on every
  run.** The standalone binary discovers its root by walking up for a
  `composer.json` and its configuration from either a config file or the PSR-4
  autoload entries, and a reader whose scan covered the wrong tree previously
  had no way to see that. Printed through `notice()`, so it never touches a
  machine-readable report.

### Fixed

- **`--format=json` was not machine-readable when a baseline existed.** The
  "N existing finding(s) hidden by ..." notice was written to stdout ahead of
  the JSON document, so `sloppy scan --format=json | jq` failed to parse on any
  project with a baseline file. Reproduced on a 1,075-file application, where
  14 hidden findings produced 14 bytes of prose in front of the report. The
  notice now goes to stderr. This was present since `0.1.0`, where the same
  message was written through Laravel's console components for the same reason.

- **`--rule` silently hid framework-skipped rules.** `RuleRegistry::only()`
  built its narrowed registry without carrying the skipped list forward, so
  `sloppy scan --rule=SL101` on a project with no Laravel reported nothing
  about the ten `SL2xx` rules that had been left out — the exact silence the
  `0.2.0` skipped-rule reporting exists to prevent.

- **"No rules are enabled" blamed the wrong thing.** It sent the reader to
  check `sloppy.rules` and their `--rule` filter even when both were fine and
  the real cause was every rule having been framework-gated away. It now says
  how many rules were skipped and which `sloppy.framework` setting did it.

- **`SL104`'s suggestion advised parameterising a bug into permanence.** Two
  bodies that hash identically may differ only in the values they use
  because one of them is *wrong*, not because they are a deliberate
  variation — and the old suggestion ("a single method taking those values
  as parameters usually replaces both") did not distinguish the two. It now
  reads: "If the two really do the same work, keep one and call it from
  both places. If they differ only in the values they use, check those
  values against each other first — `SL111` reports the ones that look like
  an unfinished copy — and parameterise only once you are satisfied every
  difference is deliberate."

### Known limitations

- **Copy-paste drift is a heuristic about probability, not proof.** A body
  that agrees with three siblings and disagrees with a fourth is more likely
  to be the odd one out than the other way round; it is not certain, and
  `SL111` never reports it as certain. Confirming which copy is right is a
  reading task `SL111` hands to the reader, not one it does for them.
- **The comparison ceiling can truncate a search.** `max_comparisons` bounds
  wall-clock time against a corpus of many same-length near-clones —
  precisely the shape ordinary applications do not have and generated or
  heavily templated code sometimes does. A run that hits it did not see
  every pair, and says so: a truncated search's findings carry
  `"search_truncated": true` in their metrics rather than reporting a
  quietly incomplete result as a complete one.

## [0.2.0] — 2026-09-12

Sloppy stops being a Laravel-only tool.

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

[Unreleased]: https://github.com/heyosseus/sloppy/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/heyosseus/sloppy/compare/v0.9.0...v1.0.0
[0.9.0]: https://github.com/heyosseus/sloppy/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/heyosseus/sloppy/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/heyosseus/sloppy/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/heyosseus/sloppy/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/heyosseus/sloppy/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/heyosseus/sloppy/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/heyosseus/sloppy/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/heyosseus/sloppy/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/heyosseus/sloppy/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/heyosseus/sloppy/releases/tag/v0.1.0
