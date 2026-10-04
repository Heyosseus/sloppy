<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\Boundaries;
use Heyosseus\Sloppy\Architecture\Profile;

function modules(): Boundaries
{
    $boundaries = Profile::fromArray(['boundaries' => [
        'modules' => ['App\Modules\{module}\*', 'Modules\{module}\*'],
        'public' => ['Contracts\*', 'Events\*'],
        'shared' => ['App\Modules\Shared\*'],
    ]])->boundaries;

    assert($boundaries instanceof Boundaries);

    return $boundaries;
}

it('finds a class\'s module from any of the patterns, by the captured name', function (): void {
    expect(modules()->moduleOf('App\Modules\Billing\Invoices\Invoice')?->name)->toBe('Billing')
        ->and(modules()->moduleOf('App\Modules\Billing\Invoices\Invoice')?->root)->toBe('App\Modules\Billing\\')
        ->and(modules()->moduleOf('Modules\Shipping\Rates')?->name)->toBe('Shipping')
        ->and(modules()->moduleOf('App\Models\User'))->toBeNull();
});

it('lets a module reach another only through its public surface or the shared kernel', function (): void {
    expect(modules()->violation('App\Modules\Billing\Invoice', 'App\Modules\Shipping\Internal\RateTable'))
        ->toBe('Internal\RateTable is internal to the Shipping module, and Invoice is in Billing')
        ->and(modules()->violation('App\Modules\Billing\Invoice', 'App\Modules\Shipping\Contracts\Rates'))->toBeNull()
        ->and(modules()->violation('App\Modules\Billing\Invoice', 'Modules\Shipping\Events\Shipped'))->toBeNull()
        ->and(modules()->violation('App\Modules\Billing\Invoice', 'App\Modules\Shared\Money'))->toBeNull()
        ->and(modules()->violation('App\Modules\Billing\Invoice', 'App\Modules\Billing\Internal\Tax'))->toBeNull()
        ->and(modules()->violation('App\Modules\Billing\Invoice', 'App\Models\User'))->toBeNull()
        ->and(modules()->violation('App\Http\Controllers\X', 'App\Modules\Shipping\Internal\RateTable'))->toBeNull();
});
