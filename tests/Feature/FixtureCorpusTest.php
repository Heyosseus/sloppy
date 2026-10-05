<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RuleTester;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * The sloppy corpus, including the SL111 drift fixtures.
 *
 * `RuleTester::fixtureDirectory()` globs one level only, so the near-miss
 * pairs under `tests/Fixtures/Sloppy/Drift/` need their own call merged in --
 * they live in a subdirectory precisely so SL111's fixtures do not clutter
 * the flat list every other rule's fixture lives in.
 *
 * @return array<string, string>
 */
function sloppyCorpus(): array
{
    return RuleTester::fixtureDirectory('Sloppy') + RuleTester::fixtureDirectory('Sloppy/Drift');
}

/**
 * The false-positive gate.
 *
 * `tests/Fixtures/Good` is deliberately ordinary Laravel code: a thin
 * controller, a coordinating action, a model with many small members, and a
 * client whose job is to make HTTP calls. A static analyser that complains
 * about code like this is worse than no analyser, so every rule must stay
 * silent here. If a change to any rule breaks this test, the rule is wrong.
 */
it('reports nothing at all on ordinary Laravel code', function (): void {
    $result = RuleTester::runAll(RuleTester::fixtureDirectory('Good'));

    expect($result->findings)->toBe([], sprintf(
        "Expected no findings on clean fixtures, got:\n%s",
        implode("\n", array_map(
            static fn (Finding $f): string => sprintf('%s %s %s — %s', $f->ruleId, $f->severity->value, (string) $f->location, $f->message),
            $result->findings,
        )),
    ))
        ->and($result->score->value)->toBe(100)
        ->and($result->errors)->toBe([]);
});

it('recognises the sloppy fixtures without crashing on any rule', function (): void {
    $result = RuleTester::runAll(sloppyCorpus());

    expect($result->errors)->toBe([])
        ->and($result->count())->toBeGreaterThan(20)
        ->and($result->score->value)->toBeLessThan(60);
});

it('exercises all but six rules on the sloppy fixtures', function (): void {
    $result = RuleTester::runAll(sloppyCorpus());
    $fired = array_values(array_unique(ruleIds($result->findings)));
    sort($fired);

    // SL102 needs a genuinely huge class and SL207 a service with eight
    // collaborators; both are covered by their own unit tests with generated
    // sources rather than by hundreds of lines of fixture. SL111 now fires
    // here too: `Drift/RefundTbcPayment.php` is missing the failure guard its
    // sibling has, and `Drift/GatewayFamily.php` news up a `PaypalGateway`
    // where its two siblings both use `StripeGateway` -- the masked-value
    // divergence structural hashing alone cannot see. SL304 to SL306 and SL308 enforce
    // a declared architecture and the sloppy corpus declares none: they have
    // their own corpus pass below, under CORPUS_ARCHITECTURE.
    expect(array_values(array_diff(RuleRegistry::withDefaults()->ids(), $fired)))
        ->toBe(['SL102', 'SL207', 'SL304', 'SL305', 'SL306', 'SL308']);
});

it('finds the same things every time it runs', function (): void {
    $files = sloppyCorpus();

    $first = RuleTester::runAll($files);
    $second = RuleTester::runAll($files);

    expect(array_map(static fn (Finding $f): string => $f->identity(), $first->findings))
        ->toBe(array_map(static fn (Finding $f): string => $f->identity(), $second->findings))
        ->and($first->score->value)->toBe($second->score->value);
});

it('gives every finding a message, an explanation and a suggestion', function (): void {
    $result = RuleTester::runAll(sloppyCorpus());

    foreach ($result->findings as $finding) {
        expect($finding->message)->not->toBe('')
            ->and($finding->explanation)->not->toBe('')
            ->and($finding->suggestion)->not->toBe('')
            ->and($finding->fingerprint)->not->toBe('')
            ->and($finding->confidence)->toBeGreaterThan(0)
            ->and($finding->location->line)->toBeGreaterThan(0);
    }
});

it('never claims certainty it cannot have', function (): void {
    $result = RuleTester::runAll(sloppyCorpus());

    foreach ($result->findings as $finding) {
        // A heuristic must not report 100%: confidence is the analyser's
        // certainty that the pattern is present, and it is never absolute.
        expect($finding->confidence)->toBeLessThan(100);
    }
});

/**
 * An architecture the Good corpus honours on purpose: the laravel preset, an
 * `integration` role for the payment client, a policy on every role the
 * corpus has (dependencies, capabilities and shape), and module boundaries
 * between integrations. Every one of SL304, SL305, SL306 and SL308 has
 * something to enforce here, so silence on Good means the rules agree the code
 * is ordinary, not that they had nothing to look at.
 */
const CORPUS_ARCHITECTURE = [
    'preset' => 'laravel',
    'roles' => [
        'integration' => ['namespace' => 'App\Integrations\*', 'suffix' => 'Client'],
    ],
    'policies' => [
        'controller' => [
            'may_not' => ['env', 'container', 'view'],
            'may_not_depend_on' => ['integration'],
        ],
        'service' => [
            'may_depend_on' => ['model'],
            'may_not_depend_on' => ['controller', 'form-request', 'middleware'],
            'may_not' => ['request', 'env', 'view'],
            'public_methods' => ['handle', 'cancel'],
            'final' => true,
        ],
        'model' => [
            'may_not_depend_on' => ['controller', 'service', 'integration'],
            'may_not' => ['request', 'env', 'container'],
            'final' => true,
        ],
        'integration' => [
            'may_not_depend_on' => ['controller', 'service', 'model'],
            'may_not' => ['db', 'request', 'env', 'view', 'container'],
            'final' => true,
        ],
    ],
    'boundaries' => [
        'modules' => 'App\Integrations\{module}\*',
        'public' => ['*Client'],
    ],
];

const POLICY_RULES = ['SL304', 'SL305', 'SL306', 'SL308'];

const DIFF_ONLY_RULES = ['SL307', 'SL502', 'SL503'];

it('gives every Good class a role the corpus architecture has a policy for', function (): void {
    $snapshot = architectureSnapshotOf(RuleTester::fixtureDirectory('Good'), CORPUS_ARCHITECTURE);

    // Without this the next test could pass because nothing was classified.
    expect($snapshot->roleOf('App\Http\Controllers\CheckoutController'))->toBe('controller')
        ->and($snapshot->roleOf('App\Actions\PlaceOrder'))->toBe('service')
        ->and($snapshot->roleOf('App\Models\Product'))->toBe('model')
        ->and($snapshot->roleOf('App\Integrations\Payments\PaymentClient'))->toBe('integration');
});

it('reports nothing at all on ordinary Laravel code under a declared architecture', function (): void {
    $result = RuleTester::runAll(RuleTester::fixtureDirectory('Good'), Profile::fromArray(CORPUS_ARCHITECTURE));

    expect($result->findings)->toBe([], sprintf(
        "Expected no findings on clean fixtures under the corpus architecture, got:\n%s",
        implode("\n", array_map(
            static fn (Finding $f): string => sprintf('%s %s — %s', $f->ruleId, (string) $f->location, $f->message),
            $result->findings,
        )),
    ))
        ->and($result->score->value)->toBe(100)
        ->and($result->errors)->toBe([]);
});

it('reports every policy rule on code that breaks the corpus architecture, and only there', function (): void {
    // The violations are analysed next to the Good corpus, so a dependency on
    // the payment client resolves to its role, and so any finding on a Good
    // file would show up here too.
    $result = RuleTester::runAll(
        RuleTester::fixtureDirectory('Good') + RuleTester::fixtureDirectory('Architecture'),
        Profile::fromArray(CORPUS_ARCHITECTURE),
    );

    $policy = array_values(array_filter(
        $result->findings,
        static fn (Finding $finding): bool => in_array($finding->ruleId, POLICY_RULES, true),
    ));

    $where = array_map(static fn (Finding $finding): string => $finding->ruleId.' '.$finding->location->relativePath, $policy);
    sort($where);

    expect($where)->toBe([
        'SL304 app/ReportController.php',
        'SL305 app/ReportController.php',
        'SL306 app/ShippingClient.php',
        'SL308 app/RefundOrder.php',
        'SL308 app/RefundOrder.php',
    ])
        ->and(messages($policy))
        ->toContain('ReportController (controller) depends on App\Integrations\Payments\PaymentClient (integration)')
        ->toContain('ReportController::refund() reads the environment (env())')
        ->toContain('Internal\Signer is internal to the Payments module')
        ->toContain('RefundOrder is not final')
        ->toContain('RefundOrder::audit() is public');
});

/**
 * The diff-only checks over a throwaway repository whose base commit has a
 * PHPStan baseline, a Pest test and one legacy class, judged against the
 * corpus architecture with `covers` on.
 *
 * @param  array<string, string>  $change  Files written on top of the base commit.
 * @return list<string> "RULE path" for each new SL307, SL502 and SL503 finding.
 */
function diffOnlyFindings(array $change): array
{
    $repository = TempRepository::create()
        ->write('sloppy.php', '<?php return '.var_export([
            'paths' => ['app', 'tests'],
            'exclude' => ['vendor'],
            'architecture' => [...CORPUS_ARCHITECTURE, 'covers' => ['app/*']],
        ], true).';')
        ->write('phpstan-baseline.neon', corpusBaseline(['app/Legacy/Importer.php' => 2]))
        ->write('tests/Feature/CheckoutTest.php', corpusTest(['expect($response->status())->toBe(201);']))
        ->write('app/Legacy/Importer.php', "<?php\n\nnamespace App\\Legacy;\n\nfinal class Importer\n{\n}\n")
        ->commit('base');

    try {
        foreach ($change as $path => $source) {
            $repository->write($path, $source);
        }

        $report = Sloppy::forProject($repository->path)->diff('HEAD');

        return array_values(array_map(
            static fn (Finding $finding): string => $finding->ruleId.' '.$finding->location->relativePath,
            array_filter($report->new, static fn (Finding $finding): bool => in_array($finding->ruleId, DIFF_ONLY_RULES, true)),
        ));
    } finally {
        $repository->remove();
    }
}

/**
 * @param  array<string, int>  $entries  Path => how many errors it silences.
 */
function corpusBaseline(array $entries): string
{
    $neon = "parameters:\n\tignoreErrors:\n";

    foreach ($entries as $path => $count) {
        $neon .= "\t\t-\n\t\t\tmessage: \"#^Undefined variable\\\\.$#\"\n\t\t\tcount: ".$count."\n\t\t\tpath: ".$path."\n";
    }

    return $neon;
}

/**
 * @param  list<string>  $assertions
 */
function corpusTest(array $assertions, string $more = ''): string
{
    return "<?php\n\nit('places an order', function (): void {\n    \$response = \$this->postJson('/orders', []);\n\n    "
        .implode("\n    ", $assertions)
        ."\n});\n".$more;
}

it('stays quiet in diff mode on a change made of ordinary code', function (): void {
    // The Good classes added where the architecture covers them, one PHPStan
    // error fixed rather than baselined, and a test that asserts more.
    $change = RuleTester::fixtureDirectory('Good') + [
        'phpstan-baseline.neon' => corpusBaseline(['app/Legacy/Importer.php' => 1]),
        'tests/Feature/CheckoutTest.php' => corpusTest(
            ['expect($response->status())->toBe(201);', 'expect($response->json(\'id\'))->toBeInt();'],
            "\nit('lists orders', function (): void {\n    expect(\$this->getJson('/orders')->status())->toBe(200);\n});\n",
        ),
    ];

    expect(diffOnlyFindings($change))->toBe([]);
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('reports all three diff-only checks on a change that earns them', function (): void {
    $findings = diffOnlyFindings([
        'app/Support/Helpers/DataUtils.php' => "<?php\n\nnamespace App\\Support\\Helpers;\n\nfinal class DataUtils\n{\n}\n",
        'phpstan-baseline.neon' => corpusBaseline(['app/Legacy/Importer.php' => 4]),
        'tests/Feature/CheckoutTest.php' => corpusTest(['expect(true)->toBeTrue();']),
    ]);
    sort($findings);

    expect($findings)->toBe([
        'SL307 app/Support/Helpers/DataUtils.php',
        'SL502 app/Legacy/Importer.php',
        'SL503 tests/Feature/CheckoutTest.php',
    ]);
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');
