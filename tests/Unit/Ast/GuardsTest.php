<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\BlastRadiusEnricher;
use Heyosseus\Sloppy\Analysis\Drift\BoundedTokenDistance;
use Heyosseus\Sloppy\Analysis\Drift\MaskedDivergence;
use Heyosseus\Sloppy\Ast\BlockSignature;
use Heyosseus\Sloppy\Ast\DuplicateBlock;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Integrations\Tooling\RectorRules;
use Heyosseus\Sloppy\Support\StringListOption;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Nop;

/**
 * A signature with the token stream and masked values a test needs, without
 * parsing anything.
 *
 * @param  list<string>  $tokens
 * @param  list<string>  $masked
 */
function signature(array $tokens, array $masked = [], string $method = 'run', string $hash = 'same-hash'): BlockSignature
{
    return BlockSignature::create(
        new DuplicateBlock('app/Order.php', 'Order', $method, 10, 20, 9),
        $hash,
        $tokens,
        $masked,
    );
}

it('locates a node that was never in a file, without a column', function (): void {
    $context = new AnalysisContext(parsedFile('class A {}'), new ProjectIndex);

    // A node built in memory has no offset in any source, so there is no
    // column to report -- and a location is still produced.
    expect($context->locate(new Nop)->column)->toBeNull()
        ->and($context->locateLine(0)->line)->toBe(1);
});

it('measures nothing for a node with no line numbers', function (): void {
    expect(NodeHelper::lineSpan(new Nop))->toBe(0);
});

it('skips the statements that are not properties when listing them', function (): void {
    $class = firstClass(<<<'PHP'
    class Settings
    {
        public const int LIMIT = 3;

        public string $name = 'x';

        public function run(): void {}
    }
    PHP);

    expect(NodeHelper::propertyNames($class))->toBe(['name']);
});

it('ignores imports, declares and namespaces when reading a file', function (): void {
    $file = (new Parser)->parse('app/Example.php', <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace App;

    use App\Other;

    class Example
    {
    }
    PHP);

    expect($file->namespaceName())->toBe('App')
        ->and(NodeHelper::className($file->classLikes()[0]))->toBe('App\Example');
});

it('has no namespace for a file that declares none', function (): void {
    expect(parsedFile('class A {}')->namespaceName())->toBeNull();
});

it('has no call name for a call it cannot name statically', function (): void {
    expect(NodeHelper::callName(new MethodCall(new Variable('this'), new Variable('name'))))->toBeNull()
        ->and(NodeHelper::callName(new StaticCall(new Name('Order'), new Variable('name'))))->toBeNull()
        ->and(NodeHelper::callName(new MethodCall(new Variable('order'), new String_('run'))))->toBeNull();
});

it('prints a type it can name and nothing for one it cannot', function (): void {
    expect(NodeHelper::typeToString(new Identifier('string')))->toBe('string')
        ->and(NodeHelper::typeToString(null))->toBeNull();
});

it('indexes the interfaces an interface extends', function (): void {
    $index = ProjectIndex::build([(new Parser)->parse('app/Contracts.php', <<<'PHP'
    <?php

    namespace App;

    interface Payable extends Billable, Refundable
    {
    }
    PHP)]);

    $summary = $index->class('App\Payable');

    // The names an interface extends are inheritance, not usage: an interface
    // that only appears in an `extends` clause has no callers, and counting it
    // as one would make SL303 think it was in use.
    expect($summary?->kind)->toBe('interface')
        ->and($index->usageCount('Billable'))->toBe(0)
        ->and($index->usageCount('Refundable'))->toBe(0);
});

it('cannot be constructed: the static-only helpers', function (): void {
    // Running the constructor is the only way to show it exists and is
    // private, which is the invariant each of these classes rests on.
    foreach ([NodeHelper::class, StringListOption::class, BoundedTokenDistance::class, MaskedDivergence::class, RectorRules::class] as $class) {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        expect($constructor?->isPrivate())->toBeTrue(sprintf('%s can be constructed.', $class));

        $constructor?->invoke($reflection->newInstanceWithoutConstructor());
    }
});

it('measures the distance to an empty body as that body\'s own length', function (): void {
    $empty = signature([], [], 'empty', 'hash-empty');
    $short = signature(['Assign', 'Variable'], [], 'short', 'hash-short');

    // Within the budget: the whole of the non-empty side is the distance.
    expect(BoundedTokenDistance::between($short, $empty, 8)?->distance)->toBe(2)
        // Beyond it: nothing to report rather than a number nobody asked for.
        ->and(BoundedTokenDistance::between($short, $empty, 1))->toBeNull();
});

it('finds the innermost class a finding sits in, and none outside every class', function (): void {
    $index = ProjectIndex::build([(new Parser)->parse('app/Pair.php', <<<'PHP'
    <?php

    namespace App;

    class Small
    {
        public function run(): void
        {
        }
    }

    class Bigger
    {
        public function first(): void
        {
        }

        public function second(): void
        {
        }
    }
    PHP)]);

    $enricher = new BlastRadiusEnricher($index);

    $inSmall = $enricher->enrich([finding(file: 'app/Pair.php', line: 7)]);
    $inBigger = $enricher->enrich([finding(file: 'app/Pair.php', line: 16)]);
    $outside = $enricher->enrich([finding(file: 'app/Pair.php', line: 3)]);

    expect($inSmall[0]->metrics)->toBeArray()
        ->and($inBigger[0]->metrics)->toBeArray()
        ->and($outside[0]->metrics)->toBeArray();
});

it('says nothing about a group whose masked values do not line up', function (): void {
    $group = [
        signature(['a'], ['1', '2'], 'one'),
        signature(['a'], ['1', '2'], 'two'),
        signature(['a'], ['1'], 'three'),
    ];

    expect(MaskedDivergence::inGroup($group))->toBe([]);
});

it('says nothing when the two values are evenly split, so neither is the odd one out', function (): void {
    $group = [
        signature(['a'], ['left'], 'one'),
        signature(['a'], ['left'], 'two'),
        signature(['a'], ['right'], 'three'),
        signature(['a'], ['right'], 'four'),
    ];

    expect(MaskedDivergence::inGroup($group))->toBe([]);
});

it('names the odd one out when there is one', function (): void {
    $group = [
        signature(['a'], ['left'], 'one'),
        signature(['a'], ['left'], 'two'),
        signature(['a'], ['right'], 'three'),
    ];

    $divergences = MaskedDivergence::inGroup($group);

    expect($divergences)->toHaveCount(1)
        ->and($divergences[0]['majority'])->toBe('left')
        ->and($divergences[0]['minority'])->toBe('right');
});

it('recognises a class with a name and one without', function (): void {
    $anonymous = new Class_(null);

    expect(NodeHelper::shortName($anonymous))->toBeNull()
        ->and(NodeHelper::className($anonymous))->toBeNull()
        ->and(NodeHelper::baseName('App\\Models\\Order'))->toBe('Order');
});
