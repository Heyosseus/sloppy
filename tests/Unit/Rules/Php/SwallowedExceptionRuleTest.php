<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Rules\Php\SwallowedExceptionRule;

function swallowed(): SwallowedExceptionRule
{
    return new SwallowedExceptionRule;
}

it('flags a catch that returns null without recording anything', function (): void {
    $found = findings(swallowed(), <<<'PHP'
    class Importer
    {
        public function read(string $path): ?array
        {
            try {
                return $this->parse($path);
            } catch (\Throwable $e) {
                return null;
            }
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL107')
        ->and($found[0]->severity)->toBe(Severity::High)
        ->and($found[0]->metrics['broad_type'])->toBeTrue()
        ->and($found[0]->message)->toContain('returns without recording the failure');
});

it('is most certain about a completely empty catch', function (): void {
    $found = findings(swallowed(), <<<'PHP'
    class Importer
    {
        public function read(string $path): void
        {
            try {
                $this->parse($path);
            } catch (\Throwable $e) {
            }
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['empty_body'])->toBeTrue()
        ->and($found[0]->confidence)->toBeGreaterThan(90)
        ->and($found[0]->message)->toContain('does nothing at all');
});

it('does not flag a catch that logs', function (): void {
    expect(findings(swallowed(), <<<'PHP'
    class Importer
    {
        public function read(string $path): ?array
        {
            try {
                return $this->parse($path);
            } catch (\Throwable $e) {
                $this->logger->error('parse failed', ['path' => $path, 'e' => $e]);

                return null;
            }
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a catch that rethrows', function (): void {
    expect(findings(swallowed(), <<<'PHP'
    class Importer
    {
        public function read(string $path): array
        {
            try {
                return $this->parse($path);
            } catch (\Throwable $e) {
                throw new ImportFailed($path, previous: $e);
            }
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a catch that records state on the object', function (): void {
    expect(findings(swallowed(), <<<'PHP'
    class Importer
    {
        private ?string $lastError = null;

        public function read(string $path): ?array
        {
            try {
                return $this->parse($path);
            } catch (\Throwable $e) {
                $this->lastError = $e->getMessage();

                return null;
            }
        }
    }
    PHP))->toBeEmpty();
});

it('is less strident about a narrow catch than a broad one', function (): void {
    $narrow = findings(swallowed(), <<<'PHP'
    class Finder
    {
        public function find(int $id): ?Order
        {
            try {
                return Order::findOrFail($id);
            } catch (ModelNotFoundException $e) {
                return null;
            }
        }
    }
    PHP);

    $broad = findings(swallowed(), <<<'PHP'
    class Finder
    {
        public function find(int $id): ?Order
        {
            try {
                return Order::findOrFail($id);
            } catch (\Throwable $e) {
                return null;
            }
        }
    }
    PHP);

    expect($narrow)->toHaveCount(1)
        ->and($broad)->toHaveCount(1)
        ->and($narrow[0]->confidence)->toBeLessThan($broad[0]->confidence)
        ->and($narrow[0]->metrics['caught'])->toBe('ModelNotFoundException');
});

it('gives two swallowing catches in one method distinct identities', function (): void {
    $found = findings(swallowed(), <<<'PHP'
    class Importer
    {
        public function read(): void
        {
            try {
                $this->a();
            } catch (\Throwable $e) {
            }

            try {
                $this->b();
            } catch (\Throwable $e) {
            }
        }
    }
    PHP);

    expect($found)->toHaveCount(2)
        ->and($found[0]->identity())->not->toBe($found[1]->identity());
});

it('rates a bool predicate that answers false on failure as low', function (): void {
    // `false` is the documented "no" of a predicate, so the caller is told.
    $found = findings(swallowed(), <<<'PHP'
    class PhoneNumbers
    {
        public function isValid(string $number): bool
        {
            try {
                return $this->parser->parse($number)->isValid();
            } catch (\Throwable $e) {
                return false;
            }
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->severity)->toBe(Severity::Low);
});

it('rates a narrow catch that rejects with null from a nullable method as low', function (): void {
    $found = findings(swallowed(), <<<'PHP'
    class Tokens
    {
        public function verify(string $token): ?Payload
        {
            try {
                return $this->jwt->decode($token);
            } catch (JWTException $e) {
                return null;
            }
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->severity)->toBe(Severity::Low);
});

it('keeps a broad catch returning null from a nullable method high', function (): void {
    // Null here cannot be told apart from "nothing found": a crash in the
    // parser and an empty file look the same to the caller.
    $found = findings(swallowed(), <<<'PHP'
    class Importer
    {
        public function read(string $path): ?array
        {
            try {
                return $this->parse($path);
            } catch (\Exception $e) {
                return null;
            }
        }
    }
    PHP);

    expect($found[0]->severity)->toBe(Severity::High);
});

it('keeps a rejecting catch high when the return type does not admit the value', function (): void {
    $found = findings(swallowed(), <<<'PHP'
    class Tokens
    {
        public function verify(string $token): Payload|false
        {
            try {
                return $this->jwt->decode($token);
            } catch (JWTException $e) {
                return null;
            }
        }

        public function check(string $token): bool
        {
            try {
                return $this->jwt->valid($token);
            } catch (JWTException $e) {
                return null;
            }
        }
    }
    PHP);

    expect(array_map(static fn (Finding $finding): Severity => $finding->severity, $found))
        ->toBe([Severity::High, Severity::High]);
});

it('judges a catch inside a closure by the closure return type', function (): void {
    $found = findings(swallowed(), <<<'PHP'
    class Tokens
    {
        public function valid(array $tokens): array
        {
            return array_filter($tokens, function (string $token): bool {
                try {
                    return $this->jwt->valid($token);
                } catch (JWTException $e) {
                    return false;
                }
            });
        }
    }
    PHP);

    expect($found[0]->severity)->toBe(Severity::Low);
});

it('never raises a severity the project lowered further', function (): void {
    $found = findings(new SwallowedExceptionRule(['severity' => 'info']), <<<'PHP'
    class PhoneNumbers
    {
        public function isValid(string $number): bool
        {
            try {
                return $this->parser->parse($number)->isValid();
            } catch (\Throwable $e) {
                return false;
            }
        }
    }
    PHP);

    expect($found[0]->severity)->toBe(Severity::Info);
});
