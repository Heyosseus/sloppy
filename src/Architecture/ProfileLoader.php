<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * Finds a project's architecture: `sloppy-architecture.php` in the project
 * root when there is one, otherwise `sloppy.architecture`, or the Laravel
 * preset when neither says anything.
 *
 * The separate file is what `sloppy architecture init` and `import` write: a
 * generated profile is easier to review, regenerate and own as a file of its
 * own than as an edit to a configuration file a person wrote.
 */
final readonly class ProfileLoader
{
    /**
     * @param  mixed  $configured  The `sloppy.architecture` value.
     *
     * @throws ProfileException When the profile cannot be used as written.
     */
    public static function load(mixed $configured, string $basePath): Profile
    {
        if (! is_array($configured)) {
            throw new ProfileException('sloppy.architecture must be an array with a preset, roles, or both.');
        }

        $file = $basePath.'/'.Profile::FILE;

        if (! is_file($file)) {
            return Profile::fromArray($configured);
        }

        if (self::declares($configured)) {
            throw new ProfileException(sprintf(
                'The architecture is declared twice: in sloppy.architecture and in %s. Keep one of them.',
                Profile::FILE,
            ));
        }

        $declared = (static fn (string $path): mixed => require $path)($file);

        if (! is_array($declared)) {
            throw new ProfileException(sprintf('%s must return an array with a preset, roles, or both.', Profile::FILE));
        }

        return Profile::fromArray($declared, Profile::FILE);
    }

    /**
     * Whether `sloppy.architecture` says more than the published
     * configuration does: anything but the default preset and empty lists.
     */
    public static function declares(mixed $configured): bool
    {
        if (! is_array($configured)) {
            return $configured !== null;
        }

        foreach ($configured as $key => $setting) {
            // The preset is compared the way Profile reads it, trimmed and in
            // any case, so ' Laravel ' is the default it means.
            $default = $key === 'preset'
                ? is_string($setting) && mb_strtolower(trim($setting)) === Presets::DEFAULT
                : $setting === [] || $setting === null;

            if (! $default) {
                return true;
            }
        }

        return false;
    }
}
