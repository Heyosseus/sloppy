<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

use Heyosseus\Sloppy\Ast\NodeHelper;

/**
 * Which modules may see which: a class may use another module only through
 * that module's public surface, or through the shared kernel.
 *
 *     'boundaries' => [
 *         'modules' => 'App\Modules\{module}\*',
 *         'public' => ['Contracts\*', 'Events\*'],
 *         'shared' => ['App\Modules\Shared\*'],
 *     ],
 *
 * `{module}` captures the module's name, so every module is covered without
 * being listed -- including the one added next month.
 */
final readonly class Boundaries
{
    /**
     * @param  list<string>  $modules  Patterns with one `{module}`.
     * @param  list<Glob>  $public  Relative to a module's root.
     * @param  list<Glob>  $shared  Fully qualified.
     */
    public function __construct(
        public string $origin,
        public array $modules,
        public array $public = [],
        public array $shared = [],
    ) {}

    public function moduleOf(string $fqn): ?Module
    {
        foreach ($this->modules as $pattern) {
            [$before, $after] = explode('{module}', $pattern, 2);
            $expression = '/^('.$this->quote($before).')([^\\\\]+)'.$this->quote($after).'$/s';

            if (preg_match($expression, $fqn, $match) === 1) {
                return new Module($match[2], $match[1].$match[2].'\\');
            }
        }

        return null;
    }

    /**
     * Why a class may not reach another, or null when it may.
     */
    public function violation(string $from, string $to): ?string
    {
        $source = $this->moduleOf($from);
        $target = $this->moduleOf($to);

        if (! $source instanceof Module || ! $target instanceof Module || $source->name === $target->name) {
            return null;
        }

        foreach ($this->shared as $shared) {
            if ($shared->matches($to)) {
                return null;
            }
        }

        $relative = $target->relativeName($to);

        foreach ($this->public as $public) {
            if ($public->matches($relative)) {
                return null;
            }
        }

        return sprintf(
            '%s is internal to the %s module, and %s is in %s',
            $relative,
            $target->name,
            NodeHelper::baseName($from),
            $source->name,
        );
    }

    /**
     * The boundaries in a line, for `sloppy architecture`.
     */
    public function describe(): string
    {
        $shared = implode(', ', array_map(static fn (Glob $glob): string => $glob->pattern, $this->shared));

        return sprintf(
            'modules %s; public %s; shared %s',
            implode(', ', $this->modules),
            $this->public === [] ? 'nothing' : $this->publicSurface(),
            $shared === '' ? 'nothing' : $shared,
        );
    }

    /**
     * The public surface, for a suggestion: `Contracts\*, Events\*`.
     */
    public function publicSurface(): string
    {
        return implode(', ', array_map(static fn (Glob $glob): string => $glob->pattern, $this->public));
    }

    private function quote(string $text): string
    {
        return implode('.*', array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            explode('*', $text),
        ));
    }
}
