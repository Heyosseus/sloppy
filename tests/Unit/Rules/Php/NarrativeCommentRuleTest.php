<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Rules\Php\NarrativeCommentRule;

/**
 * @param  array<string, mixed>  $options
 */
function narrative(array $options = []): NarrativeCommentRule
{
    return new NarrativeCommentRule($options);
}

it('flags a comment that restates the line below it', function (): void {
    $found = findings(narrative(), <<<'PHP'
    class Guard
    {
        public function check(?User $user): bool
        {
            // Check if user exists
            if ($user) {
                return true;
            }

            return false;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL109')
        ->and($found[0]->severity)->toBe(Severity::Low)
        ->and($found[0]->metrics['comment'])->toBe('Check if user exists');
});

it('keeps a comment that explains why', function (): void {
    // The words that carry the meaning are nowhere in the code, which is the
    // whole point of the comment.
    expect(findings(narrative(), <<<'PHP'
    class Pricing
    {
        public function total(array $lines): int
        {
            // Stored in minor units so rounding never loses a fraction of a penny.
            $total = 0;

            return $total;
        }
    }
    PHP))->toBeEmpty();
});

it('flags numbered step narration', function (): void {
    $found = findings(narrative(), <<<'PHP'
    class Checkout
    {
        public function run(): void
        {
            // Step 1: reserve the stock
            $this->inventory->reserve();

            // Step 2: take the payment
            $this->payments->charge();
        }
    }
    PHP);

    expect($found)->toHaveCount(2)
        ->and($found[0]->message)->toContain('narrates a step');
});

it('can be told to ignore step narration', function (): void {
    expect(findings(narrative(['detect_step_comments' => false]), <<<'PHP'
    class Checkout
    {
        public function run(): void
        {
            // Step 1: reserve the stock
            $this->inventory->reserve();
        }
    }
    PHP))->toBeEmpty();
});

it('ignores docblocks', function (): void {
    expect(findings(narrative(), <<<'PHP'
    class Guard
    {
        /**
         * Check if user exists.
         */
        public function check(?User $user): bool
        {
            return (bool) $user;
        }
    }
    PHP))->toBeEmpty();
});

it('ignores directives and annotations', function (): void {
    expect(findings(narrative(), <<<'PHP'
    class Guard
    {
        public function check(?User $user): bool
        {
            // TODO user handling
            // @phpstan-ignore-next-line
            // phpcs:ignore
            return (bool) $user;
        }
    }
    PHP))->toBeEmpty();
});

it('ignores commented-out code', function (): void {
    // A different smell, and not this rule's to report.
    expect(findings(narrative(), <<<'PHP'
    class Guard
    {
        public function check(?User $user): bool
        {
            // $user = $this->users->current();
            return (bool) $user;
        }
    }
    PHP))->toBeEmpty();
});

it('ignores separator comments', function (): void {
    expect(findings(narrative(), <<<'PHP'
    class Guard
    {
        // ------------------------------------
        public function check(): bool
        {
            return true;
        }
    }
    PHP))->toBeEmpty();
});

it('ignores comments longer than the word limit', function (): void {
    expect(findings(narrative(['max_words' => 4]), <<<'PHP'
    class Guard
    {
        public function check(?User $user): bool
        {
            // Check if the user record exists before continuing further here
            if ($user) {
                return true;
            }

            return false;
        }
    }
    PHP))->toBeEmpty();
});

it('ignores section headings', function (): void {
    // The words in a heading are meant to echo the code beneath it.
    expect(findings(narrative(), <<<'SNIPPET'
    class Helper
    {
        // -----------------------------------------------------------------
        // Names
        // -----------------------------------------------------------------

        public function name(): string
        {
            return 'a';
        }
    }
    SNIPPET))->toBeEmpty();
});

it('leaves a comment at the end of a block alone, with no code below it to restate', function (): void {
    // Regression: the parser holds a trailing comment in an empty statement
    // on the comment's own line, and the comment was compared with itself.
    expect(findings(narrative(), <<<'SNIPPET'
    class ImportLocations
    {
        public function run($location): void
        {
            if ($location->save()) {
                $this->info('created');

                // If there's a validation error, display that
            } else {
                $this->error('failed '.$location->getErrors());
            }
        }
    }
    SNIPPET))->toBeEmpty();
});

it('does not judge one line of a comment that runs over several', function (): void {
    // Regression: each // line is its own comment to the parser, and the last
    // line of a paragraph -- "the seats." -- matched the code on its own.
    expect(findings(narrative(), <<<'SNIPPET'
    class Licenses
    {
        public function checkout($license): void
        {
            // One at a time, so the audit log records each of
            // the seats.
            $license->seats->each->checkout();
        }
    }
    SNIPPET))->toBeEmpty();
});

it('ignores an empty comment and a lone divider', function (): void {
    expect(findings(narrative(), <<<'SNIPPET'
    class Totals
    {
        public function sum(array $items): int
        {
            //
            $total = array_sum($items);

            // ------
            return $total;
        }
    }
    SNIPPET))->toBeEmpty();
});

it('does not judge an end-of-line comment against the next line', function (): void {
    expect(findings(narrative(), <<<'PHP'
    class Users
    {
        public function name(string $input): string
        {
            $name = trim($input); // user name
            $user = $this->users->name($name);

            return $user;
        }
    }
    PHP))->toBeEmpty();
});
