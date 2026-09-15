<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

use Heyosseus\Sloppy\Analysis\Analyzer;
use Heyosseus\Sloppy\Ast\ParsedFile;

/**
 * Every file in the project, parsed, plus the ones that would not parse.
 *
 * These are the two things {@see Analyzer::analyzeParsed()} needs, and a tick
 * produces both in one pass, so they travel together rather than as a pair of
 * out-parameters.
 */
final readonly class ParsedProject
{
    /**
     * @param  list<ParsedFile>  $files  Files that parsed.
     * @param  array<string, string>  $errors  Relative path => why it did not.
     */
    public function __construct(
        public array $files = [],
        public array $errors = [],
    ) {}
}
