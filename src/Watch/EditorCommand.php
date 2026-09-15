<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

/**
 * How to open a finding where the person can fix it.
 *
 * A dashboard that reports `app/Services/Report.php:40` and leaves you to
 * retype it has answered the easy half of the question. This turns a finding
 * into an argument list, in whichever dialect the configured editor speaks --
 * `+40 file` for the vi family, `-g file:40` for the VS Code family, and so on.
 *
 * It never guesses. With neither `VISUAL` nor `EDITOR` set, Enter does
 * nothing: launching an editor somebody did not choose, and may not know how
 * to leave, is worse than not launching one.
 */
final readonly class EditorCommand
{
    private const string PATH = '%s';

    private const string LINE = '%d';

    /**
     * Argument templates per editor, after the editor's own words.
     *
     * @var array<string, list<string>>
     */
    private const array DIALECTS = [
        'vim' => ['+'.self::LINE, self::PATH],
        'nvim' => ['+'.self::LINE, self::PATH],
        'vi' => ['+'.self::LINE, self::PATH],
        'nano' => ['+'.self::LINE, self::PATH],
        'emacs' => ['+'.self::LINE, self::PATH],
        'code' => ['-g', self::PATH.':'.self::LINE],
        'code-insiders' => ['-g', self::PATH.':'.self::LINE],
        'codium' => ['-g', self::PATH.':'.self::LINE],
        'cursor' => ['-g', self::PATH.':'.self::LINE],
        'windsurf' => ['-g', self::PATH.':'.self::LINE],
        'subl' => [self::PATH.':'.self::LINE],
        'sublime_text' => [self::PATH.':'.self::LINE],
        'mate' => ['-l', self::LINE, self::PATH],
        'phpstorm' => ['--line', self::LINE, self::PATH],
        'idea' => ['--line', self::LINE, self::PATH],
        'webstorm' => ['--line', self::LINE, self::PATH],
    ];

    /**
     * @param  list<string>  $words  The editor and any flags it was configured with.
     */
    private function __construct(private array $words) {}

    /**
     * Read the editor from an environment, `VISUAL` first as convention has it.
     *
     * @param  array<string, string>  $environment
     */
    public static function from(array $environment): self
    {
        $configured = trim($environment['VISUAL'] ?? '') !== ''
            ? $environment['VISUAL']
            : ($environment['EDITOR'] ?? '');

        return new self(self::words($configured));
    }

    /**
     * Split an `EDITOR` value the way the convention does: on whitespace, but
     * respecting quotes -- without which no path under `Program Files` would
     * survive being read.
     *
     * @return list<string>
     */
    private static function words(string $configured): array
    {
        preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', trim($configured), $matches, PREG_SET_ORDER);

        $words = [];

        foreach ($matches as $match) {
            $word = $match[3] ?? '';

            if ($word === '') {
                $word = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
            }

            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * The command to run, or null when there is no editor to run it with.
     *
     * @return list<string>|null
     */
    public function for(string $path, int $line): ?array
    {
        if ($this->words === []) {
            return null;
        }

        $arguments = [];

        foreach (self::DIALECTS[$this->dialect()] ?? [self::PATH] as $template) {
            $arguments[] = str_replace([self::PATH, self::LINE], [$path, (string) $line], $template);
        }

        return [...$this->words, ...$arguments];
    }

    /**
     * Which editor this is, ignoring where it lives and what Windows calls it.
     */
    private function dialect(): string
    {
        $name = strtolower(basename(str_replace('\\', '/', $this->words[0])));

        foreach (['.exe', '.cmd', '.bat'] as $extension) {
            if (str_ends_with($name, $extension)) {
                return substr($name, 0, -strlen($extension));
            }
        }

        return $name;
    }
}
