<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

use InvalidArgumentException;
use stdClass;

/**
 * Adding our hooks to an agent's settings file somebody else owns.
 *
 * `.claude/settings.json`, `.gemini/settings.json` and `.codex/hooks.json` all
 * map events to matcher groups of commands, and usually already hold
 * permissions, environment and other people's hooks. Every one of them survives: ours are found by their
 * command and replaced in place, so installing twice, or after moving from a
 * phar to a Composer install, leaves exactly one of each. The rest of the file
 * keeps its bytes: see {@see JsonDocument}.
 */
final readonly class HookSettingsFile
{
    /**
     * The new contents of the file, with our hooks in it.
     *
     * @param  string  $command  How to run Sloppy, without the `hook <event>` part.
     * @param  AgentHost  $host  The agent whose events and tool names to write.
     *
     * @throws InvalidArgumentException When the existing file is not a settings object.
     */
    public static function merge(?string $existing, string $command, AgentHost $host = AgentHost::ClaudeCode): string
    {
        $document = JsonDocument::parse($existing);
        $settings = $document->data;
        $hooks = $settings->hooks ?? new stdClass;

        if (! $hooks instanceof stdClass) {
            throw new InvalidArgumentException('"hooks" is not an object.');
        }

        $ours = [
            $host->editEvent() => (object) [
                'matcher' => $host->editTools(),
                'hooks' => [self::hook($host->hookCommand($command, HookEvent::PostEdit))],
            ],
            $host->stopEvent() => (object) [
                'hooks' => [self::hook($host->hookCommand($command, HookEvent::Stop))],
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

        return $document->render();
    }

    /**
     * The file with our hooks taken out, and everything else as it was.
     *
     * An event list left empty is dropped, and so is a `hooks` object left
     * empty, so a file the installer added them to reads as it did before.
     *
     * @return string|null The new contents; '' when nothing at all is left, null when none of our hooks were there.
     *
     * @throws InvalidArgumentException When the existing file is not a settings object.
     */
    public static function remove(string $existing): ?string
    {
        $document = JsonDocument::parse($existing);
        $hooks = $document->data->hooks ?? null;

        if (! $hooks instanceof stdClass) {
            return null;
        }

        $removed = false;

        foreach (get_object_vars($hooks) as $event => $groups) {
            if (! is_array($groups) || ! self::holdsOurs($groups)) {
                continue;
            }

            $removed = true;
            $kept = self::withoutOurs($groups);

            if ($kept === []) {
                unset($hooks->{$event});
            } else {
                $hooks->{$event} = $kept;
            }
        }

        if (! $removed) {
            return null;
        }

        if (get_object_vars($hooks) === []) {
            unset($document->data->hooks);
        }

        return $document->isEmpty() ? '' : $document->render();
    }

    /**
     * Whether a hook command is one this installer wrote, wherever the binary
     * lived at the time.
     */
    public static function isSloppyHook(string $command): bool
    {
        return preg_match('/sloppy[^\s"]*"?\s+hook\s+(?:post-edit|stop)\b/i', $command) === 1;
    }

    /**
     * @param  array<mixed>  $groups
     */
    private static function holdsOurs(array $groups): bool
    {
        foreach ($groups as $group) {
            foreach ($group instanceof stdClass && is_array($group->hooks ?? null) ? $group->hooks : [] as $hook) {
                if ($hook instanceof stdClass && is_string($hook->command ?? null) && self::isSloppyHook($hook->command)) {
                    return true;
                }
            }
        }

        return false;
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

    private static function hook(string $command): stdClass
    {
        return (object) ['type' => 'command', 'command' => $command];
    }
}
