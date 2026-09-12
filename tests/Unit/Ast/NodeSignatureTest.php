<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ast\NodeHelper;

// `firstMethod()` is the shared helper declared in tests/Pest.php; a local
// redeclaration here would fatal with "Cannot redeclare firstMethod()" since
// Pest.php is loaded once for the whole suite.

it('produces a token stream that joins back to the structure string', function (): void {
    $method = firstMethod('class A { public function run($x) { return $x + 1; } }');
    $statement = $method->stmts[0];

    $signature = NodeHelper::signature($statement);

    expect(implode('', $signature->tokens))->toBe(NodeHelper::structure($statement));
});

it('collects the variable names, literals and classes the token stream masks away', function (): void {
    $method = firstMethod(
        'class A { public function run($order) { $gateway = new StripeGateway(); return User::find($order->id)->pay(42); } }'
    );

    $masked = [];

    foreach ($method->stmts as $statement) {
        foreach (NodeHelper::signature($statement)->maskedValues as $value) {
            $masked[] = $value;
        }
    }

    // Every name and value the hash cannot see, in traversal order. These are
    // what SL111 compares when two blocks hash identically.
    expect($masked)->toBe([
        'var:gateway',
        'class:StripeGateway',
        'class:User',
        'var:order',
        'lit:42',
    ]);
});

it('gives two blocks that differ only in a masked class the same tokens and different masked values', function (): void {
    $stripe = firstMethod('class A { public function run($d) { return (new StripeGateway)->charge($d->total); } }');
    $paypal = firstMethod('class B { public function run($d) { return (new PaypalGateway)->charge($d->total); } }');

    $a = NodeHelper::signature($stripe->stmts[0]);
    $b = NodeHelper::signature($paypal->stmts[0]);

    expect($a->tokens)->toBe($b->tokens)
        ->and($a->maskedValues)->not->toBe($b->maskedValues)
        ->and($a->maskedValues)->toHaveCount(count($b->maskedValues));
});

it('keeps the masked-value list the same length for blocks that share a hash', function (): void {
    // Equal length is what makes the positional comparison in MaskedDivergence
    // well defined; if this ever fails, that comparison is unsound.
    $zero = firstMethod('class A { public function run($d) { if ($d->total <= 0) { return null; } return $d->id; } }');
    $fiveHundred = firstMethod('class B { public function run($d) { if ($d->total <= 500) { return null; } return $d->id; } }');

    $a = [];
    $b = [];

    foreach ($zero->stmts as $statement) {
        $a = [...$a, ...NodeHelper::signature($statement)->maskedValues];
    }

    foreach ($fiveHundred->stmts as $statement) {
        $b = [...$b, ...NodeHelper::signature($statement)->maskedValues];
    }

    expect($a)->toHaveCount(count($b))
        ->and($a)->not->toBe($b)
        ->and($a)->toContain('lit:0')
        ->and($b)->toContain('lit:500');
});
