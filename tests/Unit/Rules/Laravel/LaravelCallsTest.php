<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\Laravel\LaravelCalls;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;

/**
 * The outermost expression of a one-line snippet.
 */
function expressionIn(string $code): Node
{
    $file = parsedFile('class Probe { public function go(): mixed { return '.$code.'; } }');

    foreach ([MethodCall::class, StaticCall::class, New_::class, FuncCall::class] as $type) {
        $found = NodeHelper::find($file->ast, $type);

        if ($found !== []) {
            return NodeHelper::outermostChain($found[0]);
        }
    }

    throw new RuntimeException('No call found in: '.$code);
}

describe('database reads', function (): void {
    it('recognises a chain that reaches the database', function (): void {
        expect(LaravelCalls::isDatabaseRead(expressionIn("Order::where('a', 1)->get()")))->toBeTrue()
            ->and(LaravelCalls::isDatabaseRead(expressionIn('Order::find(1)')))->toBeTrue()
            ->and(LaravelCalls::isDatabaseRead(expressionIn("DB::table('a')->where('b', 1)->count()")))->toBeTrue();
    });

    it('does not mistake an unterminated chain for a query', function (): void {
        expect(LaravelCalls::isDatabaseRead(expressionIn("Order::query()->where('a', 1)")))->toBeFalse()
            ->and(LaravelCalls::looksLikeQuery(expressionIn("Order::query()->where('a', 1)")))->toBeTrue()
            ->and(LaravelCalls::looksLikeQuery(expressionIn('$this->thing->somethingElse()')))->toBeFalse();
    });

    it('ignores things that are not calls at all', function (): void {
        $file = parsedFile('class Probe { public function go(): mixed { return $this->value; } }');
        $fetch = NodeHelper::find($file->ast, Node\Expr\PropertyFetch::class)[0];

        expect(LaravelCalls::isDatabaseRead($fetch))->toBeFalse();
    });
});

describe('writes', function (): void {
    it('recognises the calls that change data', function (): void {
        expect(LaravelCalls::isWrite(expressionIn('$order->save()')))->toBeTrue()
            ->and(LaravelCalls::isWrite(expressionIn('Order::create([])')))->toBeTrue()
            ->and(LaravelCalls::isWrite(expressionIn('$order->items()->sync([1])')))->toBeTrue()
            ->and(LaravelCalls::isWrite(expressionIn('$order->total()')))->toBeFalse();
    });
});

describe('facades', function (): void {
    it('tells a facade from a model', function (): void {
        expect(LaravelCalls::isFacade(staticCallIn('Cache::get("a")')))->toBeTrue()
            ->and(LaravelCalls::isFacade(staticCallIn('DB::table("a")')))->toBeTrue()
            ->and(LaravelCalls::isFacade(staticCallIn('Order::all()')))->toBeFalse();
    });

    it('recognises a fully qualified facade', function (): void {
        $call = NodeHelper::find(
            parsedFile("use Illuminate\\Support\\Facades\\Redis;\n\nclass P { public function go(): mixed { return Redis::get('a'); } }")->ast,
            StaticCall::class,
        )[0];

        expect(LaravelCalls::isFacade($call))->toBeTrue();
    });

    it('does not treat a dynamic class as a facade', function (): void {
        $call = NodeHelper::find(
            parsedFile('class P { public function go(string $c): mixed { return $c::get("a"); } }')->ast,
            StaticCall::class,
        )[0];

        expect(LaravelCalls::isFacade($call))->toBeFalse();
    });
});

describe('outbound calls', function (): void {
    it('recognises the Http facade, guzzle, curl and remote reads', function (): void {
        expect(LaravelCalls::isHttpCall(expressionIn("Http::post('https://example.com')")))->toBeTrue()
            ->and(LaravelCalls::isHttpCall(expressionIn('new \GuzzleHttp\Client()')))->toBeTrue()
            ->and(LaravelCalls::isHttpCall(expressionIn('curl_init()')))->toBeTrue()
            ->and(LaravelCalls::isHttpCall(expressionIn("file_get_contents('https://example.com/a')")))->toBeTrue()
            ->and(LaravelCalls::isHttpCall(expressionIn("fopen('http://example.com', 'r')")))->toBeTrue();
    });

    it('does not treat local reads or other facades as outbound', function (): void {
        expect(LaravelCalls::isHttpCall(expressionIn("file_get_contents('/tmp/a.csv')")))->toBeFalse()
            ->and(LaravelCalls::isHttpCall(expressionIn("Cache::get('a')")))->toBeFalse()
            ->and(LaravelCalls::isHttpCall(expressionIn('new \App\Thing()')))->toBeFalse()
            ->and(LaravelCalls::isHttpCall(expressionIn('file_get_contents($path)')))->toBeFalse()
            ->and(LaravelCalls::isHttpCall(expressionIn('strlen("a")')))->toBeFalse();
    });

    it('recognises dispatching work outward', function (): void {
        expect(LaravelCalls::isDispatch(expressionIn('Mail::to($a)')))->toBeTrue()
            ->and(LaravelCalls::isDispatch(expressionIn('$user->notify($n)')))->toBeTrue()
            ->and(LaravelCalls::isDispatch(expressionIn('event($e)')))->toBeTrue()
            ->and(LaravelCalls::isDispatch(expressionIn('$order->total()')))->toBeFalse()
            ->and(LaravelCalls::isDispatch(expressionIn('Order::all()')))->toBeFalse();
    });
});

describe('transactions', function (): void {
    it('recognises opening a transaction', function (): void {
        expect(LaravelCalls::isTransaction(staticCallIn('DB::beginTransaction()')))->toBeTrue()
            ->and(LaravelCalls::isTransaction(staticCallIn('DB::transaction($c)')))->toBeTrue()
            ->and(LaravelCalls::isTransaction(staticCallIn("DB::table('a')")))->toBeFalse()
            ->and(LaravelCalls::isTransaction(staticCallIn('Cache::get("a")')))->toBeFalse()
            ->and(LaravelCalls::isTransaction(expressionIn('$order->save()')))->toBeFalse();
    });
});

/**
 * The static call in a one-line snippet.
 */
function staticCallIn(string $code): Node
{
    return NodeHelper::find(
        parsedFile('class P { public function go(mixed $c): mixed { return '.$code.'; } }')->ast,
        StaticCall::class,
    )[0];
}
