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
