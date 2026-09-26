# CI and code scanning

Running Sloppy in a pipeline, and putting its findings where your team already looks.

[← Back to the README](../README.md) · [All documentation](README.md)

## CI

One command, and it works out the rest:

```bash
php artisan sloppy:ci        # or: vendor/bin/sloppy ci
```

`sloppy ci` reads the environment it is running in and does what that
environment wants:

| Where it runs | What it does |
| --- | --- |
| A GitHub pull request | Compares against the target branch and annotates the changed lines |
| A GitHub push | Analyses the whole project |
| GitLab | Writes a Code Quality report GitLab renders in the merge request |
| Anywhere else | Prints the ranked console report |

It also writes the ranked report to the GitHub job summary, and hands the
numbers back as step outputs so a later step can comment, gate a deploy or
publish a badge without running anything twice.

Everything is overridable: `--base`, `--format`, `--report=<file>`,
`--fail-on`, `--scan`, `--no-summary`, `--path`, `--rule`, `--min-confidence`.

## GitHub Action

Three lines of YAML:

```yaml
- name: Run Sloppy Agent Review
  uses: heyosseus/sloppy-action@v1
  with:
    diff-branch: main
```

In full, with the parts you might want:

```yaml
name: sloppy

on: pull_request

permissions:
  contents: read
  pull-requests: read

jobs:
  review:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - id: sloppy
        uses: heyosseus/sloppy-action@v1
        with:
          fail-on: high          # critical, high, medium, low, info or never
          paths: app src         # optional, space separated
          report: sloppy.json    # optional machine-readable copy

      - run: echo "Score ${{ steps.sloppy.outputs.score }} (${{ steps.sloppy.outputs.new-findings }} new)"
```

The action fetches the base branch itself -- `actions/checkout` clones one
commit by default, and diff mode needs the branch the pull request targets, or
it would report inherited findings as if the author had written them.

Inputs: `diff-branch`, `paths`, `rules`, `fail-on`, `min-confidence`, `format`,
`report`, `scan`, `summary`, `working-directory`, `php-version`, `version`.
Outputs: `score`, `score-delta`, `findings`, `new-findings`,
`resolved-findings`, `status`, `mode`.

The action is this repository's own `action.yml`, so
`uses: heyosseus/sloppy@v1` works too if you prefer not to depend on a second
repository.

## GitLab CI

Include the shipped template and the findings appear in the merge request's
Code Quality widget, beside the lines they are about:

```yaml
include:
  - remote: 'https://raw.githubusercontent.com/heyosseus/sloppy/main/resources/ci/gitlab-ci.yml'

variables:
  SLOPPY_FAIL_ON: high
```

The report covers the whole project on purpose: GitLab works out which findings
a merge request introduced by comparing the report against the target branch's,
and handing it a diff would break the comparison it is already doing for you.

## Editors and code scanning

Sloppy speaks three formats that put findings where you already look, so it
does not need a dashboard of its own.

| Format | Goes to |
| --- | --- |
| `--format=sarif` | GitHub code scanning, VS Code, JetBrains IDEs |
| `--format=github` | Inline annotations on a pull-request diff |
| `--format=markdown` | A pull-request comment body |

### SARIF

```bash
vendor/bin/sloppy scan --format=sarif > sloppy.sarif
```

SARIF 2.1.0 is what code-scanning tooling already reads, which is the answer
to *why not SonarQube* without running a server. Findings appear in the
Security tab, and in your editor's problems panel, from one file.

GitHub deduplicates findings across runs using `partialFingerprints`, and
Sloppy's finding identity is exactly the right shape for it: a stable hash of
rule, file and a rule-supplied fingerprint that **excludes the line number**,
so adding an import at the top of a file does not resurrect every finding
beneath it or re-notify you about all of them.

```yaml
      - name: Analyse
        run: vendor/bin/sloppy scan --format=sarif --fail-on=never > sloppy.sarif

      - uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: sloppy.sarif
```

`--fail-on=never` is deliberate there: let the upload happen, and let code
scanning decide what blocks a merge.

### Inline annotations

```yaml
      - name: Annotate the diff
        run: vendor/bin/sloppy scan --format=github
```

No redirection — the workflow commands are meant to be *seen* by the runner on
standard output, which is why `github` is the one machine-readable format you
do not pipe to a file.

### A pull-request comment

```yaml
      - name: Comment
        run: |
          vendor/bin/sloppy review origin/${{ github.base_ref }}             --format=markdown > review.md
          gh pr comment ${{ github.event.number }} --body-file review.md
        env:
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Analysis completed and nothing breached `fail_on` |
| `1` | Analysis completed and found something at or above `fail_on` |
| `2` | Analysis could not run: bad configuration, no git repository, unreadable baseline |

The distinction between 1 and 2 matters. A pipeline that conflates them either
ignores real findings or fails on a typo in a config file without telling you
which.
