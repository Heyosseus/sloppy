<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * Architectures common enough to ship: their roles, what each role may
 * depend on and do, and which modules may see which.
 *
 * Kept as PHP constants rather than files under resources/, so the phar and a
 * global install carry them without a path to resolve.
 *
 * Order matters: a class takes the first role it matches. Within a preset the
 * roles read off a parent class come before the ones guessed from a name: a
 * FormRequest kept under `Http\Controllers` is a form request, and a class in
 * `App\Domain` that extends `Model` is a model, not a service.
 */
final readonly class Presets
{
    public const string DEFAULT = 'laravel';

    private const array FORM_REQUEST = [
        'description' => 'Validates and authorises one request.',
        'parent' => '*FormRequest',
    ];

    private const array MODEL = [
        'description' => 'An Eloquent model: data and its relationships.',
        'kind' => 'class',
        'parent' => [
            \Illuminate\Foundation\Auth\User::class,
            \Illuminate\Database\Eloquent\Relations\Pivot::class,
            '*\Model',
            'Model',
        ],
    ];

    private const array CONTROLLER = [
        'description' => 'Turns an HTTP request into a call and a response.',
        'kind' => 'class',
        'any' => [
            ['namespace' => '*Http\Controllers*'],
            ['suffix' => 'Controller', 'not' => ['suffix' => 'TestController']],
            ['parent' => '*Controller'],
        ],
    ];

    private const array MIDDLEWARE = [
        'description' => 'Wraps requests on their way in and out.',
        'namespace' => '*\Middleware\*',
    ];

    private const array SERVICE = [
        'description' => 'Where behaviour is supposed to live: services, actions, handlers and the domain.',
        'kind' => 'class',
        'any' => [
            ['suffix' => ['Service', 'Action', 'Manager', 'Handler', 'Repository', 'UseCase', 'Interactor']],
            ['namespace' => ['*\Services\*', '*\Actions\*', '*\Domain\*', '*\Application\*']],
        ],
    ];

    /**
     * The roles Sloppy has always assumed in a Laravel application, with no
     * policies: the default changes nothing about what is reported. The rules
     * read these names: SL201, SL202 and SL206 look at `controller`, SL207 at
     * `service`, and SL208 at `controller`, `model`, `form-request` and
     * `middleware`.
     */
    private const array LARAVEL = [
        'roles' => [
            'form-request' => self::FORM_REQUEST,
            'model' => self::MODEL,
            'controller' => self::CONTROLLER,
            'middleware' => self::MIDDLEWARE,
            'service' => self::SERVICE,
        ],
    ];

    /**
     * One class per use case, called from controllers, jobs and commands
     * (lorisleiva/laravel-actions, or plain action classes).
     */
    private const array LARAVEL_ACTIONS = [
        'roles' => [
            'form-request' => self::FORM_REQUEST,
            'model' => self::MODEL,
            'controller' => self::CONTROLLER,
            'middleware' => self::MIDDLEWARE,
            'action' => [
                'description' => 'One use case, behind one public method.',
                'kind' => 'class',
                'any' => [['suffix' => 'Action'], ['namespace' => '*\Actions\*']],
            ],
            'service' => self::SERVICE,
        ],
        'policies' => [
            'controller' => [
                'may_not' => ['db'],
                'advice' => 'A controller hands the work to one action and turns its result into a response.',
            ],
            'action' => [
                'may_not_depend_on' => ['controller'],
                // One use case behind one entry point. The rest are the hooks
                // lorisleiva/laravel-actions calls when an action runs as a
                // controller, a job, a listener or a command.
                'public_methods' => [
                    'handle', 'execute', 'as*', 'get*', 'configure*', 'rules', 'authorize',
                    'prepareForValidation', 'withValidator', 'afterValidator', 'jsonResponse', 'htmlResponse',
                ],
                'advice' => 'Controllers, jobs and commands call actions; an action never calls back into a controller, and does one thing behind handle().',
            ],
            'model' => [
                'may_not_depend_on' => ['controller', 'action'],
                'advice' => 'Actions use models, not the other way round: keep models about data and relationships.',
            ],
        ],
    ];

    /**
     * Controllers call services, services call repositories, and only
     * repositories query.
     */
    private const array SERVICE_REPOSITORY = [
        'roles' => [
            'form-request' => self::FORM_REQUEST,
            'model' => self::MODEL,
            'controller' => self::CONTROLLER,
            'middleware' => self::MIDDLEWARE,
            'repository-contract' => [
                'description' => 'The interface a repository implements.',
                'kind' => 'interface',
                'suffix' => ['Repository', 'RepositoryInterface', 'RepositoryContract'],
                'intended_abstraction' => true,
            ],
            'repository' => [
                'description' => 'The only place that queries the database.',
                'kind' => 'class',
                'any' => [['suffix' => 'Repository'], ['namespace' => '*\Repositories\*']],
                'intended_abstraction' => true,
            ],
            'service' => self::SERVICE,
        ],
        'policies' => [
            'controller' => [
                'may_not' => ['db'],
                'may_not_depend_on' => ['repository'],
                'advice' => 'A controller calls a service, and the service calls the repository.',
            ],
            'service' => [
                'may_not' => ['db'],
                'may_not_depend_on' => ['controller'],
                'advice' => 'A service asks a repository for its data instead of querying the database itself.',
            ],
            'repository' => [
                'may_not_depend_on' => ['controller', 'service'],
                'advice' => 'A repository answers queries; it never calls up into a service or a controller.',
            ],
        ],
    ];

    /**
     * Domain, application and infrastructure layers, with Laravel's HTTP
     * classes as the delivery layer. Pragmatic: the domain may use Eloquent,
     * but never delivery or infrastructure.
     */
    private const array DDD = [
        'roles' => [
            'form-request' => self::FORM_REQUEST,
            'controller' => self::CONTROLLER,
            'middleware' => self::MIDDLEWARE,
            'domain' => ['description' => 'The business rules.', 'namespace' => '*\Domain\*'],
            'application' => ['description' => 'Use cases that orchestrate the domain.', 'namespace' => '*\Application\*'],
            'infrastructure' => ['description' => 'Persistence, queues and outside services.', 'namespace' => '*\Infrastructure\*'],
            'model' => self::MODEL,
            'service' => self::SERVICE,
        ],
        'policies' => [
            'domain' => [
                'may_not_depend_on' => ['application', 'infrastructure', 'controller', 'form-request', 'middleware'],
                'may_not' => ['http', 'request', 'env', 'view', 'container'],
                'advice' => 'The domain is called; it does not call out. Define an interface in the domain and implement it in infrastructure.',
            ],
            'application' => [
                'may_not_depend_on' => ['infrastructure', 'controller', 'form-request', 'middleware'],
                'may_not' => ['request', 'env', 'view'],
                'advice' => 'A use case takes plain data, not a request, and reaches infrastructure through an interface the container binds.',
            ],
        ],
    ];

    /**
     * Ports and adapters: a framework-free core that reaches the world only
     * through interfaces it owns.
     */
    private const array HEXAGONAL = [
        'roles' => [
            'form-request' => self::FORM_REQUEST,
            'controller' => self::CONTROLLER,
            'middleware' => self::MIDDLEWARE,
            'port' => [
                'description' => 'An interface the core owns and an adapter implements.',
                'kind' => 'interface',
                'namespace' => ['*\Domain\*', '*\Application\*', '*\Ports\*'],
                'intended_abstraction' => true,
            ],
            'adapter' => [
                'description' => 'Implements a port with the framework, a database or an outside service.',
                'namespace' => ['*\Infrastructure\*', '*\Adapters\*'],
                'intended_abstraction' => true,
            ],
            'domain' => ['description' => 'The business rules, in plain PHP.', 'namespace' => '*\Domain\*'],
            'application' => ['description' => 'Use cases, written against ports.', 'namespace' => '*\Application\*'],
            'model' => self::MODEL,
            'service' => self::SERVICE,
        ],
        'policies' => [
            'domain' => [
                'may_not_depend_on' => ['Illuminate\*', 'adapter', 'application', 'controller', 'form-request', 'middleware'],
                'may_not' => ['db', 'http', 'dispatch', 'request', 'env', 'view', 'container'],
                'advice' => 'The core is plain PHP. Reach the database, the queue or an outside service through a port the domain defines.',
            ],
            'port' => [
                'may_not_depend_on' => ['Illuminate\*', 'adapter'],
                'advice' => 'A port speaks the domain\'s language: its types come from the core, never from the framework.',
            ],
            'application' => [
                'may_not_depend_on' => ['adapter', 'controller', 'form-request', 'middleware'],
                'may_not' => ['db', 'http', 'request', 'env', 'view'],
                'advice' => 'A use case talks to ports; the container hands it the adapters.',
            ],
            'controller' => [
                'may_not' => ['db'],
                'may_not_depend_on' => ['adapter'],
                'advice' => 'A controller calls a use case and never reaches past it into an adapter.',
            ],
        ],
    ];

    /**
     * A modular monolith: each module sees another only through its public
     * surface.
     */
    private const array MODULAR = [
        'roles' => self::LARAVEL['roles'],
        'boundaries' => [
            'modules' => ['Modules\{module}\*', 'App\Modules\{module}\*'],
            'public' => ['Contracts\*', 'Events\*', 'Data\*', 'Enums\*'],
            'shared' => ['Modules\Shared\*', 'App\Modules\Shared\*'],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [self::DEFAULT, 'laravel-actions', 'service-repository', 'ddd', 'hexagonal', 'modular', 'none'];
    }

    /**
     * @return array{roles?: array<string, array<string, mixed>>, policies?: array<string, array<string, mixed>>, boundaries?: array<string, mixed>}
     */
    public static function definition(string $name): array
    {
        return match ($name) {
            'laravel' => self::LARAVEL,
            'laravel-actions' => self::LARAVEL_ACTIONS,
            'service-repository' => self::SERVICE_REPOSITORY,
            'ddd' => self::DDD,
            'hexagonal' => self::HEXAGONAL,
            'modular' => self::MODULAR,
            'none' => [],
            default => throw new ProfileException(sprintf(
                'sloppy.architecture.preset [%s] is not a preset. Use one of: %s.',
                $name,
                implode(', ', self::names()),
            )),
        };
    }
}
