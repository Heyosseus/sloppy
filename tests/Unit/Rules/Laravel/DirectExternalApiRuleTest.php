<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Laravel\DirectExternalApiRule;

function directApi(): DirectExternalApiRule
{
    return new DirectExternalApiRule;
}

it('flags an HTTP call in a controller', function (): void {
    $found = findings(directApi(), <<<'PHP'
    namespace App\Http\Controllers;

    class CouponController extends Controller
    {
        public function redeem(Request $request)
        {
            $response = Http::post('https://coupons.example.com/redeem', [
                'code' => $request->code,
            ]);

            return $response->json();
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL208')
        ->and($found[0]->metrics['layer'])->toBe('a controller')
        ->and($found[0]->suggestion)->toContain('CouponClient');
});

it('flags an HTTP call in a model', function (): void {
    $found = findings(directApi(), <<<'PHP'
    namespace App\Models;

    class Order extends Model
    {
        public function dispatch(): void
        {
            Http::post('https://warehouse.example.com/dispatch', ['id' => $this->id]);
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['layer'])->toBe('an Eloquent model');
});

it('flags curl and remote file reads', function (): void {
    $found = findings(directApi(), <<<'PHP'
    namespace App\Http\Middleware;

    class VerifyLicence
    {
        public function handle($request, $next)
        {
            $body = file_get_contents('https://licences.example.com/check');

            return $next($request);
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['layer'])->toBe('middleware');
});

it('does not flag the client that exists to make the call', function (): void {
    expect(findings(directApi(), <<<'PHP'
    namespace App\Integrations;

    class CouponClient
    {
        public function redeem(string $code): array
        {
            return Http::post('https://coupons.example.com/redeem', ['code' => $code])->json();
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a gateway or connector', function (): void {
    expect(findings(directApi(), <<<'PHP'
    namespace App\Http\Controllers;

    class PaymentGateway
    {
        public function charge(int $cents): array
        {
            return Http::post('https://payments.example.com/charges', ['amount' => $cents])->json();
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a service, where integrations are allowed to live', function (): void {
    expect(findings(directApi(), <<<'PHP'
    namespace App\Services;

    class CouponService
    {
        public function redeem(string $code): array
        {
            return Http::post('https://coupons.example.com/redeem', ['code' => $code])->json();
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a local read of a file path', function (): void {
    expect(findings(directApi(), <<<'PHP'
    namespace App\Http\Controllers;

    class ExportController extends Controller
    {
        public function show()
        {
            return file_get_contents(storage_path('export.csv'));
        }
    }
    PHP))->toBeEmpty();
});

it('reports one finding per method, not per call', function (): void {
    $found = findings(directApi(), <<<'PHP'
    namespace App\Http\Controllers;

    class SyncController extends Controller
    {
        public function sync()
        {
            Http::get('https://a.example.com');
            Http::get('https://b.example.com');
            Http::get('https://c.example.com');
        }
    }
    PHP);

    expect($found)->toHaveCount(1);
});
