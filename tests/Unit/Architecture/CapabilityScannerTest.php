<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\Capability;
use Heyosseus\Sloppy\Architecture\CapabilityScanner;
use Heyosseus\Sloppy\Architecture\CapabilityUse;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;

/**
 * What the scanner finds in the first class of `$code`, as "capability
 * evidence" lines, with `$others` indexed alongside.
 *
 * @param  array<string, string>  $others
 * @return list<string>
 */
function capabilitiesIn(string $code, array $others = []): array
{
    $parser = new Parser;
    $file = $parser->parse('app/Subject.php', "<?php\n\n".$code);
    $files = [$file];

    foreach ($others as $path => $source) {
        $files[] = $parser->parse($path, "<?php\n\n".$source);
    }

    $scanner = new CapabilityScanner(ProjectIndex::build($files));

    return array_map(
        static fn (CapabilityUse $use): string => $use->capability->value.' '.$use->evidence,
        $scanner->scan($file->classLikes()[0]),
    );
}

const ORDER_MODEL = ['app/Models/Order.php' => 'namespace App\Models; class Order extends \Illuminate\Database\Eloquent\Model {}'];

it('reads and writes the database through a model the index knows', function (): void {
    $code = 'namespace App; use App\Models\Order; class S {
        public function a() { return Order::where("x", 1)->get(); }
        public function b() { Order::where("x", 1)->update(["y" => 2]); }
        public function c() { return Order::create([]); }
        public function d() { return Order::statusLabels(); }
    }';

    expect(capabilitiesIn($code, ORDER_MODEL))->toBe([
        'db.read Order::where()',
        'db.write Order::where()',
        'db.write Order::create()',
    ]);
});

it('follows a model through the project\'s own base classes', function (): void {
    $others = [
        'app/Models/Base.php' => 'namespace App\Models; abstract class Base extends \Illuminate\Database\Eloquent\Model {}',
        'app/Models/Invoice.php' => 'namespace App\Models; class Invoice extends Base {}',
    ];

    expect(capabilitiesIn('namespace App; class S { public function a() { return \App\Models\Invoice::find(1); } }', $others))
        ->toBe(['db.read Invoice::find()']);
});

it('reads and writes through the DB facade', function (): void {
    $code = 'namespace App; use Illuminate\Support\Facades\DB; class S {
        public function a() { return DB::table("orders")->get(); }
        public function b() { DB::transaction(fn () => null); }
        public function c() { return \DB::select("select 1"); }
    }';

    expect(capabilitiesIn($code))->toBe([
        'db.read DB::table()',
        'db.write DB::transaction()',
        'db.read DB::select()',
    ]);
});

it('does not count what it cannot tell from any other object or class', function (): void {
    $code = 'namespace App; use Carbon\Carbon; class S {
        public function a($order, $items) {
            $order->save();
            $items->count();
            strlen("x");
            Carbon::create(2026, 1, 1);
            \Vendor\Thing::where("x");
            self::create();
            return $this->repository->find(1);
        }
        public static function create() {}
    }';

    expect(capabilitiesIn($code))->toBe([]);
});

it('finds HTTP, dispatching, the request, the environment, views and the container', function (): void {
    $code = 'namespace App; use Illuminate\Support\Facades\Http; use Illuminate\Support\Facades\Mail; use Illuminate\Support\Facades\View; use Illuminate\Support\Facades\App; use Illuminate\Http\Request; use App\Jobs\Sync; class S {
        public function a(Request $request) {
            Http::get("https://x.test");
            new \GuzzleHttp\Client();
            curl_init();
            Mail::to("a")->send(null);
            Sync::dispatch();
            dispatch(new Sync);
            $request->user()->notify(null);
            request("q");
            env("KEY");
            view("home");
            View::make("home");
            \Inertia\Inertia::render("Home");
            app("x");
            App::make("x");
            \Illuminate\Container\Container::getInstance();
        }
    }';

    expect(capabilitiesIn($code))->toBe([
        'request takes Request',
        'http Http::get()',
        'http new Client',
        'http curl_init()',
        'dispatch Mail::to()',
        'dispatch Sync::dispatch()',
        'dispatch dispatch()',
        'dispatch ->notify()',
        'request request()',
        'env env()',
        'view view()',
        'view View::make()',
        'view Inertia::render()',
        'container app()',
        'container App::make()',
        'container Container::getInstance()',
    ]);
});

it('counts a capability a class is handed through its constructor', function (): void {
    $code = 'namespace App; class S {
        public function __construct(
            private \Illuminate\Http\Client\Factory $http,
            private ?\Illuminate\Database\ConnectionInterface $db,
            private \Illuminate\Contracts\Bus\Dispatcher|\Illuminate\Contracts\View\Factory $mixed,
            private \Illuminate\Contracts\Foundation\Application $app,
            private string $name,
        ) {}
    }';

    expect(capabilitiesIn($code))->toBe([
        'http injects Illuminate\Http\Client\Factory',
        'db.read injects Illuminate\Database\ConnectionInterface',
        'db.write injects Illuminate\Database\ConnectionInterface',
        'dispatch injects Illuminate\Contracts\Bus\Dispatcher',
        'view injects Illuminate\Contracts\View\Factory',
        'container injects Illuminate\Contracts\Foundation\Application',
    ]);
});

it('counts a form request parameter as reading the request', function (): void {
    $others = [
        'app/Http/Requests/StoreOrder.php' => 'namespace App\Http\Requests; class StoreOrder extends \Illuminate\Foundation\Http\FormRequest {}',
    ];

    expect(capabilitiesIn('namespace App; class S { public function a(\App\Http\Requests\StoreOrder $r, \Illuminate\Foundation\Http\FormRequest $f, \App\Other $o) {} }', $others))
        ->toBe(['request takes StoreOrder', 'request takes FormRequest']);
});

it('reads db and db.* as both database capabilities', function (): void {
    expect(Capability::parse('db'))->toBe([Capability::DatabaseRead, Capability::DatabaseWrite])
        ->and(Capability::parse(' DB.* '))->toBe([Capability::DatabaseRead, Capability::DatabaseWrite])
        ->and(Capability::parse('http'))->toBe([Capability::Http])
        ->and(Capability::parse('telepathy'))->toBe([])
        ->and(Capability::names())->toBe(['db', 'db.read', 'db.write', 'http', 'dispatch', 'request', 'env', 'view', 'container']);
});
