<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

use Heyosseus\Sloppy\Ast\ParsedFile;
use Heyosseus\Sloppy\Ast\Parser;

/**
 * The project's parsed files, kept between ticks.
 *
 * An analysis is two passes -- parse every file and build the index, then run
 * every rule over every file -- and parsing is the expensive half. A watch
 * loop that re-read the whole tree on each keystroke would spend all of its
 * time re-parsing files nobody touched.
 *
 * So this keeps each {@see ParsedFile} against the stamp it was parsed at, and
 * re-parses only what moved. Every file is still handed to the analyser, and
 * every rule still runs over all of them, which is what keeps a tick's numbers
 * identical to a full `sloppy scan` of the same tree. The saving is in the
 * parsing, not in the reporting -- reporting less is how cross-file rules
 * start lying.
 */
final class ParsedFileCache
{
    /** @var array<string, array{stamp: string, file: ParsedFile}> */
    private array $entries = [];

    public function __construct(private readonly Parser $parser = new Parser) {}

    /**
     * The whole project, parsed, re-reading only the files whose stamp moved.
     *
     * @param  array<string, string>  $fileMap  Relative path => absolute path.
     */
    public function sync(TreeState $state, array $fileMap): ParsedProject
    {
        $files = [];
        $errors = [];

        foreach ($fileMap as $relative => $absolute) {
            $file = $this->parsed($relative, $absolute, $state->stamps[$relative] ?? '');

            if ($file->isParsed()) {
                $files[] = $file;

                continue;
            }

            $errors[$relative] = $file->parseError ?? 'Unknown parse error.';
        }

        // A file that left the project must not sit in memory for the life of
        // the process, and must not come back if a later tick re-creates it
        // with the stamp it had when it was deleted.
        $this->entries = array_intersect_key($this->entries, $fileMap);

        return new ParsedProject($files, $errors);
    }

    /**
     * Drop everything, so the next sync re-reads the tree from disk. This is
     * what `r` does: the one escape hatch for an edit the stamps missed.
     */
    public function forget(): void
    {
        $this->entries = [];
    }

    private function parsed(string $relative, string $absolute, string $stamp): ParsedFile
    {
        $entry = $this->entries[$relative] ?? null;

        if ($entry !== null && $entry['stamp'] === $stamp) {
            return $entry['file'];
        }

        $file = $this->parser->parseFile($absolute, $relative);

        $this->entries[$relative] = ['stamp' => $stamp, 'file' => $file];

        return $file;
    }
}
