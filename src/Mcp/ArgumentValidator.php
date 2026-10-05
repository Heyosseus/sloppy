<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp;

/**
 * Checks a tool call's arguments against the schema the tool advertised.
 *
 * A model that sent `"format": "xml"` or `"paths": "app"` asked a question
 * the tool cannot answer as asked. Quietly answering a different one -- the
 * default format, a scan of the configured paths -- reads to the model as the
 * answer to what it sent, so it never learns to send the right thing. Saying
 * which argument was wrong, and what it should have been, does.
 *
 * Only the subset of JSON Schema the tools use is understood: `type`
 * (string, integer, number, boolean, array, object), `enum`, `items`,
 * `minimum`, `maximum` and `required`. Keys the schema does not mention are
 * allowed, as JSON Schema allows them by default.
 */
final readonly class ArgumentValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> What is wrong with the arguments; empty when nothing is.
     */
    public function problems(mixed $arguments, array $schema): array
    {
        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return [sprintf('"arguments" must be an object, got %s.', $this->describe($arguments))];
        }

        $problems = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $name) {
            if (is_string($name) && ! array_key_exists($name, $arguments)) {
                $problems[] = sprintf('"%s" is required.', $name);
            }
        }

        foreach ($arguments as $name => $value) {
            $property = $properties[$name] ?? null;

            if (is_array($property)) {
                $problems = [...$problems, ...$this->check((string) $name, $value, $property)];
            }
        }

        return $problems;
    }

    /**
     * @param  array<mixed>  $property
     * @return list<string>
     */
    private function check(string $name, mixed $value, array $property): array
    {
        $type = $property['type'] ?? null;

        if (is_string($type) && ! $this->hasType($value, $type)) {
            return [sprintf('"%s" must be %s %s, got %s.', $name, in_array($type, ['integer', 'array', 'object'], true) ? 'an' : 'a', $type, $this->describe($value))];
        }

        $enum = $property['enum'] ?? null;

        if (is_array($enum) && ! in_array($value, $enum, true)) {
            return [sprintf(
                '"%s" must be one of %s, got %s.',
                $name,
                implode(', ', array_map($this->describe(...), $enum)),
                $this->describe($value),
            )];
        }

        $minimum = $property['minimum'] ?? null;
        $maximum = $property['maximum'] ?? null;

        if ((is_int($minimum) && is_numeric($value) && $value < $minimum) || (is_int($maximum) && is_numeric($value) && $value > $maximum)) {
            return [sprintf('"%s" must be between %s and %s, got %s.', $name, $minimum ?? '-inf', $maximum ?? 'inf', $this->describe($value))];
        }

        $items = $property['items'] ?? null;

        if (! is_array($items) || ! is_array($value)) {
            return [];
        }

        $problems = [];

        foreach (array_values($value) as $index => $item) {
            $problems = [...$problems, ...$this->check(sprintf('%s[%d]', $name, $index), $item, $items)];
        }

        return $problems;
    }

    private function hasType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            // JSON has one number type: 80.0 is as much an integer as 80.
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'null' => $value === null,
            default => true,
        };
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"'.$value.'"',
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => array_is_list($value) ? 'an array' : 'an object',
            default => get_debug_type($value),
        };
    }
}
