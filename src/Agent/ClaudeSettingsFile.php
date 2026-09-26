<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Adding our hooks to a Claude Code settings file somebody else owns.
 *
 * `.claude/settings.json` usually already holds permissions, environment and
 * other people's hooks. Every one of them survives: ours are found by their
 * command and replaced in place, so installing twice, or after moving from a
 * phar to a Composer install, leaves exactly one of each.
 *
 * The file is decoded to objects rather than arrays on purpose. An empty
 * `"env": {}` decoded to an array comes back out as `[]`, which Claude Code
 * then refuses to load -- a settings writer that breaks settings.
 */
final readonly class ClaudeSettingsFile
{
    private const string EDIT_TOOLS = 'Edit|Write|MultiEdit';

    /**
     * The new contents of the file, with our hooks in it.
     *
     * @param  string  $command  How to run Sloppy, without the `hook <event>` part.
     *
     * @throws InvalidArgumentException When the existing file is not a settings object.
     */
    public static function merge(?string $existing, string $command): string
    {
        $settings = self::decode($existing);
        $hooks = $settings->hooks ?? new stdClass;

        if (! $hooks instanceof stdClass) {
            throw new InvalidArgumentException('"hooks" is not an object.');
        }

        $ours = [
            'PostToolUse' => (object) [
                'matcher' => self::EDIT_TOOLS,
                'hooks' => [self::hook($command, HookEvent::PostEdit)],
            ],
            'Stop' => (object) [
                'hooks' => [self::hook($command, HookEvent::Stop)],
            ],
        ];

        foreach ($ours as $event => $group) {
            $groups = $hooks->{$event} ?? [];

            if (! is_array($groups)) {
                throw new InvalidArgumentException(sprintf('"hooks.%s" is not a list.', $event));
            }

            $hooks->{$event} = [...self::withoutOurs($groups), $group];
        }

        $settings->hooks = $hooks;

        return self::encode($settings);
    }

    /**
     * Whether a hook command is one this installer wrote, wherever the binary
     * lived at the time.
     */
    public static function isSloppyHook(string $command): bool
    {
        return preg_match('/sloppy[^\s"]*"?\s+hook\s+(?:post-edit|stop)\b/i', $command) === 1;
    }

    private static function decode(?string $existing): stdClass
    {
        if ($existing === null || trim($existing) === '') {
            return new stdClass;
        }

        try {
            $decoded = json_decode($existing, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('It is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! $decoded instanceof stdClass) {
            throw new InvalidArgumentException('It is not a JSON object.');
        }

        return $decoded;
    }

    /**
     * An event's groups with our hooks taken out, and any group that held
     * nothing else dropped. Anything shaped unlike a group is kept as found:
     * it is not ours to judge.
     *
     * @param  array<mixed>  $groups
     * @return list<mixed>
     */
    private static function withoutOurs(array $groups): array
    {
        $kept = [];

        foreach ($groups as $group) {
            if (! $group instanceof stdClass || ! is_array($group->hooks ?? null)) {
                $kept[] = $group;

                continue;
            }

            $remaining = array_values(array_filter(
                $group->hooks,
                static fn (mixed $hook): bool => ! ($hook instanceof stdClass
                    && is_string($hook->command ?? null)
                    && self::isSloppyHook($hook->command)),
            ));

            if ($remaining === []) {
                continue;
            }

            $group->hooks = $remaining;
            $kept[] = $group;
        }

        return $kept;
    }

    private static function hook(string $command, HookEvent $event): stdClass
    {
        return (object) ['type' => 'command', 'command' => $command.' hook '.$event->value];
    }

    /**
     * Pretty-printed with two-space indentation, which is how Claude Code and
     * most editors write this file, so a reinstall does not reformat it.
     */
    private static function encode(stdClass $settings): string
    {
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $indented = preg_replace_callback(
            '/^(?: {4})+/m',
            static fn (array $indent): string => str_repeat('  ', intdiv(strlen($indent[0]), 4)),
            $json,
        );

        return ($indented ?? $json)."\n";
    }
}
