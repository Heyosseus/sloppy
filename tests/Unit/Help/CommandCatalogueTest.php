<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SloppyApplication;
use Heyosseus\Sloppy\Help\CommandCatalogue;
use Heyosseus\Sloppy\Help\CommandSummary;
use Symfony\Component\Console\Command\Command;

/**
 * Every command name the standalone binary answers to, without the ones
 * Symfony defines for itself and the hidden plumbing nobody types -- `hook`
 * is written into an agent's settings, not reached for.
 *
 * @return list<string>
 */
function registeredCliCommands(): array
{
    $visible = array_filter(
        (new SloppyApplication('test'))->all(),
        static fn (Command $command): bool => ! $command->isHidden(),
    );

    return array_values(array_diff(array_keys($visible), ['help', 'list', '_complete', 'completion']));
}

it('covers every command the standalone binary registers', function (): void {
    $catalogued = array_map(
        static fn (CommandSummary $summary): string => $summary->cli,
        CommandCatalogue::entries(),
    );

    sort($catalogued);
    $registered = registeredCliCommands();
    sort($registered);

    // A tenth command added without an entry here fails in the suite rather
    // than in review, which is the whole reason the catalogue is allowed to
    // hold its own wording.
    expect($catalogued)->toBe($registered);
});

it('names the Artisan command each entry has on the other surface', function (): void {
    $signatures = [];

    foreach (glob(dirname(__DIR__, 3).'/src/Console/Commands/Sloppy*Command.php') ?: [] as $file) {
        if (preg_match('/signature = \'(sloppy[a-z:]*)/', (string) file_get_contents($file), $matches) === 1) {
            $signatures[] = $matches[1];
        }
    }

    $catalogued = array_map(
        static fn (CommandSummary $summary): string => $summary->artisan,
        CommandCatalogue::entries(),
    );

    sort($catalogued);
    sort($signatures);

    expect($catalogued)->toBe($signatures);
});

it('says what each command is for and when to reach for it', function (): void {
    foreach (CommandCatalogue::entries() as $summary) {
        expect($summary->description)->not->toBe('')
            ->and($summary->description)->toEndWith('.')
            ->and($summary->when)->not->toBe('')
            ->and($summary->when)->toEndWith('.');
    }
});
