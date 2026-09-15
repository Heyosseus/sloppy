<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Php\UnexplainedSuppressionRule;

it('flags a suppression that gives no reason', function (): void {
    $findings = findings(new UnexplainedSuppressionRule, <<<'PHP'
    <?php
    class Importer
    {
        /** @phpstan-ignore-next-line */
        public function run(): int
        {
            return $this->missing();
        }
    }
    PHP);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('SL501')
        ->and($findings[0]->message)->toContain('@phpstan-ignore');
});

it('says nothing when the suppression carries a reason', function (): void {
    $findings = findings(new UnexplainedSuppressionRule, <<<'PHP'
    <?php
    class Importer
    {
        /** @phpstan-ignore-next-line Doctrine's stub declares the wrong return type */
        public function run(): int
        {
            return $this->missing();
        }
    }
    PHP);

    expect($findings)->toBeEmpty();
});

it('accepts a reason written on the next line of the docblock', function (): void {
    // Real docblocks wrap. Requiring the reason on the annotation's own line
    // would train people to write a shorter reason, not a better one.
    $findings = findings(new UnexplainedSuppressionRule, <<<'PHP'
    <?php
    class Importer
    {
        /**
         * @psalm-suppress MixedReturnStatement
         * The vendor SDK is untyped and we validate the shape in the caller.
         */
        public function run(): int
        {
            return $this->sdk->fetch();
        }
    }
    PHP);

    expect($findings)->toBeEmpty();
});

it('still fires when a suppression names a rule but gives no prose', function (): void {
    // `MixedReturnStatement` is the error being silenced, which the reader can
    // already see. It is the sentence after it that is missing.
    $findings = findings(new UnexplainedSuppressionRule, <<<'PHP'
    <?php
    class Importer
    {
        /** @psalm-suppress MixedReturnStatement */
        public function run(): int
        {
            return $this->sdk->fetch();
        }
    }
    PHP);

    expect($findings)->toHaveCount(1);
});

it('ignores an annotation that is only mentioned in a string', function (): void {
    // Matching the file's text rather than its comment tokens would fire on
    // this package's own documentation of the rule.
    $findings = findings(new UnexplainedSuppressionRule, <<<'PHP'
    <?php
    class Docs
    {
        public function example(): string
        {
            return '@phpstan-ignore-next-line';
        }
    }
    PHP);

    expect($findings)->toBeEmpty();
});

it('reports each bare suppression separately', function (): void {
    $findings = findings(new UnexplainedSuppressionRule, <<<'PHP'
    <?php
    class Importer
    {
        /** @phpstan-ignore-next-line */
        public function one(): int
        {
            return $this->a();
        }

        // @noinspection
        public function two(): int
        {
            return $this->b();
        }
    }
    PHP);

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->location->line)->not->toBe($findings[1]->location->line);
});

it('ignores an annotation merely discussed in prose', function (): void {
    // Caught by SelfCheckTest: this rule's own source explains what
    // `@psalm-suppress` is, and the rule reported its own documentation.
    // A suppression is a directive, and a directive starts its line. Prose
    // that mentions one has words in front of it.
    $findings = findings(new UnexplainedSuppressionRule, <<<'PHP'
    <?php
    class Docs
    {
        // Nor is the identifier the analyser needs. `@psalm-suppress
        // MixedReturnStatement` names the error, which the reader can see.
        public function explain(): int
        {
            return 1;
        }
    }
    PHP);

    expect($findings)->toBeEmpty();
});

it('still fires on a directive indented inside a docblock', function (): void {
    $findings = findings(new UnexplainedSuppressionRule, <<<'PHP'
    <?php
    class Importer
    {
        /**
         * Loads the feed.
         *
         * @phpstan-ignore-next-line
         */
        public function run(): int
        {
            return $this->missing();
        }
    }
    PHP);

    expect($findings)->toHaveCount(1);
});
