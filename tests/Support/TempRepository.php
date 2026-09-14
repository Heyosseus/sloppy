<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests\Support;

use Heyosseus\Sloppy\Git\Git;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A throwaway git repository, so diff mode is tested against real git output
 * rather than a hand-written fake of it.
 */
final readonly class TempRepository
{
    private function __construct(public string $path) {}

    public static function create(): self
    {
        $path = str_replace('\\', '/', sys_get_temp_dir()).'/sloppy-repo-'.bin2hex(random_bytes(6));

        if (! mkdir($path, 0o777, true) && ! is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create [%s].', $path));
        }

        $repository = new self($path);

        $repository->git(['init', '--initial-branch=main']);
        $repository->git(['config', 'user.email', 'tests@example.com']);
        $repository->git(['config', 'user.name', 'Sloppy Tests']);
        $repository->git(['config', 'commit.gpgsign', 'false']);

        return $repository;
    }

    /**
     * Whether git is usable here at all, so tests can skip rather than fail on
     * a machine without it.
     */
    public static function gitIsAvailable(): bool
    {
        $process = new Process(['git', '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    public function write(string $relativePath, string $contents): self
    {
        $full = $this->path.'/'.$relativePath;
        $directory = dirname($full);

        if (! is_dir($directory) && ! mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create [%s].', $directory));
        }

        file_put_contents($full, $contents);

        return $this;
    }

    public function delete(string $relativePath): self
    {
        $full = $this->path.'/'.$relativePath;

        if (is_file($full)) {
            unlink($full);
        }

        return $this;
    }

    public function commit(string $message): self
    {
        $this->git(['add', '--all']);
        $this->git(['commit', '--no-gpg-sign', '-m', $message]);

        return $this;
    }

    public function client(): Git
    {
        return new Git($this->path);
    }

    /**
     * @param  list<string>  $args
     */
    public function git(array $args): string
    {
        $process = new Process(['git', ...$args], $this->path);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'git %s failed in the test repository: %s',
                implode(' ', $args),
                $process->getErrorOutput(),
            ));
        }

        return $process->getOutput();
    }

    public function remove(): void
    {
        TempTree::remove($this->path);
    }
}
