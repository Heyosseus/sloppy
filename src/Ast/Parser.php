<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

use PhpParser\Error;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\Parser as PhpParser;
use PhpParser\ParserFactory;

/**
 * Wraps nikic/php-parser and applies the two traversals every rule depends on.
 *
 * `NameResolver` gives nodes fully qualified names, so a rule can tell
 * `Illuminate\Support\Facades\Http` from a local `Http` class without guessing
 * from imports. `NodeConnectingVisitor` adds parent/previous/next links, so a
 * rule can ask "am I inside a loop?" without re-walking from the root.
 */
final readonly class Parser
{
    private PhpParser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    /**
     * Parse source text. A file that will not parse comes back as a
     * `ParsedFile` carrying the error rather than throwing: one broken file
     * should not abort a whole-project run.
     */
    public function parse(string $relativePath, string $source, string $absolutePath = ''): ParsedFile
    {
        try {
            $ast = $this->parser->parse($source) ?? [];
        } catch (Error $error) {
            return new ParsedFile(
                relativePath: $relativePath,
                absolutePath: $absolutePath !== '' ? $absolutePath : $relativePath,
                source: $source,
                ast: [],
                parseError: $error->getMessage(),
            );
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor(new NodeConnectingVisitor);

        /** @var list<Stmt> $resolved */
        $resolved = $traverser->traverse($ast);

        return new ParsedFile(
            relativePath: $relativePath,
            absolutePath: $absolutePath !== '' ? $absolutePath : $relativePath,
            source: $source,
            ast: $resolved,
        );
    }

    /**
     * Read and parse a file from disk.
     */
    public function parseFile(string $absolutePath, string $relativePath): ParsedFile
    {
        $source = @file_get_contents($absolutePath);

        if ($source === false) {
            return new ParsedFile(
                relativePath: $relativePath,
                absolutePath: $absolutePath,
                source: '',
                ast: [],
                parseError: 'File could not be read.',
            );
        }

        return $this->parse($relativePath, $source, $absolutePath);
    }
}
