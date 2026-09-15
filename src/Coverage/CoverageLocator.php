<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Coverage;

/**
 * Where the coverage report is, if there is one.
 *
 * An explicit `--coverage=` beats the configured path, which beats the places
 * PHPUnit and Pest write by default. Nothing here is required: when no report
 * exists the result is an empty map and the risk model is exactly what it was
 * before this feature existed.
 */
final readonly class CoverageLocator
{
    /**
     * @var list<string>
     */
    private const array AUTODETECT = [
        'build/logs/clover.xml',
        'coverage.xml',
        'build/coverage/clover.xml',
        'coverage/clover.xml',
        'build/logs/cobertura.xml',
    ];

    public function __construct(private string $basePath) {}

    public function locate(?string $explicit = null, ?string $configured = null): CoverageMap
    {
        foreach ([$explicit, $configured] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $this->read($this->absolute($candidate));
            }
        }

        foreach (self::AUTODETECT as $candidate) {
            $path = $this->absolute($candidate);

            if (is_file($path)) {
                return $this->read($path);
            }
        }

        return CoverageMap::empty();
    }

    /**
     * Cobertura and Clover are told apart by content rather than by filename,
     * because CI configurations name these anything at all.
     */
    private function read(string $path): CoverageMap
    {
        if (! is_file($path)) {
            return CoverageMap::empty();
        }

        $head = (string) file_get_contents($path, false, null, 0, 2048);

        return str_contains($head, '<packages') || str_contains($head, 'line-rate=')
            ? CoberturaReader::read($path, $this->basePath)
            : CloverReader::read($path, $this->basePath);
    }

    private function absolute(string $path): string
    {
        $normalised = str_replace('\\', '/', $path);

        if (str_starts_with($normalised, '/') || preg_match('#^[A-Za-z]:/#', $normalised) === 1) {
            return $normalised;
        }

        return rtrim(str_replace('\\', '/', $this->basePath), '/').'/'.ltrim($normalised, '/');
    }
}
