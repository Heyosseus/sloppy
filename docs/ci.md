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
| A GitHub pull request | Compares against where the change forked from the target branch and annotates the changed lines |
| A GitHub push | Analyses the whole project |
| GitLab | Writes a Code Quality report GitLab renders in the merge request |
| Anywhere else | Prints the ranked console report |

It also writes the ranked report to the GitHub job summary, and hands the
numbers back as step outputs so a later step can comment, gate a deploy or
publish a badge without running anything twice.

Everything is overridable: `--base`, `--format`, `--report=<file>`,
`--fail-on`, `--scan`, `--no-summary`, `--path`, `--rule`, `--min-confidence`,
`--explain-risk`.

**It compares from the merge base.** Given a branch -- the pull request's
target, or `--base=main` -- `ci` compares the working tree with
`git merge-base <branch> HEAD`, where the change forked, not with the branch's
tip. Against the tip, everything merged into the target since the fork would
be charged to this change: findings the target gained would show up as
resolved, and findings it fixed as new. A commit given by hash is used as it
is. `--no-merge-base` compares with the tip. A shallow clone may not reach the
merge base; `ci` then warns and compares with the tip, so fetch enough
history (`fetch-depth: 0`) -- the action deepens the checkout itself.
`sloppy diff <branch> --merge-base` does the same outside CI; plain
`sloppy diff <branch>` compares with the tip.

**A file that cannot be parsed fails the step.** It is a file nobody checked,
so `ci` exits 2 when any file has a parse error, after writing the report.
Pass `--allow-parse-errors` to accept that. A run in which *no* file could be
parsed exits 2 without a score, everywhere -- 100 would be a claim about code
nobody read. A `--path` that does not exist exits 2 too.

**In a monorepo** -- the project in a subdirectory of the repository, or the
action's `working-directory` -- changed files are found relative to the
project, and annotations, SARIF and Code Quality name files from the
repository root, which is what GitHub and GitLab resolve them against.

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

Sloppy speaks four formats that put findings where you already look, so it
does not need a dashboard of its own.

| Format | Goes to |
| --- | --- |
| `--format=sarif` | GitHub code scanning, VS Code, JetBrains IDEs |
| `--format=github` | Inline annotations on a pull-request diff |
| `--format=gitlab` | The GitLab merge request Code Quality widget |
| `--format=markdown` | A pull-request comment body |

Every format works on `scan`, `diff`, `review` and `ci`. On `diff` and
`review`, `sarif`, `gitlab` and `rector` carry the findings the change
introduced; in `ci` they carry every finding in the changed files, because
GitLab and code scanning work out what is new themselves. Two findings that
share an identity -- the same pattern twice in one method -- get distinct
fingerprints, so neither is dropped as a duplicate.

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
| `2` | Analysis could not run: bad configuration, a `--path` that does not exist, no git repository, unreadable baseline, nothing that parses -- and in `ci`, any file that does not parse |

The distinction between 1 and 2 matters. A pipeline that conflates them either
ignores real findings or fails on a typo in a config file without telling you
which.
