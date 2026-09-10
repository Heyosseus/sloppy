# Contributing

Thanks for considering a contribution.

## Getting set up

```bash
composer install
composer test
```

You need PHP 8.3 or later, and PCOV or Xdebug — the suite enforces 100% line
coverage, so it cannot run without a coverage driver.

## What the gates check

`composer test` runs five in sequence:

| Gate | Command |
| --- | --- |
| Rector | `rector --dry-run` |
| Pint | `pint --test` |
| PHPStan | `phpstan analyse` (level 8) |
| Type coverage | `pest --type-coverage --min=100` |
| Line coverage | `pest --coverage --min=100` |

All five must pass. If a line is genuinely unreachable, delete it rather than
finding a way to exclude it — an untestable branch is usually a branch that should
not exist.

Individual gates run on their own: `composer test:lint`, `composer test:types`,
and so on. `composer lint` and `composer refacto` apply the fixes rather than
only reporting them.

## Supported peers

| Peer | Range |
| --- | --- |
| PHP | 8.3, 8.4 |
| Laravel | 11, 12, 13 |

CI proves every combination, and both ends of the range are additionally run with
`--prefer-lowest` to catch a constraint in `composer.json` that is wider than the
code actually supports.
