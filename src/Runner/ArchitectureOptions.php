<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Architecture\DependencyGraph;
use Heyosseus\Sloppy\Output\OutputFormat;
use InvalidArgumentException;

/**
 * What `sloppy architecture` was asked: the whole project, one class, or one
 * of the subcommands.
 *
 *     sloppy architecture                         every role, policy and boundary
 *     sloppy architecture OrderController         one class explained
 *     sloppy architecture graph --format=dot      which roles depend on which
 *     sloppy architecture place "refund an order" where a new class belongs
 *     sloppy architecture init                    a profile inferred from the code
 *     sloppy architecture import deptrac.yaml     a profile translated from deptrac
 *     sloppy architecture prompt                  what an agent needs to write one
 */
final readonly class ArchitectureOptions
{
    public const string SHOW = 'show';

    /**
     * Subcommand words. Lower case, so they cannot be mistaken for a class.
     */
    public const array ACTIONS = ['graph', 'place', 'init', 'import', 'prompt'];

    /**
     * @param  string|null  $class  A fully qualified or short class name to explain; null for the overview.
     * @param  string|null  $query  What `place` was asked, or the file `import` reads.
     * @param  string|null  $name  The name `place` should suggest a class for.
     * @param  string  $graphFormat  mermaid, dot or json.
     * @param  bool  $write  Write the proposal from `init` or `import` without asking.
     * @param  bool  $force  Replace an existing sloppy-architecture.php.
     */
    public function __construct(
        public ?string $class = null,
        public OutputFormat $format = OutputFormat::Console,
        public string $action = self::SHOW,
        public ?string $query = null,
        public ?string $name = null,
        public string $graphFormat = 'mermaid',
        public bool $write = false,
        public bool $force = false,
    ) {
        if (! in_array($format, [OutputFormat::Console, OutputFormat::Json], true)) {
            throw new InvalidArgumentException(sprintf(
                'sloppy architecture writes console or json, not %s.',
                $format->value,
            ));
        }
    }

    /**
     * Read the command line both binaries share.
     *
     * @param  list<string>  $words  What followed the subcommand.
     */
    public static function parse(?string $subject, array $words, ?string $format, ?string $name = null, bool $write = false, bool $force = false): self
    {
        $subject = $subject === null || trim($subject) === '' ? null : trim($subject);
        $action = in_array($subject, self::ACTIONS, true) ? (string) $subject : self::SHOW;
        $query = trim(implode(' ', $words));

        if ($action === 'graph') {
            $graphFormat = mb_strtolower(trim($format ?? 'mermaid'));

            if (! in_array($graphFormat, DependencyGraph::FORMATS, true)) {
                throw new InvalidArgumentException(sprintf('sloppy architecture graph writes %s, not %s.', implode(', ', DependencyGraph::FORMATS), $graphFormat));
            }

            return new self(action: $action, graphFormat: $graphFormat);
        }

        if ($action === 'place' && $query === '') {
            throw new InvalidArgumentException('Say what the class does: sloppy architecture place "an action that refunds an order".');
        }

        return new self(
            class: $action === self::SHOW ? $subject : null,
            format: OutputFormat::parse($format ?? 'console'),
            action: $action,
            query: $query === '' ? null : $query,
            name: $name === null || trim($name) === '' ? null : trim($name),
            write: $write,
            force: $force,
        );
    }
}
