<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * A JSON file somebody else owns, edited without reformatting it.
 *
 * `.claude/settings.json` and `.mcp.json` are hand-written and committed, so
 * every byte this package changes shows up in someone's diff. Decoding and
 * re-encoding loses things that are not ours to lose: PHP turns
 * `12345678901234567890` into a float and `1.0` into `1`, and its pretty
 * printer has its own idea of indentation. So:
 *
 * - every number is swapped for a placeholder before decoding and its
 *   original text put back after encoding, so numbers survive byte for byte;
 * - the indentation, line ending and final newline of the original are kept;
 * - and a document nobody changed is written back exactly as it was read.
 *
 * The file is decoded to objects rather than arrays on purpose: an empty
 * `"env": {}` decoded to an array comes back out as `[]`, which Claude Code
 * then refuses to load -- a settings writer that breaks settings.
 */
final readonly class JsonDocument
{
    /**
     * @param  array<string, string>  $numbers  Placeholder => the number's original text.
     */
    private function __construct(
        public stdClass $data,
        private ?string $original,
        private string $canonical,
        private array $numbers,
        private string $indent,
        private string $eol,
        private bool $finalNewline,
    ) {}

    /**
     * @throws InvalidArgumentException When the text is not a JSON object.
     */
    public static function parse(?string $text): self
    {
        if ($text === null || trim($text) === '') {
            return new self(new stdClass, null, '', [], '  ', "\n", true);
        }

        $nonce = bin2hex(random_bytes(6));
        $numbers = [];

        $masked = preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/',
            static function (array $match) use ($nonce, &$numbers): string {
                if ($match[0][0] === '"') {
                    return $match[0];
                }

                $placeholder = sprintf('"@sloppy-number-%s-%d@"', $nonce, count($numbers));
                $numbers[$placeholder] = $match[0];

                return $placeholder;
            },
            $text,
        );

        try {
            $decoded = json_decode($masked ?? $text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('It is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! $decoded instanceof stdClass) {
            throw new InvalidArgumentException('It is not a JSON object.');
        }

        return new self(
            data: $decoded,
            original: $text,
            canonical: self::canonical($decoded),
            numbers: $numbers,
            indent: preg_match('/\n([ \t]+)\S/', $text, $indent) === 1 ? $indent[1] : '  ',
            eol: str_contains($text, "\r\n") ? "\r\n" : "\n",
            finalNewline: preg_match('/\R\z/', $text) === 1,
        );
    }

    /**
     * Whether the data no longer matches what was read.
     */
    public function changed(): bool
    {
        return $this->original === null || self::canonical($this->data) !== $this->canonical;
    }

    /**
     * Whether nothing is left in the document at all.
     */
    public function isEmpty(): bool
    {
        return get_object_vars($this->data) === [];
    }

    /**
     * The document as text: the original, untouched, when nothing changed,
     * and otherwise the new data in the original's style.
     */
    public function render(): string
    {
        if ($this->original !== null && ! $this->changed()) {
            return $this->original;
        }

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $indented = preg_replace_callback(
            '/^(?: {4})+/m',
            fn (array $indent): string => str_repeat($this->indent, intdiv(strlen($indent[0]), 4)),
            $json,
        ) ?? $json;

        $restored = strtr($indented, $this->numbers);
        $lines = str_replace("\n", $this->eol, $restored);

        return $this->finalNewline ? $lines.$this->eol : $lines;
    }

    private static function canonical(stdClass $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
