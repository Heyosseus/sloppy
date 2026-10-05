<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\Presets;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Architecture\ProfileException;
use Heyosseus\Sloppy\Architecture\ProfileLoader;

/**
 * The `architecture` block of the published config/sloppy.php, which says
 * nothing a project chose.
 */
const PUBLISHED_ARCHITECTURE = ['preset' => 'laravel', 'roles' => [], 'policies' => []];

it('reads sloppy.architecture when the project keeps no profile file', function (): void {
    $root = tempProject(['composer.json' => '{}']);

    $profile = ProfileLoader::load(['preset' => 'ddd'], $root);

    expect($profile->preset)->toBe('ddd')
        ->and($profile->source)->not->toBe(Profile::FILE);

    removeTree($root);
});

it('falls back to the default preset when nothing is declared anywhere', function (): void {
    $root = tempProject(['composer.json' => '{}']);

    expect(ProfileLoader::load([], $root)->preset)->toBe(Presets::DEFAULT)
        ->and(ProfileLoader::load(PUBLISHED_ARCHITECTURE, $root)->preset)->toBe(Presets::DEFAULT);

    removeTree($root);
});

it('reads sloppy-architecture.php instead when the configuration says nothing of its own', function (): void {
    $root = tempProject([Profile::FILE => "<?php return ['preset' => 'hexagonal'];"]);

    $profile = ProfileLoader::load(PUBLISHED_ARCHITECTURE, $root);

    expect($profile->preset)->toBe('hexagonal')
        ->and($profile->source)->toBe(Profile::FILE)
        ->and(ProfileLoader::load([], $root)->preset)->toBe('hexagonal');

    removeTree($root);
});

it('refuses an architecture declared in both places', function (array $configured): void {
    $root = tempProject([Profile::FILE => "<?php return ['preset' => 'ddd'];"]);

    try {
        expect(static fn (): Profile => ProfileLoader::load($configured, $root))->toThrow(
            ProfileException::class,
            'The architecture is declared twice: in sloppy.architecture and in sloppy-architecture.php. Keep one of them.',
        );
    } finally {
        removeTree($root);
    }
})->with([
    'another preset' => [['preset' => 'modular']],
    'a role' => [['roles' => ['action' => ['suffix' => 'Action']]]],
    'a policy' => [['policies' => ['controller' => ['may_not' => 'db']]]],
    'boundaries' => [['boundaries' => ['modules' => 'App\{module}\*']]],
    'boundaries turned off' => [['boundaries' => false]],
    'covers' => [['covers' => ['app/*']]],
]);

it('does not count the default preset as a declaration however it is spelt', function (): void {
    // Profile reads the preset name loosely, so ' Laravel ' *is* the default
    // preset. Treating it as a second declaration refused a project whose
    // only sin was capitalisation.
    $root = tempProject([Profile::FILE => "<?php return ['preset' => 'ddd'];"]);

    expect(ProfileLoader::load(['preset' => ' Laravel '], $root)->preset)->toBe('ddd')
        ->and(ProfileLoader::declares(['preset' => 'LARAVEL']))->toBeFalse();

    removeTree($root);
});

it('refuses a configured architecture that is not an array, even beside a profile file', function (mixed $configured): void {
    $root = tempProject([Profile::FILE => "<?php return ['preset' => 'ddd'];"]);

    try {
        expect(static fn (): Profile => ProfileLoader::load($configured, $root))
            ->toThrow(ProfileException::class, 'sloppy.architecture must be an array with a preset, roles, or both.');
    } finally {
        removeTree($root);
    }
})->with([['laravel'], [null], [true]]);

it('refuses a profile file that returns no array, or an unusable one, naming the file', function (): void {
    $scalar = tempProject([Profile::FILE => "<?php return 'ddd';"]);
    $nothing = tempProject([Profile::FILE => '<?php ']);
    $unusable = tempProject([Profile::FILE => "<?php return ['preset' => 'symfony'];"]);

    try {
        expect(static fn (): Profile => ProfileLoader::load([], $scalar))
            ->toThrow(ProfileException::class, 'sloppy-architecture.php must return an array with a preset, roles, or both.')
            ->and(static fn (): Profile => ProfileLoader::load([], $nothing))
            ->toThrow(ProfileException::class, 'sloppy-architecture.php must return an array with a preset, roles, or both.')
            ->and(static fn (): Profile => ProfileLoader::load([], $unusable))
            ->toThrow(ProfileException::class, '[symfony] is not a preset');
    } finally {
        removeTree($scalar);
        removeTree($nothing);
        removeTree($unusable);
    }
});

it('keeps the profile file\'s variables to itself', function (): void {
    // The file is required in its own scope: a `$root` or `$configured` it
    // happens to define cannot leak into, or be read from, the loader.
    $root = tempProject([Profile::FILE => "<?php \$configured = ['preset' => 'ddd']; return ['preset' => isset(\$basePath) ? 'ddd' : 'modular'];"]);

    expect(ProfileLoader::load([], $root)->preset)->toBe('modular');

    removeTree($root);
});

it('says whether sloppy.architecture declares anything', function (mixed $configured, bool $declares): void {
    expect(ProfileLoader::declares($configured))->toBe($declares);
})->with([
    'nothing' => [null, false],
    'an empty array' => [[], false],
    'the published block' => [PUBLISHED_ARCHITECTURE, false],
    'the default preset alone' => [['preset' => 'laravel'], false],
    'a null key' => [['covers' => null], false],
    'another preset' => [['preset' => 'none'], true],
    'a role' => [['roles' => ['a' => ['suffix' => 'A']]], true],
    'boundaries off' => [['boundaries' => false], true],
    'a string' => ['ddd', true],
    'false' => [false, true],
]);
