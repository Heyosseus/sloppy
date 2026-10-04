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

#### Presets

| Preset | For | What it enforces |
| --- | --- | --- |
| `laravel` | The default | Nothing new: the five roles Sloppy has always used, with no policies. |
| `laravel-actions` | One class per use case | Adds an `action` role. Controllers don't query the database; actions never call controllers; models never call actions or controllers. |
| `service-repository` | Controllers, services, repositories | Adds `repository` and `repository-contract` roles. Only repositories query; controllers never reach a repository directly. |
| `ddd` | Domain, application, infrastructure | The domain never depends on application, infrastructure or HTTP, and never makes HTTP calls, reads the request or the environment, renders, or resolves from the container. Application never depends on infrastructure or HTTP. The domain may use Eloquent. |
| `hexagonal` | Ports and adapters | The domain is plain PHP: no `Illuminate\*`, no database, HTTP, dispatching, request, environment, views or container. Use cases talk to ports, never adapters. Ports and adapters are intended abstractions. |
| `modular` | A modular monolith | Module boundaries for `Modules\{module}` and `App\Modules\{module}`: other modules are reached only through `Contracts`, `Events`, `Data` and `Enums`, or the `Shared` module. |
| `none` | Starting from nothing | No roles at all. |

`sloppy architecture` lists every role, policy and boundary in force and
where each came from, so a preset is never a black box.

#### Policies

A policy says what one role may depend on and what it may do:

```php
'architecture' => [
    'preset' => 'laravel',
    'policies' => [
        'controller' => [
            'may_not' => ['db', 'env'],
            'may_not_depend_on' => ['App\Infrastructure\*'],
            'advice' => 'A controller hands the work to a service.',
        ],
        'service' => [
            'may_depend_on' => ['model', 'service'],
        ],
    ],
],
```

- `may_not_depend_on` forbids what it names: a role, or a glob on class
  names for code no role covers (`Illuminate\*`, a vendor SDK). Reported by
  `SL304`.
- `may_depend_on` is an allow list: a dependency on a class that plays a role
  must be on a role listed here, or on the same role. Framework, vendor and
  unclassified classes are never judged by it -- forbid those by name.
- `may_not` forbids capabilities, reported by `SL305`. Outbound `http` is
  reported by `SL208`, so it is never reported twice.
- `public_methods` and `final` give the role a shape, reported by `SL308`:
  the public methods a concrete class in the role may declare (names or
  globs; the constructor and other magic methods always may), and whether it
  must be final. The `laravel-actions` preset keeps actions to `handle()` and
  the hooks lorisleiva/laravel-actions calls.
- `advice` is added to every finding the policy produces. Write it for the
  agent that will read it.

| Capability | Counted from |
| --- | --- |
| `db.read`, `db.write` (`db` for both) | Static calls on a class the project index knows is an Eloquent model, judged by the whole chain (`Order::where()->update()` writes); the `DB` facade; an injected `DatabaseManager` or connection |
| `http` | The `Http` facade, Guzzle, `curl_*`, `file_get_contents('http…')`, an injected HTTP client |
| `dispatch` | The `Mail`, `Notification`, `Bus`, `Queue`, `Event` and `Broadcast` facades, `X::dispatch()`, `dispatch()`, `event()`, `->notify()`, an injected dispatcher or mailer |
| `request` | `request()`, the `Request` facade, a `Request` or form request parameter |
| `env` | `env()` |
| `view` | `view()`, the `View` facade, `Inertia::render()`, an injected view factory |
| `container` | `app()`, `resolve()`, the `App` facade, `Container::getInstance()`, an injected container or application |

Detection is tuned for precision: a policy finding says "your architecture
forbids this" and has to be right. `$order->save()` on a variable is not
counted, because nothing can tell it from any other object's `save()`.

A project policy replaces the preset's policy for that role, and `false`
removes it. A preset policy also disappears with its role, when the project
removes or replaces the role.

#### Module boundaries

```php
'architecture' => [
    'boundaries' => [
        'modules' => 'App\Modules\{module}\*',
        'public' => ['Contracts\*', 'Events\*'],
        'shared' => ['App\Modules\Shared\*'],
    ],
],
```

`{module}` captures the module's name, so every module is covered without
being listed, including the next one. A class may name another module's
classes only if they match `public` (relative to that module) or `shared`.
Reported by `SL306`. `'boundaries' => false` turns off a preset's boundaries.

#### Where new classes go

```php
'architecture' => [
    'covers' => ['app/*'],
],
```

`covers` names the paths where every class should play a role. `sloppy diff`,
and the agent hooks, report a class the change adds there that plays none as
`SL307`, so a new `app/Services/Helpers/DataUtils.php` is caught on the edit
that created it. Classes that already exist are never reported, and without
`covers` the check does not run.

Before creating a class, ask where it belongs:

```bash
vendor/bin/sloppy architecture place "an action that refunds an order" --name=RefundOrder
```

The answer names the role, its namespace and directory, the suffix its classes
share, a class name and file, and what the role may depend on and do. The MCP
server offers the same answer as `sloppy_place`.

#### Seeing the whole picture

```bash
vendor/bin/sloppy architecture graph               # Mermaid, for a README or a pull request
vendor/bin/sloppy architecture graph --format=dot  # Graphviz
vendor/bin/sloppy architecture graph --format=json
```

The graph shows how many classes play each role and how many dependencies run
between roles. An edge with a dependency the policies forbid is drawn in red,
with the count of forbidden ones, so every red edge is an `SL304` finding.

#### Writing the profile down

A profile can live under `sloppy.architecture`, or in a file of its own:
`sloppy-architecture.php` in the project root, returning the same array.
Sloppy reads the file when it exists, and refuses to run when both places
declare an architecture, so there is never a question of which one is in force.

Three commands write that file for you. Each prints what it would write, asks
before writing when a person is at the terminal, and otherwise writes only
with `--write` (`--force` replaces an existing file):

```bash
vendor/bin/sloppy architecture init      # infer a profile from the code
vendor/bin/sloppy architecture import    # translate deptrac.yaml (or a file you name)
vendor/bin/sloppy architecture prompt    # what an agent needs to write one from prose
```

- **`init`** reads the packages in `composer.json` (`nwidart/laravel-modules`,
  `lorisleiva/laravel-actions`), the namespaces classes cluster in (`Domain`,
  `Infrastructure`, `Ports`, `Modules\*`) and the words class names end in. It
  picks the closest preset, adds a role for every family of three or more
  classes the preset leaves out (`*Data`, `*Job`), covers the analysed paths
  once nearly every class has a role, and asks about namespaces it could not
  place rather than guessing.
- **`import`** turns deptrac layers into roles, collectors into matchers and
  the ruleset into `may_depend_on` allow lists. Collectors with no equivalent,
  and regular expressions no glob can express, are named in the file's
  comments instead of being guessed at.
- **`prompt`** prints the profile format, what the code shows and the draft
  `init` would write, for any agent to turn your `ARCHITECTURE.md` into a
  profile. The MCP server offers it as `sloppy_architecture_prompt`. Sloppy
  itself never calls a model: what the agent writes is a file like any other,
  validated and reviewed.

#### Intended abstractions

`'intended_abstraction' => true` on a role tells `SL301`, `SL302` and `SL303`
that interfaces and wrappers in that role are the design: a port with one
adapter is a port, not indirection. The `hexagonal` and `service-repository`
presets set it on their ports, adapters and repositories.

```php
'roles' => [
    'gateway' => ['suffix' => 'Gateway', 'intended_abstraction' => true],
],
```

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
