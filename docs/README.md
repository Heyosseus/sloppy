# Sloppy documentation

[← Back to the README](../README.md)

| Page | What it covers |
| --- | --- |
| [Getting started](getting-started.md) | Installing with Composer, globally or as a phar; which command to reach for; your first scan; the anatomy of a finding; adopting on an existing codebase with a baseline |
| [Coding agents](agents.md) | `sloppy agents install` and the Claude Code hooks; rulesets for Claude, Cursor, Codex, Copilot and Windsurf; Laravel Boost; the MCP server |
| [Rules](rules.md) | Every rule with its severity and category, the suppression rules, the self-check, and how false positives are kept down |
| [Everyday workflow](workflow.md) | `diff` and `review`, what to read first, `sloppy fix` over Rector and Pint, Pest expectations, `watch`, Filament and NativePHP |
| [CI and code scanning](ci.md) | `sloppy ci`, the GitHub Action, GitLab Code Quality, SARIF, inline annotations, PR comments, exit codes |
| [Score, severity and risk](scoring.md) | The slop score formula, severity versus confidence, the risk model and its arithmetic, coverage and PHPStan baselines as evidence |
| [Configuration](configuration.md) | `config/sloppy.php`, where the binary looks for it, `sloppy-architecture.php`, and tuning a noisy first run |
| [JSON output](json-output.md) | The report's shape and the contract it keeps |
| [Custom rules](custom-rules.md) | Writing a rule, registering it, and where it shows up |
