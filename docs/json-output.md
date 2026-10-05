# JSON output

The machine-readable report, and the contract it keeps.

[← Back to the README](../README.md) · [All documentation](README.md)

## JSON output

```bash
php artisan sloppy --format=json
php artisan sloppy:diff --format=json
```

The shape is a published contract. `schema` is bumped when a field changes
meaning; within a schema version keys are only ever added. Findings come out in
the analyser's canonical order, so two runs over the same code produce
byte-identical output that is safe to diff in CI.

**Standard output carries the report and nothing else**, so it pipes:

```bash
vendor/bin/sloppy scan --format=json | jq '.findings[] | select(.severity == "high")'
```

Anything written for the reader rather than for the machine -- the project root
and configuration source, the count of findings a baseline is hiding, progress
-- goes to standard error. In a terminal you see it as usual; in a pipe it stays
out of the way. Redirect it with `2>/dev/null` if you want it gone entirely.

This is the report behind the screenshot in
[Anatomy of a finding](getting-started.md#anatomy-of-a-finding), abridged to
one finding:

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
      "metrics": { "dependency": "Psr\\Log\\LoggerInterface", "promoted": true },
      "risk": 4.36,
      "tier": "maintainability"
    }
  ],
  "errors": {},
  "rules_skipped": []
}
```

`identity` is the stable hash baselines and diff mode match on. `risk` is the
finding's place in the [reading order](scoring.md#risk). `tier` is `defect`,
`maintainability` or `advisory`, as the console report
[triages](getting-started.md#a-long-report-triaged) it.

`errors` is always an object -- `{}` when nothing went wrong -- mapping
whatever could not be processed to why: a relative path for a file that would
not parse, or `"SL101 in app/Foo.php"` for a rule that threw.

`rules_skipped` lists the IDs of rules that did not run because the framework
they need is not in the project (`sloppy.framework`), e.g.
`["SL201", "SL202"]` for a project without Laravel. An empty report with ten
skipped rules is a different statement from an empty report with none.

Text that came from the analysed code is always valid UTF-8 in the report: a
byte sequence that is not -- a Latin-1 string literal quoted in a message --
is replaced with U+FFFD rather than making the whole report unencodable. If a
report still cannot be encoded the command exits 2 with a message instead of
writing a partial or empty document.

### Explaining risk

With `--explain-risk` (on `scan`, `diff`, `review` and `ci`) every finding also
carries the factors behind its `risk` and the arithmetic that combines them:

```json
"risk": 4.36,
"tier": "maintainability",
"risk_factors": {
  "value": 4.36,
  "severity_weight": 4.0,
  "confidence": 0.88,
  "novelty": 1.0,
  "novelty_label": "novelty unknown",
  "proximity": 1.0,
  "proximity_label": "whole file",
  "reach": 1.24,
  "blast_radius": 2,
  "exposure": 1.0,
  "exposure_label": "coverage unknown",
  "activity": 1.0,
  "recent_changes": null
},
"risk_arithmetic": "4.0 (medium) x 0.88 (confidence) x 1.00 (novelty unknown) x 1.00 (whole file) x 1.24 (2 usages) x 1.00 (coverage unknown) = 4.36"
```

`blast_radius` and `recent_changes` are `null` when they were not measured --
no project index, no git history -- which is different from zero. See
[Risk](scoring.md#risk) for what each factor means.

## Diff mode

`diff`, `review --format=json` and `ci` in diff mode return this envelope:

```json
{
  "schema": 1,
  "tool": "sloppy",
  "mode": "diff",
  "base": "main",
  "changed_files": [
    { "path": "app/Http/Controllers/OrderController.php", "status": "modified", "changed_lines": 6 },
    { "path": "app/Legacy/Report.php", "status": "deleted", "changed_lines": 0 }
  ],
  "score": {
    "base": { "value": 84, "band": "healthy", "label": "Healthy", "penalty": 15.2, "penalty_density": 15.2 },
    "current": { "value": 82, "band": "healthy", "label": "Healthy", "penalty": 17.8, "penalty_density": 17.8 },
    "delta": -2
  },
  "summary": { "new": 1, "existing": 3, "resolved": 2 },
  "new": [ { "rule": "SL107", "...": "...", "risk": 9.6, "tier": "defect" } ],
  "existing": [],
  "resolved": [],
  "errors": {}
}
```

- `base` is the revision as given -- or the merge-base commit with
  `--merge-base`, and in `ci` when comparing with a branch.
- `changed_files[].status` is `added`, `modified`, `renamed`, `deleted` or
  `untracked`. Only PHP files the project analyses are listed. A file moved
  with a plain `mv` is `renamed`, like one moved with `git mv`. A deleted file
  is listed so the findings it took with it count as `resolved`.
- `score.base` and `score.current` are the scores of the **whole tree** at the
  base and now -- `score.current` is the number `sloppy scan` gives -- so a
  change that touches no PHP still reports the project's real score, with a
  `delta` of 0. See [Scoring](scoring.md) for how the base tree is scored.
- `summary` has exactly the three keys `new`, `existing` and `resolved`: the
  lengths of the three lists.
- `new`, `existing` and `resolved` hold findings in the changed files only, in
  the same shape as a scan's `findings` -- including `risk`, `tier` and, with
  `--explain-risk`, `risk_factors` and `risk_arithmetic`. Evidence findings
  (`SL502`, `SL503`, `SL307`) only ever appear in `new`.
- `errors` is an object keyed like a scan's, covering both trees.
