<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Rules\Php\CopyPasteDriftRule;
use Heyosseus\Sloppy\Tests\Support\RuleTester;

/**
 * @param  array<string, mixed>  $options
 */
function copyPasteDrift(array $options = []): CopyPasteDriftRule
{
    return new CopyPasteDriftRule($options);
}

const DRIFT_SIBLING_A = <<<'PHP'
class RefundBog
{
    public function execute($payment)
    {
        $client = $this->client();
        $token = $client->authorise($payment->reference);
        $response = $client->refund($token, $payment->amount);
        if (! $response) {
            return;
        }
        $payment->markRefunded($response->reference);
        $this->log->info('refunded', ['id' => $payment->id]);
        return true;
    }
}
PHP;

/**
 * The guarded body and the same body with its failure guard removed.
 *
 * Built by removing the guard rather than by writing two fixtures, so the two
 * cannot drift apart in a way that quietly changes what every test below is
 * measuring. Assert the removal happened -- a str_replace that silently
 * matched nothing would leave two identical bodies, which SL111 ignores by
 * design, and every test here would then pass for the wrong reason.
 *
 * The guard itself is deliberately tiny. `NodeHelper::describe()` emits three
 * tokens per AST node (a name and its bracketing pair), so a guard with a
 * method-call condition and a logged error -- a more "realistic" guard --
 * measures 66 tokens against this 162-token body: past `max_token_distance`
 * (28) outright, and at a 40.7% ratio no genuine finding on the swept
 * 73,737-line corpus ever reached (the ceiling was 5.61%). A bare
 * `if (! $response) { return; }` measures 12 tokens, a 7.4% ratio -- inside
 * both defaults with the same margin real findings had.
 *
 * @return array<string, string>
 */
function driftFixturePair(): array
{
    $guard = <<<'PHP'
        if (! $response) {
            return;
        }

PHP;

    $withoutGuard = str_replace($guard, '', str_replace('RefundBog', 'RefundTbc', DRIFT_SIBLING_A));

    if (! str_contains(DRIFT_SIBLING_A, $guard) || str_contains($withoutGuard, '! $response')) {
        throw new RuntimeException('The guard was not removed; this fixture no longer tests drift.');
    }

    return ['app/RefundBog.php' => DRIFT_SIBLING_A, 'app/RefundTbc.php' => $withoutGuard];
}

it('reports a sibling that lost a guard its family has', function (): void {
    $findings = RuleTester::runAcross(copyPasteDrift(['min_statements' => 4]), driftFixturePair());

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('SL111')
        ->and($findings[0]->severity)->toBe(Severity::High)
        ->and($findings[0]->message)->toContain('RefundTbc')
        ->and($findings[0]->metrics['token_distance'])->toBeGreaterThan(0)
        ->and($findings[0]->confidence)->toBeLessThan(100);
});

it('reports the minority class among three bodies that hash identically', function (): void {
    $body = 'public function pay($d) { $g = new %s(); $r = $g->charge($d->total, $d->currency); $this->log->info("paid"); $d->markPaid($r->id); return $r->id; }';

    $findings = RuleTester::runAcross(copyPasteDrift(['min_statements' => 4]), [
        'app/One.php' => 'class One { '.sprintf($body, 'StripeGateway').' }',
        'app/Two.php' => 'class Two { '.sprintf($body, 'StripeGateway').' }',
        'app/Three.php' => 'class Three { '.sprintf($body, 'PaypalGateway').' }',
    ]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->location->relativePath)->toBe('app/Three.php')
        ->and($findings[0]->message)->toContain('PaypalGateway')
        ->and($findings[0]->message)->toContain('StripeGateway');
});

it('marks every finding it still emits when the search was cut off', function (): void {
    // A silently degraded analysis is worse than one that says so.
    //
    // The corpus needs two halves. The Drift* bodies differ in SHAPE, so they
    // have different hashes, survive the cheap gates, and consume the
    // comparison budget -- three candidate pairs against a ceiling of one. The
    // One/Two/Three bodies differ only in a MASKED class name, so all three
    // share a hash, are rejected at the gates without spending any budget, and
    // still produce the minority finding through the masked path.
    //
    // An earlier version of this test used only the masked trio. Every pair in
    // it is gate-rejected, so once the ceiling was corrected to count
    // comparisons rather than candidates, nothing could ever consume the
    // budget and truncation became unreachable.
    $paid = 'public function pay($d) { $g = new %s(); $r = $g->charge($d->total, $d->currency); $this->log->info("paid"); $d->markPaid($r->id); return $r->id; }';

    $base = <<<'PHP'
        public function settle($order)
        {
            $gateway = $this->gateway();
            $response = $gateway->capture($order->total, $order->currency);
            $this->log->info('settled', ['id' => $order->id]);
            $order->markSettled($response->reference);
            return $response->reference;
        }
        PHP;

    $guarded = str_replace(
        '        $gateway = $this->gateway();',
        '        if ($order->total <= 0) {
            return null;
        }
        $gateway = $this->gateway();',
        $base,
    );

    $findings = RuleTester::runAcross(copyPasteDrift(['min_statements' => 4, 'max_comparisons' => 1]), [
        'app/Drift1.php' => 'class Drift1 { '.$base.' }',
        'app/Drift2.php' => 'class Drift2 { '.$guarded.' }',
        'app/Drift3.php' => 'class Drift3 { '.str_replace('markSettled', 'markComplete', $base).' }',
        'app/One.php' => 'class One { '.sprintf($paid, 'StripeGateway').' }',
        'app/Two.php' => 'class Two { '.sprintf($paid, 'StripeGateway').' }',
        'app/Three.php' => 'class Three { '.sprintf($paid, 'PaypalGateway').' }',
    ]);

    expect($findings)->not->toBe([]);

    foreach ($findings as $finding) {
        expect($finding->metrics)->toHaveKey('search_truncated')
            ->and($finding->metrics['search_truncated'])->toBeTrue();
    }
});

it('does not treat a renamed local as a candidate defect', function (): void {
    // A local variable is masked, not literal, so a group that agrees on
    // everything except what one body calls its own accumulator has found a
    // rename -- not the class swap or literal drift SL111 exists to catch.
    $body = 'public function total($items) { $%1$s = 0; foreach ($items as $i) { $%1$s += $i->price; } return $%1$s; }';

    expect(RuleTester::runAcross(copyPasteDrift(['min_statements' => 4]), [
        'app/One.php' => 'class One { '.sprintf($body, 'sum').' }',
        'app/Two.php' => 'class Two { '.sprintf($body, 'sum').' }',
        'app/Three.php' => 'class Three { '.sprintf($body, 'total').' }',
    ]))->toBe([]);
});

it('says nothing about two bodies that are genuinely identical', function (): void {
    // SL104's job. Reporting it here would double-report every duplicate.
    $body = 'public function run($rows) { $out = []; foreach ($rows as $r) { $out[] = $r->id; } $n = count($out); $label = "done"; return [$label, $n, $out]; }';

    expect(RuleTester::runAcross(copyPasteDrift(['min_statements' => 4]), [
        'app/A.php' => 'class A { '.$body.' }',
        'app/B.php' => 'class B { '.$body.' }',
    ]))->toBe([]);
});

it('says nothing about two unrelated methods of similar length', function (): void {
    // min_statements is lowered deliberately. At the shipped default of 8 these
    // bodies are below the floor and the test would pass without the distance
    // check ever running -- a test that asserts nothing about what it names.
    expect(RuleTester::runAcross(copyPasteDrift(['min_statements' => 4]), [
        'app/Mailer.php' => <<<'PHP'
        class Mailer
        {
            public function send($user, $template)
            {
                $body = $this->renderer->render($template, ['user' => $user]);
                $message = $this->factory->make($user->email, $body);
                $this->transport->deliver($message);
                $this->log->info('sent', ['to' => $user->email]);
                $user->touchLastMailed();
                return $message->id();
            }
        }
        PHP,
        'app/Importer.php' => <<<'PHP'
        class Importer
        {
            public function import($path, $sheet)
            {
                $rows = $this->reader->rows($path, $sheet);
                $valid = $this->validator->filter($rows, $this->schema());
                $this->repository->upsertMany($valid);
                $this->cache->forget('catalogue');
                $this->metrics->increment('imports');
                return count($valid);
            }
        }
        PHP,
    ]))->toBe([]);
});

it('rises in confidence with the size of the agreeing family', function (): void {
    $body = 'public function slugs($items) { $seen = []; foreach ($items as $i) { $seen[] = $i->%s; } sort($seen); $n = count($seen); return [$n, $seen]; }';

    $pair = RuleTester::runAcross(copyPasteDrift(['min_statements' => 4]), [
        'app/A.php' => 'class A { '.sprintf($body, 'slug').' }',
        'app/B.php' => 'class B { '.sprintf($body, 'brandSlug').' }',
    ]);

    $family = RuleTester::runAcross(copyPasteDrift(['min_statements' => 4]), [
        'app/A.php' => 'class A { '.sprintf($body, 'slug').' }',
        'app/B.php' => 'class B { '.sprintf($body, 'brandSlug').' }',
        'app/C.php' => 'class C { '.sprintf($body, 'categorySlug').' }',
    ]);

    expect($pair)->not->toBe([])
        ->and($family)->not->toBe([])
        ->and(max(array_map(static fn (Finding $f): int => $f->confidence, $family)))
        ->toBeGreaterThan(max(array_map(static fn (Finding $f): int => $f->confidence, $pair)));
});

it('honours max_token_distance with the ratio held open', function (): void {
    // Both options are set in both legs so that exactly one variable moves.
    // The earlier version of this test set only the budget and left the ratio
    // at its 0.08 default, which put the pass/fail line within a token or two
    // of the fixture's actual ratio -- a test that could break on a fixture
    // edit that had nothing to do with the budget.
    $files = driftFixturePair();

    $tight = copyPasteDrift(['max_token_distance' => 1, 'max_divergence_ratio' => 0.95, 'min_statements' => 4]);
    $loose = copyPasteDrift(['max_token_distance' => 40, 'max_divergence_ratio' => 0.95, 'min_statements' => 4]);

    expect(RuleTester::runAcross($tight, $files))->toBe([])
        ->and(RuleTester::runAcross($loose, $files))->toHaveCount(1);
});

it('honours max_divergence_ratio with the budget held open', function (): void {
    $files = driftFixturePair();

    $permissive = copyPasteDrift(['max_token_distance' => 40, 'max_divergence_ratio' => 0.95, 'min_statements' => 4]);
    $strict = copyPasteDrift(['max_token_distance' => 40, 'max_divergence_ratio' => 0.0011, 'min_statements' => 4]);

    expect(RuleTester::runAcross($permissive, $files))->toHaveCount(1)
        ->and(RuleTester::runAcross($strict, $files))->toBe([]);
});

it('has a fingerprint that survives the block moving down the file', function (): void {
    $body = 'public function pay($d) { $g = new %s(); $r = $g->charge($d->total, $d->currency); $this->log->info("paid"); $d->markPaid($r->id); return $r->id; }';

    $files = static fn (string $pad): array => [
        'app/One.php' => 'class One { '.sprintf($body, 'StripeGateway').' }',
        'app/Two.php' => 'class Two { '.sprintf($body, 'StripeGateway').' }',
        'app/Three.php' => $pad.'class Three { '.sprintf($body, 'PaypalGateway').' }',
    ];

    $before = RuleTester::runAcross(copyPasteDrift(['min_statements' => 4]), $files(''));
    $after = RuleTester::runAcross(copyPasteDrift(['min_statements' => 4]), $files("// a new import\n// and another\n"));

    expect($before[0]->fingerprint)->toBe($after[0]->fingerprint);
});
