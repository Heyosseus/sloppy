# Everyday workflow

Reviewing a change, fixing what can be fixed, gating in your test suite, and keeping the score on screen.

[← Back to the README](../README.md) · [All documentation](README.md)

## Review a change

The most useful command if you work with coding agents:

```bash
php artisan sloppy:diff             # working tree vs HEAD
php artisan sloppy:diff HEAD~1      # vs the previous commit
php artisan sloppy:diff main        # everything this branch changed
```

It separates what your change *introduced* from what it merely *inherited*, and
only new findings can fail the build.

![php artisan sloppy:diff main](../.github/assets/diff.svg)

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

## What to read first

`sloppy:diff` answers *did this change make it worse?* `sloppy:review` answers
the question you have immediately afterwards: **of everything this change
touched, what deserves reading?**

```bash
vendor/bin/sloppy review origin/main
php artisan sloppy:review origin/main
```

```
  Sloppy review

  161 file(s) changed, 12,198 lines  ·  score 5 → 12 (+7)

  Read in this order
    1. app/Actions/Product/CloneProductToTenantAction.php   risk 70.5
         new SL111 Copy-Paste Drift  (76%, risk 9.9, in hunk)
         new SL102 God Class  (73%, risk 9.5, in hunk)
         new SL111 Copy-Paste Drift  (68%, risk 8.8, in hunk)
         new SL204 Query Inside Loop  (84%, risk 4.4, in hunk)
         and 9 more in this file, 2 x SL104, 7 x SL204
    2. app/Filament/Imports/B2bCatalogImporter.php   risk 48.0
         new SL102 God Class  (91%, risk 11.8, in hunk)
         new SL101 God Method  (76%, risk 9.9, in hunk)

  Skim
    4 file(s), risk under 5
      app/Support/TenantFrontend.php  risk 3.4

  No attention needed
    132 file(s) changed with no findings

  Resolved by this change
    3 finding(s) no longer reported
      2 x SL101 God Method
      1 x SL204 Query Inside Loop
```

Three tiers, so you know where to stop. Same analysis, same score and the same
exit code as `sloppy:diff` — only the presentation differs, so adopting the
reading order changes no build outcome.

**`in hunk` is the part no other tool can do.** A god method your change
*created* and a god method it merely stood next to are not the same finding.
Telling them apart needs the diff's hunks at rule time, and nothing else in
this space has them.

## Fix what can be fixed

Sloppy does not rewrite your code. Rector and Pint already do that well, and
what Sloppy knows that they do not is *which* of their rules this project
currently needs:

```bash
php artisan sloppy:fix --dry-run     # show what would change
php artisan sloppy:fix               # rewrite, then format
```

It generates a Rector configuration scoped to the files that actually have
findings, runs it, formats the rewritten files with Pint, and then tells you
what is left:

```
  12 of 31 finding(s) have an automated fix. Wrote rector-sloppy.php.
  rector finished.
  pint finished.
  19 finding(s) need a person: SL101 x4, SL102 x1, SL104 x9, SL111 x5.
  Findings 31 -> 19. Score 68/100 -> 84/100.
```

Scoping matters: the generated configuration lists only the files that have
findings, so a fix pass on a large project does not rewrite the whole of
`app/` to prove a point about six files.

### What it can fix, and what it will not pretend to

Six rules map onto Rector rules that genuinely fix them:

| Rule | Fixed by |
| --- | --- |
| `SL103` Excessive Nesting | `ChangeNestedIfsToEarlyReturn`, `ChangeNestedForeachIfsToEarlyContinue`, `CombineIf` |
| `SL105` Dead Private Method | `RemoveUnusedPrivateMethod` |
| `SL106` Unused Constructor Dependency | `RemoveUnusedConstructorParam`, `RemoveUnusedPromotedProperty`, `RemoveUnusedPrivateProperty` |
| `SL107` Swallowed Exception | `ThrowWithPreviousException`, `RemoveDeadTryCatch` |
| `SL108` Redundant Condition | `RemoveAlwaysTrueIfCondition`, `RemoveAlwaysElse`, `SimplifyIfReturnBool` |
| `SL110` Defensive Programming Noise | `SimplifyIfNotNullReturn`, `RemoveAlwaysTrueIfCondition` |

Rector's `...Rector` class suffix is omitted above for width; the generated
configuration names each class in full.

The rest are left alone on purpose, and the report says why at the point you
would have asked:

| Rule | Why no tool fixes it |
| --- | --- |
| `SL101` God Method | splitting a long method is a design decision, not a rewrite |
| `SL102` God Class | which responsibility leaves the class is a design decision |
| `SL104` Duplicate Logic | the shared code has to be named before it can be extracted |
| `SL109` Narrative Comment | deleting a comment automatically risks deleting the one that mattered |
| `SL111` Copy-Paste Drift | only you know whether the drift between the copies was deliberate |

No automated rewrite splits a god method into the three methods it wanted to
be, and one that tried would produce three worse ones. A fix command that
claimed otherwise would cost you more time than it saved.

### Running it your way

| Flag | What it does |
| --- | --- |
| `--dry-run` | Write nothing; print what would change |
| `--rule=SL107` | Fix only these rules, repeatable |
| `--no-rector` | Write the configuration and stop, for review before it runs |
| `--no-pint` | Skip the formatting pass, if your project formats another way |
| `--keep-config` | Leave `rector-sloppy.php` on disk instead of deleting it |
| `--rector-config=` | Name the generated file something else |
| `--path=app/Domain` | Fix only these paths, repeatable |
| `--min-confidence=80` | Ignore findings the analyser is less sure about |

Neither tool is a dependency of this package. Without them you still get the
configuration and a message naming the one to install -- Sloppy's own job is
knowing which of their rules this project currently needs, and that answer is
useful whether or not you let it run them. To generate the configuration
alone, without an analysis pass around it:

```bash
vendor/bin/sloppy --format=rector > rector-sloppy.php
```

## In your test suite

Sloppy ships a Pest plugin, so new debt fails in the same red-green loop as
everything else about the code. There is nothing to register: the expectations
are autoloaded with the package, and exist as soon as `composer require
--dev heyosseus/sloppy` has run.

```php
it('has zero AI slop on the current branch', function (): void {
    expectCleanSloppyDiff('main');
});

it('keeps the whole of app/Domain clean', function (): void {
    expectCleanSloppyScan(paths: ['app/Domain']);
});

it('does not let the score slip', function (): void {
    expectSloppyScoreAtLeast(80);
});
```

Failures name the file, the line and the rule:

```
This change introduced 1 finding(s) against main:
  app/Services/Billing.php:41  SL101 God Method (high) -- Billing::charge() spans 96 lines ...
```

All three take the same narrowing arguments, so a test can be as specific as
the rule it is protecting:

| Expectation | Fails when | Returns |
| --- | --- | --- |
| `expectCleanSloppyDiff($base)` | the working tree introduced findings against `$base` | `DiffReport` |
| `expectCleanSloppyScan()` | the analysed paths contain findings at all | `AnalysisResult` |
| `expectSloppyScoreAtLeast($min)` | the slop score has fallen below a floor | `AnalysisResult` |

```php
expectCleanSloppyScan(
    failOn: 'high',
    paths: ['app/Domain'],
    rules: ['SL101', 'SL107'],
    minConfidence: 80,
);
```

Each expectation returns the report it built, so a test can go further:

```php
$report = expectCleanSloppyDiff('main', failOn: 'critical');

expect($report->resolved)->not->toBeEmpty();
```

A note on where to put the gate. `expectCleanSloppyDiff('main')` and
`sloppy ci` ask the same question of the same analyser, so pick the one whose
feedback arrives when you can still act on it: the expectation fails on the
machine that wrote the code, seconds after it was written, while the pipeline
fails after the push. Teams that want both usually keep the test narrow -- one
package, one threshold -- and let CI cover the whole project.

## Watch while you work

The most useful moment to know what a change cost is while it is still being
made — which is the moment nobody stops to run a command. `sloppy watch` leaves
the answer on screen:

```bash
php artisan sloppy:watch      # Laravel
sloppy watch                  # any PHP project
```

```
  72/100  Needs attention
  ██████████████░░░░░░

  complexity      14  ████████████
  laravel          9  ████████
  duplication      4  ███

  Read first
  1 SL101 God Method             app/Services/ReportingService.php:40
› 2 SL107 Swallowed Exception    app/Models/Order.php:112
  3 SL204 Possible N+1           app/Http/Controllers/InvoiceController.php:88

  61 files · changed ReportingService.php · 1.2s
  ↑↓ move  ↵ open  r rescan  q quit
```

Put it beside the agent writing the code. Every save redraws the score, the
breakdown and what to read first. `↑↓` moves through the findings, `↵` opens the
selected one at its line in `$VISUAL` or `$EDITOR`, `r` forces a rescan and `q`
leaves — restoring the terminal exactly as it was found.

Three things worth knowing:

- **A tick is not an approximation.** It re-parses only the file that moved,
  then runs every rule over the whole project, so the numbers are identical to
  `sloppy scan` of the same tree. Reporting on fewer files would be faster and
  would make `SL303` and the duplication rules quietly wrong.
- **It watches by polling, not by filesystem events.** No extension is needed
  and it behaves the same everywhere. Changes are matched on modification time
  and length, so the rare edit that changes neither is what `r` is for.
- **Keyboard control needs a terminal that can give up a keypress.** PHP has no
  way to put a Windows console into raw mode, so there `sloppy watch` is a
  monitor: it still redraws on every change, and the footer says `Ctrl+C to
  quit` rather than offering keys that would never arrive. Redirected into a
  file or a pipe there is no terminal at all, and the command says so and exits
  rather than looping forever — `sloppy health --json` is the pipeable one.

Options are the ones `sloppy health` takes — `--path`, `--rule`,
`--min-confidence`, `--top` — plus `--interval` for the poll interval in
milliseconds (default 250).

## Filament and NativePHP

Both read the same cached health snapshot, so a dashboard never waits for an
analysis:

```php
// A Filament panel
use Heyosseus\Sloppy\Integrations\Filament\SloppyPlugin;

$panel->plugin(SloppyPlugin::make());
```

```php
// A NativePHP menu bar
use Heyosseus\Sloppy\Integrations\NativePhp\DesktopHealth;

MenuBar::create()->label(app(DesktopHealth::class)->menuLabel());   // "! 72/100"
```

Keep the snapshot warm from the scheduler, and every surface stays current:

```php
Schedule::command('sloppy:health --fresh')->hourly();
```

The same snapshot is available as JSON for anything else:

```bash
php artisan sloppy:health --json
```

Filament is not a dependency of this package. Neither is NativePHP: the desktop
integration hands you strings, and your app decides what to do with them.
