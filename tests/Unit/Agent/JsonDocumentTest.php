<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\JsonDocument;

it('writes back what it read when nothing changed', function (string $text): void {
    $document = JsonDocument::parse($text);

    expect($document->changed())->toBeFalse()
        ->and($document->render())->toBe($text);
})->with([
    'compact' => ['{"a":1,"b":[1.0,2e10]}'],
    'big integer' => ["{\n  \"id\": 12345678901234567890\n}\n"],
    'escaped' => ['{"path": "a\/b", "name": "caf\u00e9"}'],
]);

it('keeps every number\'s text and the file\'s style when something else changed', function (): void {
    $document = JsonDocument::parse("{\n\t\"big\": 12345678901234567890,\n\t\"one\": 1.0,\n\t\"neg\": -0.5E-3,\n\t\"text\": \"12 monkeys\"\n}");
    $document->data->added = true;

    expect($document->changed())->toBeTrue()
        ->and($document->render())->toBe("{\n\t\"big\": 12345678901234567890,\n\t\"one\": 1.0,\n\t\"neg\": -0.5E-3,\n\t\"text\": \"12 monkeys\",\n\t\"added\": true\n}");
});

it('starts a new document with two spaces and a final newline', function (): void {
    $document = JsonDocument::parse(null);
    $document->data->hooks = new stdClass;

    expect($document->isEmpty())->toBeFalse()
        ->and($document->render())->toBe("{\n  \"hooks\": {}\n}\n");
});

it('refuses text that is not a JSON object', function (string $text, string $message): void {
    expect(fn (): JsonDocument => JsonDocument::parse($text))->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['{"a": ', 'It is not valid JSON'],
    ['[1, 2]', 'It is not a JSON object.'],
]);
