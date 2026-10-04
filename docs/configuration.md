# Configuration

Every option lives in one file. This page is the tour; the published `config/sloppy.php` is the reference.

[← Back to the README](../README.md) · [All documentation](README.md)

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
        'Database/Migrations', 'Database/Factories', 'Database/Seeders',
        'tests', 'Tests', '*.blade.php',
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

        // Where `sloppy scan` puts a rule's findings: listed as a defect,
        // summarised as maintainability, or counted as advisory.
        'SL201' => ['tier' => 'defect'],

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
default. Start by reading that rather than this page.

### Describing your architecture

The rules about where code belongs (SL201, SL202, SL206, SL207 and SL208)
judge a class by the **role** it plays: `controller`, `model`,
`form-request`, `middleware` or `service`. By default those roles come from
the `laravel` preset, which recognises them the way Sloppy always has: by
namespace, name suffix and parent class.

If your project keeps things somewhere else, say so:

```php
'architecture' => [
    'preset' => 'laravel',
    'roles' => [
        // Your roles are tried first, in the order you write them.
        'controller' => ['namespace' => 'App\Ui\Http\*', 'suffix' => 'Controller'],
        'gateway' => ['namespace' => 'App\Infrastructure\*', 'suffix' => 'Gateway'],

        // false removes a preset role entirely.
        'service' => false,
    ],
],
```

A class takes the **first** role it matches. A role with a preset role's name
replaces it, and `'preset' => 'none'` starts from nothing.

| Key | Matches | Example |
| --- | --- | --- |
| `namespace` | the fully qualified name | `'App\Domain\*\Actions\*'` |
| `path` | the project-relative path | `'app/Legacy/*'` |
| `suffix` | the end of the short name | `'Controller'` |
| `parent` | the class it extends directly | `'*FormRequest'` |
| `extends` | any class it extends, through parents in your project | `'App\Http\BaseController'` |
| `implements` | an interface it implements | `'App\Contracts\*'` |
| `uses` | a trait it uses | `'Lorisleiva\Actions\Concerns\AsAction'` |
| `attribute` | an attribute on the class | `'App\Attributes\Action'` |
| `kind` | `class`, `interface`, `trait` or `enum` | `'class'` |
| `any` | a list of alternatives, one of which must match | `[['suffix' => 'Handler'], ['namespace' => 'App\Handlers\*']]` |
| `not` | a matcher that must not match | `['suffix' => 'TestController']` |

Every key also takes a list, any of which may match. Keys in one role must all
match. In a glob, `*` is any text, backslashes included; nothing else is
special.

Check the result with `sloppy architecture`, which counts the classes in each
role, and `sloppy architecture OrderController`, which says which role one
class got, what matched, and which roles it would also have matched. A
mistake in the profile stops the run with a message naming the key, rather
than silently matching nothing.

### Tuning a noisy first run

In order of bluntness:

1. `min_confidence: 75` — keep only findings the analyser is fairly sure about.
2. `'SL109' => ['severity' => 'info']` — keep the finding, stop it mattering.
3. `php artisan sloppy:fix` — delete the comments that only restate their
   code, and let Rector take the mechanical findings.
4. `php artisan sloppy:baseline` — accept today's debt, gate on tomorrow's.
5. `'SL109' => ['enabled' => false]` — last resort.

A long scan is already [triaged](getting-started.md#a-long-report-triaged):
the defects are listed and the rest is summarised per file, so a noisy run is
readable before you tune anything.
