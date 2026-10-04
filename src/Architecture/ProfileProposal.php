<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * An architecture worked out for a project -- inferred from the code or
 * translated from deptrac -- before anyone has agreed to it.
 *
 * It is written out as `sloppy-architecture.php`, so it is reviewed, diffed
 * and committed like any other change, and it is loaded through the same
 * validation as a profile written by hand.
 */
final readonly class ProfileProposal
{
    /**
     * @param  array<string, mixed>  $profile  The `sloppy.architecture` array.
     * @param  list<string>  $notes  How it was worked out, one line each.
     * @param  array<string, int>  $unclear  Namespaces with many classes and no role => their class count.
     */
    public function __construct(
        public array $profile,
        public array $notes = [],
        public array $unclear = [],
    ) {}

    /**
     * The proposal with one more role, tried before the rest.
     *
     * @param  array<string, mixed>  $definition
     */
    public function withRole(string $name, array $definition, string $note): self
    {
        $roles = $this->profile['roles'] ?? [];

        return new self(
            [...$this->profile, 'roles' => [$name => $definition, ...(is_array($roles) ? $roles : [])]],
            [...$this->notes, $note],
            $this->unclear,
        );
    }

    /**
     * Throws a {@see ProfileException} if the proposal would not load.
     */
    public function validate(): Profile
    {
        return Profile::fromArray($this->profile, Profile::FILE);
    }

    public function php(string $by): string
    {
        // Notes quote names from other people's files -- a deptrac layer, a
        // namespace -- and this file is required on every run. A line break,
        // or PHP's closing tag, in one would end the comment and start code.
        $comments = array_map(
            static fn (string $note): string => '// - '.str_replace(["\r", "\n", '?>'], [' ', ' ', '? >'], $note),
            $this->notes,
        );

        return implode("\n", [
            '<?php',
            '',
            sprintf('// Written by `%s`. Review it and commit it: Sloppy reads the', $by),
            '// architecture from this file instead of sloppy.architecture. Run',
            '// `vendor/bin/sloppy architecture` to see every class\'s role.',
            '//',
            ...$comments,
            '',
            'return '.self::export($this->profile, 0).';',
            '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['profile' => $this->profile, 'notes' => $this->notes, 'unclear' => $this->unclear];
    }

    /**
     * Short-array PHP, indented the way the published configuration is, with
     * namespace backslashes left single where PHP allows it.
     */
    private static function export(mixed $value, int $depth): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $indent = str_repeat('    ', $depth + 1);
            $list = array_is_list($value);
            $lines = [];

            /** @var mixed $item */
            foreach ($value as $key => $item) {
                $lines[] = $indent.($list ? '' : self::export($key, $depth).' => ').self::export($item, $depth + 1).',';
            }

            return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
        }

        return match (true) {
            is_string($value) => "'".self::escape($value)."'",
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            default => 'null',
        };
    }

    /**
     * Inside single quotes only a quote, and a backslash before a quote, a
     * backslash or the closing quote, need escaping.
     */
    private static function escape(string $text): string
    {
        $escaped = '';
        $length = strlen($text);

        for ($position = 0; $position < $length; $position++) {
            $character = $text[$position];
            $next = $text[$position + 1] ?? '';

            $escaped .= match (true) {
                $character === "'" => "\\'",
                $character === '\\' && (in_array($next, ['', '\\', "'"], true)) => '\\\\',
                default => $character,
            };
        }

        return $escaped;
    }
}
