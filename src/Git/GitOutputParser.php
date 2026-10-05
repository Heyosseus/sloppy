<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

/**
 * Reads the plumbing output `Git` asks for: unified diffs, `--name-status -z`
 * listings and `cat-file --batch` answers.
 *
 * Nothing here runs git. Each method takes what a process printed and turns
 * it into the package's own types, so the shape of git's output is known in
 * one place and can be exercised without a repository.
 */
final readonly class GitOutputParser
{
    /**
     * The added line ranges of every file a `--unified=0` diff names.
     *
     * @return array<string, list<DiffHunk>>
     */
    public function hunks(string $diff): array
    {
        $hunks = [];
        $path = null;

        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, '+++ ')) {
                $path = $this->targetPath(substr($line, 4));

                continue;
            }

            if ($path === null || ! str_starts_with($line, '@@') || preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches) !== 1) {
                continue;
            }

            $hunks[$path][] = new DiffHunk(
                startLine: (int) $matches[1],
                lineCount: isset($matches[2]) ? (int) $matches[2] : 1,
            );
        }

        return $hunks;
    }

    /**
     * Read `--name-status -z`: a status, then one path -- or two, for a
     * rename or copy -- each terminated by a NUL, so no path is ever quoted
     * or split on whitespace.
     *
     * @return list<ChangedFile>
     */
    public function nameStatus(string $output): array
    {
        $files = [];
        $fields = explode("\0", $output);
        $count = count($fields);

        for ($i = 0; $i < $count; $i++) {
            $code = trim($fields[$i]);

            if ($code === '') {
                continue;
            }

            $twoPaths = str_starts_with($code, 'R') || str_starts_with($code, 'C');
            $first = $fields[$i + 1] ?? null;
            $second = $twoPaths ? ($fields[$i + 2] ?? null) : null;
            $i += $twoPaths ? 2 : 1;

            if ($first === null || $first === '') {
                continue;
            }

            $status = match (true) {
                str_starts_with($code, 'A'), str_starts_with($code, 'C') => 'added',
                str_starts_with($code, 'D') => 'deleted',
                str_starts_with($code, 'R') => 'renamed',
                default => 'modified',
            };

            $files[] = $status === 'renamed' && $second !== null && $second !== ''
                ? new ChangedFile($second, 'renamed', previousPath: $first)
                : new ChangedFile($twoPaths && $second !== null && $second !== '' ? $second : $first, $status);
        }

        return $files;
    }

    /**
     * Walk `cat-file --batch` output, which answers each request in order with
     * either `<oid> <type> <size>` and that many bytes, or `<request> missing`.
     *
     * @param  list<string>  $relativePaths
     * @return array<string, ?string>
     */
    public function blobs(string $output, array $relativePaths): array
    {
        $contents = [];
        $offset = 0;

        foreach ($relativePaths as $path) {
            $break = strpos($output, "\n", $offset);

            // No line left to read means git answered fewer requests than were
            // made, which is the same answer as a malformed header: we do not
            // know what this file held at that revision.
            $header = $break === false ? [] : explode(' ', trim(substr($output, $offset, $break - $offset)));
            $offset = $break === false ? $offset : $break + 1;
            $size = end($header);

            if (count($header) !== 3 || $header[1] !== 'blob' || ! is_string($size) || ! ctype_digit($size)) {
                $contents[$path] = null;

                continue;
            }

            $contents[$path] = substr($output, $offset, (int) $size);
            $offset += (int) $size + 1;
        }

        return $contents;
    }

    /**
     * The non-empty paths of a NUL-separated listing such as `ls-files -z`.
     *
     * @return list<string>
     */
    public function paths(string $output): array
    {
        $paths = [];

        foreach (explode("\0", $output) as $path) {
            if (trim($path) !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * How often each path appears in a `log --name-only -z` listing, which is
     * how many of the commits it covers touched that file.
     *
     * @return array<string, int>
     */
    public function pathCounts(string $output): array
    {
        $counts = [];

        foreach (preg_split('/[\0\n]/', $output) ?: [] as $path) {
            if (trim($path) !== '') {
                $counts[$path] = ($counts[$path] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Split paths into groups that fit comfortably inside one command line.
     *
     * @param  list<string>  $relativePaths
     * @return list<list<string>>
     */
    public function batches(array $relativePaths, int $maxLength = 6000): array
    {
        $batches = [];
        $batch = [];
        $length = 0;

        foreach ($relativePaths as $path) {
            if ($batch !== [] && $length + mb_strlen($path) + 3 > $maxLength) {
                $batches[] = $batch;
                $batch = [];
                $length = 0;
            }

            $batch[] = $path;
            $length += mb_strlen($path) + 3;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * The path a `+++` header names, or null for a deletion.
     *
     * Git appends a tab to a name containing a space, for the benefit of
     * `patch`, and C-quotes a name containing a quote, a backslash or a
     * control character even with `core.quotePath` off.
     */
    private function targetPath(string $header): ?string
    {
        $target = rtrim($header, "\r");

        if (str_starts_with($target, '"')) {
            $target = $this->unquote($target);
        } elseif (str_contains($target, "\t")) {
            $target = substr($target, 0, (int) strrpos($target, "\t"));
        }

        if ($target === '/dev/null') {
            return null;
        }

        return str_starts_with($target, 'b/') ? substr($target, 2) : $target;
    }

    /**
     * Undo git's C-style quoting of a path: `"a\"b\303\251"` is `a"bé`.
     */
    private function unquote(string $quoted): string
    {
        $end = strrpos($quoted, '"');
        $body = substr($quoted, 1, $end === false || $end === 0 ? null : $end - 1);

        return (string) preg_replace_callback(
            '/\\\\([0-7]{3}|.)/',
            static fn (array $match): string => match ($match[1]) {
                'n' => "\n",
                't' => "\t",
                'r' => "\r",
                'a' => "\x07",
                'b' => "\x08",
                'f' => "\f",
                'v' => "\v",
                default => strlen($match[1]) === 3 ? chr(((int) octdec($match[1])) & 255) : $match[1],
            },
            $body,
        );
    }
}
