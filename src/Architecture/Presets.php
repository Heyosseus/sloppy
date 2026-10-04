<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * Role definitions for architectures common enough to ship.
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

    /**
     * The roles Sloppy has always assumed in a Laravel application. The rules
     * read these names: SL201, SL202 and SL206 look at `controller`, SL207 at
     * `service`, and SL208 at `controller`, `model`, `form-request` and
     * `middleware`.
     *
     * @var array<string, array<string, mixed>>
     */
    private const array LARAVEL = [
        'form-request' => [
            'description' => 'Validates and authorises one request.',
            'parent' => '*FormRequest',
        ],
        'model' => [
            'description' => 'An Eloquent model: data and its relationships.',
            'kind' => 'class',
            'parent' => [
                \Illuminate\Foundation\Auth\User::class,
                \Illuminate\Database\Eloquent\Relations\Pivot::class,
                '*\Model',
                'Model',
            ],
        ],
        'controller' => [
            'description' => 'Turns an HTTP request into a call and a response.',
            'kind' => 'class',
            'any' => [
                ['namespace' => '*Http\Controllers*'],
                ['suffix' => 'Controller', 'not' => ['suffix' => 'TestController']],
                ['parent' => '*Controller'],
            ],
        ],
        'middleware' => [
            'description' => 'Wraps requests on their way in and out.',
            'namespace' => '*\Middleware\*',
        ],
        'service' => [
            'description' => 'Where behaviour is supposed to live: services, actions, handlers and the domain.',
            'kind' => 'class',
            'any' => [
                ['suffix' => ['Service', 'Action', 'Manager', 'Handler', 'Repository', 'UseCase', 'Interactor']],
                ['namespace' => ['*\Services\*', '*\Actions\*', '*\Domain\*', '*\Application\*']],
            ],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [self::DEFAULT, 'none'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function roles(string $name): array
    {
        return match ($name) {
            'laravel' => self::LARAVEL,
            'none' => [],
            default => throw new ProfileException(sprintf(
                'sloppy.architecture.preset [%s] is not a preset. Use one of: %s.',
                $name,
                implode(', ', self::names()),
            )),
        };
    }
}
