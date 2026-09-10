# Contributing

Thanks for considering a contribution.

## Getting set up

```bash
composer install
composer test
```

You need PHP 8.3 or later, and PCOV or Xdebug — the suite enforces a line
coverage floor, so it cannot run without a coverage driver.

## What the gates check

`composer test` runs five in sequence:

| Gate | Command |
| --- | --- |
| Rector | `rector --dry-run` |
| Pint | `pint --test` |
| PHPStan | `phpstan analyse` (level 8) |
| Type coverage | `pest --type-coverage --min=100` |
| Line coverage | `pest --coverage --min=95` |

All five must pass. Type coverage is absolute: every parameter, return and
property is typed. Line coverage has a floor rather than a ceiling because a
handful of defensive branches — an unwritable file, a malformed config value —
are worth keeping and not worth contriving a test for. If a line is genuinely
unreachable, delete it rather than finding a way to exclude it.

Individual gates run on their own: `composer test:lint`, `composer test:types`,
and so on. `composer lint` and `composer refacto` apply the fixes rather than
only reporting them.

## Running the commands by hand

`testbench.yaml` registers the service provider in Testbench's skeleton app, so
the commands can be run against a real directory without installing the package
into a project:

```bash
vendor/bin/testbench sloppy --path=/absolute/path/to/an/app
vendor/bin/testbench sloppy --path=/absolute/path/to/an/app --format=json
```

Paths must be absolute, because Testbench's base path is its own skeleton and
not this repository.

## Adding a rule

A rule is a class extending `BaseRule`, a config entry, and a test file. In
order:

1. **Write the test first.** Every rule needs three things proved: it fires on
   the pattern, it stays silent on code that merely resembles it, and its
   options do what they say. `findings($rule, $snippet)` and
   `findingsAcross($rule, $files)` are available in every test.
2. **Write the rule.** `AnalysisContext` gives you the parsed file and the
   project index. Ask `NodeHelper` your AST questions; if you find yourself
   writing a traversal a second rule would want, move it there. Rules must be
   deterministic and side-effect free — no disk, no network, no clock.
3. **Register it** in `RuleRegistry::shipped()` and add an entry to
   `config/sloppy.php` documenting every option with its default. A test
   asserts the config and the registry agree, in both directions.
4. **Add it to the README table.**

### What a good rule looks like

- **Several signals, not one.** A single measurement over a threshold is a
  metric, not a finding. `SL101` needs two of six, or one that is off the
  scale.
- **Hedged where it must be.** If certainty is impossible, the message says
  "possible" or "may be" and the confidence reflects it. No rule reports 100%.
- **Framework-aware.** Relations, scopes, accessors, casts and `boot()` hooks
  are what models are for. A class that exists to make HTTP calls is not
  flagged for making HTTP calls.
- **Quiet when unsure.** Magic methods, dynamic property access, attributes,
  reflection, subclasses — anything that could reach a member indirectly means
  the rule steps back rather than guessing.
- **Useful.** The message states what was measured, and the suggestion states
  what to do. "Class has many methods" helps nobody.

### The test that matters most

`tests/Feature/FixtureCorpusTest.php` asserts that all rules report **zero**
findings on `tests/Fixtures/Good` — deliberately ordinary Laravel code — with a
score of 100. If your rule breaks that test, the rule is wrong, not the
fixture. A static analyser that constantly complains gets switched off.

`tests/Feature/SelfCheckTest.php` runs the whole rule set over Sloppy's own
`src/` and fails on any finding not signed off in that file. Fix what it finds,
or record the decision there with the reasoning.

## Supported peers

| Peer | Range |
| --- | --- |
| PHP | 8.3, 8.4 |
| Laravel | 12, 13 |

CI proves every combination, and both ends of the range are additionally run with
`--prefer-lowest` to catch a constraint in `composer.json` that is wider than the
code actually supports.

Laravel 11 is not supported. Its security window has closed, so every 11.x
release now carries advisories that will never be patched and Composer's
advisory policy refuses to install them — a version CI cannot install is not a
version this package can claim to support.
