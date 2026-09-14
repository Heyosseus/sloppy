<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SloppyApplication;
use Heyosseus\Sloppy\Help\CommandCatalogue;
use Heyosseus\Sloppy\Help\CommandSummary;

/**
 * Every command name the standalone binary answers to, without the two
 * Symfony defines for itself.
 *
 * @return list<string>
 */
function registeredCliCommands(): array
{
    $names = array_keys((new SloppyApplication('test'))->all());

    return array_values(array_diff($names, ['help', 'list', '_complete', 'completion']));
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
