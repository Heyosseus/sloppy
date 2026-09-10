<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Laravel\InlineValidationRule;

/**
 * @param  array<string, mixed>  $options
 */
function inlineValidation(array $options = []): InlineValidationRule
{
    return new InlineValidationRule($options);
}

it('flags a large inline rule set in a controller', function (): void {
    $found = findings(inlineValidation(), <<<'PHP'
    namespace App\Http\Controllers;

    class OrderController extends Controller
    {
        public function store(Request $request)
        {
            $data = $request->validate([
                'customer_id' => 'required|integer',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|integer',
                'items.*.quantity' => 'required|integer|min:1',
                'coupon_code' => 'nullable|string',
                'payment_method' => 'required|in:card,paypal',
            ]);

            return $data;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL202')
        ->and($found[0]->metrics['rules'])->toBe(6)
        ->and($found[0]->metrics['multi_constraint_rules'])->toBe(6)
        ->and($found[0]->suggestion)->toContain('FormRequest');
});

it('leaves a small inline check alone', function (): void {
    // A FormRequest for two fields is ceremony, not an improvement.
    expect(findings(inlineValidation(), <<<'PHP'
    namespace App\Http\Controllers;

    class SearchController extends Controller
    {
        public function index(Request $request)
        {
            $request->validate([
                'q' => 'required|string|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            return [];
        }
    }
    PHP))->toBeEmpty();
});

it('recognises Validator::make', function (): void {
    $found = findings(inlineValidation(['max_rules' => 3]), <<<'PHP'
    namespace App\Http\Controllers;

    class ImportController extends Controller
    {
        public function store(Request $request)
        {
            $validator = Validator::make($request->all(), [
                'file' => 'required|file',
                'delimiter' => 'nullable|string',
                'has_header' => 'boolean',
                'encoding' => 'nullable|string',
            ]);

            return $validator->validated();
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['via'])->toBe('Validator::make');
});

it('does not flag a FormRequest, which is already the extracted form', function (): void {
    expect(findings(inlineValidation(['controllers_only' => false]), <<<'PHP'
    namespace App\Http\Requests;

    class PlaceOrderRequest extends FormRequest
    {
        public function rules(): array
        {
            return [
                'customer_id' => 'required|integer',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|integer',
                'items.*.quantity' => 'required|integer|min:1',
                'coupon_code' => 'nullable|string',
                'payment_method' => 'required|in:card,paypal',
            ];
        }
    }
    PHP))->toBeEmpty();
});

it('ignores non-controllers by default', function (): void {
    expect(findings(inlineValidation(), <<<'PHP'
    namespace App\Actions;

    class ImportRows
    {
        public function handle(Request $request)
        {
            return $request->validate([
                'a' => 'required',
                'b' => 'required',
                'c' => 'required',
                'd' => 'required',
                'e' => 'required',
                'f' => 'required',
                'g' => 'required',
            ]);
        }
    }
    PHP))->toBeEmpty();
});

it('can be widened past controllers', function (): void {
    expect(findings(inlineValidation(['controllers_only' => false]), <<<'PHP'
    namespace App\Actions;

    class ImportRows
    {
        public function handle(Request $request)
        {
            return $request->validate([
                'a' => 'required',
                'b' => 'required',
                'c' => 'required',
                'd' => 'required',
                'e' => 'required',
                'f' => 'required',
                'g' => 'required',
            ]);
        }
    }
    PHP))->toHaveCount(1);
});
