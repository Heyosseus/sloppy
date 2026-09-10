<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Laravel\CollectionInsteadOfQueryRule;

function collectionInsteadOfQuery(): CollectionInsteadOfQueryRule
{
    return new CollectionInsteadOfQueryRule;
}

it('flags filtering a full table load in PHP', function (): void {
    $found = findings(collectionInsteadOfQuery(), <<<'PHP'
    class Customers
    {
        public function active(): Collection
        {
            return Customer::all()->where('active', true);
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL205')
        ->and($found[0]->metrics['model'])->toBe('Customer')
        ->and($found[0]->metrics['operation'])->toBe('where')
        ->and($found[0]->suggestion)->toContain('Customer::where(...)->get()');
});

it('flags counting a full table load in PHP', function (): void {
    $found = findings(collectionInsteadOfQuery(), <<<'PHP'
    class Customers
    {
        public function total(): int
        {
            return Customer::all()->count();
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->confidence)->toBeGreaterThan(88);
});

it('is less certain about a closure predicate', function (): void {
    // filter() has no direct SQL equivalent, so this is a hint, not advice.
    $found = findings(collectionInsteadOfQuery(), <<<'PHP'
    class Customers
    {
        public function eligible(): Collection
        {
            return Customer::all()->filter(fn (Customer $c): bool => $c->score() > 10);
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->confidence)->toBeLessThan(70)
        ->and($found[0]->suggestion)->toContain('if the predicate can be expressed in SQL');
});

it('does not flag a proper query', function (): void {
    expect(findings(collectionInsteadOfQuery(), <<<'PHP'
    class Customers
    {
        public function active(): Collection
        {
            return Customer::where('active', true)->get();
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a collection operation with no query equivalent', function (): void {
    expect(findings(collectionInsteadOfQuery(), <<<'PHP'
    class Customers
    {
        public function grouped(): Collection
        {
            return Customer::all()->groupBy('region');
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag facades that happen to expose all()', function (): void {
    expect(findings(collectionInsteadOfQuery(), <<<'PHP'
    class Settings
    {
        public function enabled(): array
        {
            return Config::all()->where('enabled', true);
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a mapped collection, which the database cannot do', function (): void {
    expect(findings(collectionInsteadOfQuery(), <<<'PHP'
    class Customers
    {
        public function labels(): Collection
        {
            return Customer::all()->map(fn (Customer $c): string => $c->label());
        }
    }
    PHP))->toBeEmpty();
});
