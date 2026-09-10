<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/logo-dark.svg">
    <img alt="Sloppy" src=".github/assets/logo-light.svg" width="430">
  </picture>
</p>

<p align="center">
  <b>Static analysis for the code-quality patterns AI coding agents leave behind.</b><br>
  Deterministic, local, Laravel-aware. No model, no API key, no network.
</p>

<p align="center">
  <a href="https://github.com/heyosseus/sloppy/actions/workflows/tests.yml"><img alt="tests" src="https://github.com/heyosseus/sloppy/actions/workflows/tests.yml/badge.svg"></a>
  <a href="https://packagist.org/packages/heyosseus/sloppy"><img alt="packagist" src="https://img.shields.io/packagist/v/heyosseus/sloppy.svg"></a>
  <a href="https://packagist.org/packages/heyosseus/sloppy"><img alt="downloads" src="https://img.shields.io/packagist/dt/heyosseus/sloppy.svg"></a>
  <img alt="php" src="https://img.shields.io/packagist/dependency-v/heyosseus/sloppy/php.svg">
  <a href="LICENSE.md"><img alt="license" src="https://img.shields.io/packagist/l/heyosseus/sloppy.svg"></a>
</p>

<p align="center">
  <img alt="php artisan sloppy" src=".github/assets/scan.svg" width="860">
</p>

Sloppy reads your PHP with a real parser and reports the shapes that turn into
maintenance cost: god methods, swallowed exceptions, likely N+1 queries,
business logic in controllers, abstractions that never earned their keep. It
ships 23 rules, a git-diff review mode, a baseline for existing projects, and
JSON output for CI.

Every screenshot on this page is real output from the command in its caption.

## It is not an AI detector

Nobody can reliably prove authorship from source code, and a tool that claimed
to would be selling you a coin flip with a progress bar. Sloppy never guesses
who or what wrote a line. It detects **slop** — patterns that correlate with
fast, unreviewed output and with technical debt generally. A 200-line
controller action that writes to four tables, calls a payment API and swallows
a `Throwable` is a problem whether a person, an agent or a pair of them wrote
it at 3am.

So every number is about the *code*, never about its author:

| Sloppy says | It means | It does **not** mean |
| --- | --- | --- |
| `Confidence: 88%` | How sure the analyser is that the pattern it describes is really present | Any probability that the code was AI-generated |
| `Score: 67/100` | A code-quality risk measure for the analysed paths | "67% of this code is AI-generated" |
| `SL107 Swallowed Exception` | This catch block does nothing observable with the failure | An accusation about who wrote it |

Sloppy is deterministic and local. Your source never leaves the machine, and
the same code always produces the same report — which is what makes it usable
as a gate.

## Install

```bash
composer require --dev heyosseus/sloppy
```

The service provider is discovered automatically. Publish the config when you
want to tune it:

```bash
php artisan vendor:publish --tag=sloppy-config
```

Requirements: **PHP 8.3+**, **Laravel 12 or 13**.

## Contents

- [Scan a project](#scan-a-project) · [Anatomy of a finding](#anatomy-of-a-finding)
- [Review a change](#review-a-change) · [Adopt on an existing codebase](#adopt-on-an-existing-codebase)
- [Does it just complain about everything?](#does-it-just-complain-about-everything)
- [Slop score](#slop-score) · [Severity and confidence](#severity-and-confidence)
- [Rules](#rules) · [Configuration](#configuration) · [Custom rules](#custom-rules)
- [JSON output](#json-output) · [Exit codes](#exit-codes) · [CI](#ci)
- [What Sloppy is not](#what-sloppy-is-not) · [False positives](#false-positives)

## Scan a project

```bash
php artisan sloppy                  # the configured paths
php artisan sloppy --path=app/Services
php artisan sloppy --rule=SL101 --rule=SL107
php artisan sloppy --min-confidence=80
php artisan sloppy --explain        # include each rule's "why this matters"
```

The screenshot at the top of this page is that command run against a small
application written badly on purpose. It ships as `tests/Fixtures/Sloppy`, so
you can reproduce it.

### Anatomy of a finding

One file, seven findings, the whole report:

![php artisan sloppy --path=app/Services/ReportingService.php](.github/assets/report.svg)

Every finding carries the same five things: **where** it is, **what** was
measured, **how sure** the analyser is, **why** the pattern is often a problem,
and **what to do** about it. The last one is the point — a finding you cannot
act on is noise with a line number.

## Review a change

The most useful command if you work with coding agents:

```bash
php artisan sloppy:diff             # working tree vs HEAD
php artisan sloppy:diff HEAD~1      # vs the previous commit
php artisan sloppy:diff main        # everything this branch changed
```

It separates what your change *introduced* from what it merely *inherited*, and
only new findings can fail the build.

![php artisan sloppy:diff main](.github/assets/diff.svg)

Three things worth knowing about how it works:

- **It reviews the working tree, not just commits.** Uncommitted and untracked
  files are included, so an agent's new class is reviewed before it is
  committed rather than after.
- **Findings are matched by fingerprint, not by line number.** Adding an import
  at the top of a file does not turn every existing finding in it into a new
  one.
- **Cross-file rules still see the whole project.** Rules only *run* on changed
  files, but the project index is built from everything, so `SL303` can still
  tell that an interface has exactly one implementation in a file the diff
  never touched.

## Adopt on an existing codebase

Turning Sloppy on for the first time should not mean fixing everything first.
Record what is there today, commit the file, and gate on what comes next:

```bash
php artisan sloppy:baseline
```

![php artisan sloppy:baseline, then php artisan sloppy](.github/assets/baseline.svg)

Baseline entries are keyed on rule, file and a rule-supplied fingerprint —
usually a class and member name — and deliberately **not** on line numbers, so
a baseline survives ordinary editing. If a finding occurs more often than the
baseline recorded, the extra occurrences are new.

`--force` replaces an existing baseline; `--rule=` baselines only some rules.

## Does it just complain about everything?

That is the failure mode of every analyser, so Sloppy runs its whole rule set
over its own source in CI:

![php artisan sloppy --path=src](.github/assets/self.svg)

One finding, and it is a real one: `NodeHelper` is a large class. Splitting it
into five so that rules import three of them to ask three questions would be
exactly the ceremony this package exists to discourage, so the decision is
recorded in `tests/Feature/SelfCheckTest.php` with its reasoning — and that
test fails on any *new* finding about Sloppy's own code. Four others it found
were genuine, and they were fixed.

The other half of the answer is `tests/Fixtures/Good`: deliberately ordinary
Laravel code, with a test asserting that all 23 rules report **zero** findings
on it at a score of 100.

## Slop score

A single deterministic number for the analysed paths:

<p align="center">
  <img alt="Score bands: 0-39 severe slop, 40-59 sloppy, 60-74 needs attention, 75-89 healthy, 90-100 clean" src=".github/assets/bands.svg" width="760">
</p>

```text
penalty  = Σ  weight(severity) × confidence / 100
units    = max(1, analysedLines / lines_per_unit)
density  = penalty / units
coverage = min(1, Σ affectedLines / analysedLines)
score    = 100 − min(100, density × penalty_multiplier × (1 + coverage))
```

- Step 1 makes a finding we are half sure about cost half as much.
- Step 2 normalises by codebase size, so a large application is not punished
  merely for being large.
- Step 4 doubles the penalty when findings blanket the codebase and barely
  moves it when they are localised.

Default weights are `critical: 20`, `high: 10`, `medium: 4`, `low: 1.5`,
`info: 0.5`. Weights, the multiplier, `lines_per_unit` and the band thresholds
are all configurable under `sloppy.score`. Same input, same score — always.

Because the score is a density, a small codebase full of findings bottoms out
quickly: the 472-line demo application in the first screenshot scores 0. That
is the arithmetic working, not a verdict on 472 lines of anything.

## Severity and confidence

They answer different questions, and both appear on every finding.

**Severity** — how much this matters if it is real. `critical`, `high`,
`medium`, `low`, `info`. Set by the rule, overridable per rule in config. This
is what `fail_on` compares against.

**Confidence** — how sure the analyser is that the pattern it describes is
actually present, 0–100. A 300-line method is a measurement, so confidence is
high. A possible N+1 depends on eager loading the analyser may not be able to
see, so confidence is lower. Abstraction inflation is a heuristic, so it never
reports above 78.

No rule ever reports 100% confidence. These are heuristics, and a heuristic
that claims certainty is lying.

## Rules

23 rules ship in v0.1. Every one has tests proving both that it fires on the
pattern and that it stays quiet on ordinary Laravel code.

### PHP and general

| ID | Rule | Severity | Category | What it looks for |
| --- | --- | --- | --- | --- |
| `SL101` | God Method | High | Complexity | Methods that are excessively long, branchy or chatty across several independent measurements. |
| `SL102` | God Class | High | Complexity | Classes that are large across several dimensions at once: size, method count, injected dependencies and the number of collaborators they talk to. |
| `SL103` | Excessive Nesting | Medium | Complexity | Methods whose conditionals, loops and try blocks nest deeper than the configured limit. |
| `SL104` | Duplicate Logic | Medium | Duplication | Methods whose bodies are structurally identical to another method in the project. |
| `SL105` | Dead Private Method | Medium | Dead code | Private methods with no visible caller anywhere in the declaring file. |
| `SL106` | Unused Constructor Dependency | Medium | Dependencies | Constructor-injected dependencies that are never read anywhere in the class. |
| `SL107` | Swallowed Exception | High | Error handling | Catch blocks that neither rethrow, report, log nor otherwise react to the failure they caught. |
| `SL108` | Redundant Condition | Low | Readability | Conditions re-tested immediately inside themselves, repeated within one if/elseif chain, duplicated across a boolean operator, or written as a literal true/false. |
| `SL109` | Narrative Comment | Low | Readability | Short comments whose every meaningful word already appears in the statement directly below them. |
| `SL110` | Defensive Programming Noise | Low | Readability | A method that guards the same subject the same way twice, with the same outcome and no reassignment in between. |

### Laravel

| ID | Rule | Severity | Category | What it looks for |
| --- | --- | --- | --- | --- |
| `SL201` | Business Logic In Controller | High | Laravel | Controller actions that combine several business concerns: database writes, calculations, transactions, outbound calls and branching. |
| `SL202` | Inline Validation | Low | Laravel | Large inline validation rule sets inside controllers, where a FormRequest would carry them better. |
| `SL203` | Possible N+1 | Medium | Performance | Relationship access inside a loop where the iterated collection does not appear to eager load that relation. |
| `SL204` | Query Inside Loop | Medium | Performance | Query builder or Eloquent queries executed inside a loop. |
| `SL205` | Collection Instead Of Database Query | Medium | Performance | A full table load followed immediately by a collection operation the database could have performed. |
| `SL206` | Excessive Controller Dependencies | Medium | Dependencies | Controllers whose constructor injects more collaborators than the configured limit. |
| `SL207` | Excessive Service Dependencies | Medium | Dependencies | Service, action and manager classes whose constructor injects more collaborators than the configured limit. |
| `SL208` | Direct External API Call | Medium | Laravel | Outbound HTTP requests made directly from controllers, models, form requests or middleware. |
| `SL209` | Model Doing Too Much | Medium | Laravel | Eloquent models that make outbound calls, dispatch notifications or jobs, or contain long business workflows. |
| `SL210` | Suspicious `Model::all()` | Medium | Performance | `Model::all()` whose result is iterated in the same method, or which is called from inside a loop. |

### Architecture

These three are **advisory**. They report that an abstraction is not earning
its keep *for the current usage*, which is a judgement about today's code and
not a rule against repositories or interfaces.

| ID | Rule | Severity | Category | What it looks for |
| --- | --- | --- | --- | --- |
| `SL301` | Abstraction Inflation | Medium | Architecture | Concepts wrapped in several layers where at least one layer is trivial, singly implemented or singly used. |
| `SL302` | Empty Wrapper Class | Medium | Architecture | Classes whose public methods almost all forward their arguments unchanged to a single injected collaborator. |
| `SL303` | Single-Use Abstraction | Low | Architecture | Small interfaces and abstract classes that have exactly one implementation and at most one calling file. |

## Configuration

Everything lives in `config/sloppy.php`. There is deliberately no second
configuration format to keep in sync.

```php
return [
    'enabled' => env('SLOPPY_ENABLED', true),

    'paths' => ['app'],

    'exclude' => [
        'vendor', 'storage', 'bootstrap/cache', 'node_modules', 'public',
        'database/migrations', 'database/factories', 'database/seeders',
        '*.blade.php',
    ],

    // Lowest severity that fails the command, or null to never fail.
    'fail_on' => 'high',

    // Drop findings below this confidence before reporting anything.
    'min_confidence' => 0,

    'baseline' => '.sloppy-baseline.json',

    'rules' => [
        // Every rule is on unless it says otherwise here.
        'SL109' => ['enabled' => false],

        // Prefer lowering a severity to switching a rule off: the finding
        // stays visible without failing the build.
        'SL301' => ['severity' => 'low'],

        'SL101' => ['max_lines' => 120, 'max_complexity' => 20],
        'SL206' => ['max_dependencies' => 6],
        'SL210' => ['ignore_models' => ['Country', 'Currency', 'Setting']],
    ],
];
```

Exclusions match whole path segments at any depth, so `vendor` excludes both
`vendor/…` and `packages/foo/vendor/…`. Entries containing `*` are matched as
globs.

The published config file documents every option of every rule with its
default. Start by reading that rather than this table.

### Tuning a noisy first run

In order of bluntness:

1. `min_confidence: 75` — keep only findings the analyser is fairly sure about.
2. `'SL109' => ['severity' => 'info']` — keep the finding, stop it mattering.
3. `php artisan sloppy:baseline` — accept today's debt, gate on tomorrow's.
4. `'SL109' => ['enabled' => false]` — last resort.

## JSON output

```bash
php artisan sloppy --format=json
php artisan sloppy:diff --format=json
```

The shape is a published contract. `schema` is bumped when a field changes
meaning; within a schema version keys are only ever added. Findings come out in
the analyser's canonical order, so two runs over the same code produce
byte-identical output that is safe to diff in CI.

This is the report behind the screenshot above, abridged to one finding:

```json
{
  "schema": 1,
  "tool": "sloppy",
  "score": {
    "value": 67,
    "band": "needs_attention",
    "label": "Needs attention",
    "penalty": 18.68,
    "penalty_density": 18.68
  },
  "summary": {
    "files": 1,
    "lines": 65,
    "findings": 7,
    "by_severity": { "critical": 0, "high": 0, "medium": 5, "low": 2, "info": 0 }
  },
  "findings": [
    {
      "rule": "SL106",
      "name": "Unused Constructor Dependency",
      "category": "dependencies",
      "severity": "medium",
      "confidence": 88,
      "file": "app/Services/ReportingService.php",
      "line": 12,
      "end_line": 12,
      "column": 9,
      "message": "ReportingService injects $logger but never uses $this->logger.",
      "explanation": "An unused dependency still has to be constructed and resolved, and it misleads the next reader about what the class actually needs. ...",
      "suggestion": "Remove $logger from the constructor, and from any container binding or test that builds this class by hand.",
      "fingerprint": "ReportingService::$logger",
      "identity": "a6166b140b3256d6",
      "metrics": { "dependency": "Psr\\Log\\LoggerInterface", "promoted": true }
    }
  ],
  "errors": {}
}
```

`identity` is the stable hash baselines and diff mode match on. `errors` maps
whatever could not be processed to why — a relative path for a file that would
not parse, or `"SL101 in app/Foo.php"` for a rule that threw.

Diff mode returns the same envelope with `mode: "diff"`, a `base`, a
`changed_files` list, `score.base` / `score.current` / `score.delta`, and
findings split into `new`, `existing` and `resolved`.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Analysis completed and nothing breached `fail_on` |
| `1` | Analysis completed and found something at or above `fail_on` |
| `2` | Analysis could not run: bad configuration, no git repository, unreadable baseline |

The distinction between 1 and 2 matters. A pipeline that conflates them either
ignores real findings or fails on a typo in a config file without telling you
which.

## CI

Gate pull requests on what they introduce, not on what they inherited:

```yaml
name: sloppy

on: pull_request

jobs:
  review:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          # Diff mode needs the base commit, so a shallow clone is not enough.
          fetch-depth: 0

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - run: composer install --no-interaction --prefer-dist

      - name: Review the change
        run: php artisan sloppy:diff origin/${{ github.base_ref }}
```

For a whole-project gate on `main`, commit a baseline and run `php artisan
sloppy`. To keep the report as a build artifact:

```yaml
      - run: php artisan sloppy --format=json > sloppy.json
      - uses: actions/upload-artifact@v4
        with:
          name: sloppy-report
          path: sloppy.json
```

## Custom rules

Extend `BaseRule` and register the class. Options come from
`sloppy.rules.<ID>`, keyed by whatever `id()` returns.

```php
namespace App\Sloppy;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Expr\StaticCall;

final class NoFacadesInDomainRule extends BaseRule
{
    public function id(): string
    {
        return 'APP001';
    }

    public function name(): string
    {
        return 'Facade In Domain';
    }

    public function description(): string
    {
        return 'Flags Laravel facades used inside the domain layer.';
    }

    public function explanation(): string
    {
        return 'The domain layer is meant to be testable without the framework booted.';
    }

    public function category(): Category
    {
        return Category::Architecture;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        if (! str_contains($context->relativePath(), 'app/Domain/')) {
            return;
        }

        foreach (NodeHelper::find($context->ast(), StaticCall::class) as $call) {
            $class = NodeHelper::staticCallClass($call);

            if ($class === null || ! str_contains($class, 'Illuminate\Support\Facades')) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $call,
                message: sprintf('%s is used in the domain layer.', NodeHelper::baseName($class)),
                suggestion: 'Inject the underlying service instead.',
                confidence: $this->intOption('confidence', 90),
                fingerprint: NodeHelper::baseName($class),
            );
        }
    }
}
```

```php
// config/sloppy.php
'custom_rules' => [
    App\Sloppy\NoFacadesInDomainRule::class,
],

'rules' => [
    'APP001' => ['confidence' => 80],
],
```

`AnalysisContext` gives you the parsed file and a project-wide index; write
rules against those rather than reading files yourself, so each file is parsed
once per run. `NodeHelper` carries the shared AST vocabulary — class
classification, metrics, chain walking — so a rule contains only the detection
it is named after.

Rules must be deterministic and side-effect free: no disk writes, no network,
no clock. A rule that throws is caught, recorded in `errors`, and does not stop
the run.

## What Sloppy is not

Sloppy complements the tools you already run; it does not replace any of them.

| Tool | Answers |
| --- | --- |
| PHPStan / Psalm | Is this type-correct? |
| Pint / PHP_CodeSniffer | Is this formatted consistently? |
| Pest / PHPUnit | Does this behave correctly? |
| Rector | Can this be transformed mechanically? |
| **Sloppy** | Is this shaped like code somebody will regret? |

There is no overlap by design. If PHPStan can prove it, Sloppy stays out of it.

## False positives

A static analyser that constantly complains gets switched off, so this is the
priority the whole rule set is tuned around:

- **Multiple signals.** SL101 and SL102 need several independent measurements
  to be over the line, not one.
- **Hedged language.** "Possible N+1", "may be unnecessary for the current
  usage" — where certainty is impossible, the wording says so and the
  confidence drops.
- **Framework awareness.** Relations, scopes, accessors, casts and `boot()`
  hooks are what models are *for*, and are never counted against them. A class
  that exists to make HTTP calls is not flagged for making HTTP calls.
- **Escape hatches everywhere.** Magic methods, dynamic property access,
  attributes, reflection, `compact()`, subclasses — anything that could reach a
  member indirectly makes the relevant rule step back rather than guess.
- **A regression test for silence.** `tests/Fixtures/Good` is ordinary Laravel
  code, and a test asserts all 23 rules report zero findings on it. If a change
  to any rule breaks that test, the rule is wrong.

Found a false positive? That is a bug worth reporting, not a threshold to work
around.

## Testing

```bash
composer test
```

That runs, in order: Rector (dry run), Pint, PHPStan at level 8, 100% type
coverage, then the suite with a 95% line-coverage floor. See
[CONTRIBUTING.md](CONTRIBUTING.md).

## Roadmap

v0.1 is the deterministic analyser. The architecture is built so these can be
added without reshaping it:

- Safe automated fixes (redundant conditions, unused imports) via Rector
- `sloppy:explain` for a longer write-up of one finding
- GitHub Action and inline PR comments
- HTML reports
- Project architecture policies
- MCP server, so an agent can check its own work before handing it back
- Optional, explicitly opt-in AI assistance for explaining or fixing hard
  findings

The deterministic analyser will always work with no API key, no network and no
model. Anything AI-assisted will be opt-in, separate, and never required to get
a report.

## License

MIT. See [LICENSE.md](LICENSE.md).
