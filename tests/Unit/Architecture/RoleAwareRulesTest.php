<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Architecture\ArchitectureMap;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Contracts\Rule;
use Heyosseus\Sloppy\Rules\Laravel\DirectExternalApiRule;
use Heyosseus\Sloppy\Rules\Laravel\ExcessiveControllerDependenciesRule;
use Heyosseus\Sloppy\Rules\Laravel\ExcessiveServiceDependenciesRule;

/**
 * @param  array<string, mixed>  $architecture
 * @return list<Finding>
 */
function findingsWithArchitecture(Rule $rule, string $code, array $architecture): array
{
    $file = parsedFile($code);
    $context = new AnalysisContext($file, ProjectIndex::build([$file]), new ArchitectureMap(Profile::fromArray($architecture)));

    return [...$rule->analyze($context)];
}

function crowdedConstructor(string $declaration): string
{
    $params = implode(', ', array_map(
        static fn (int $n): string => sprintf('private \App\Ports\Port%d $p%d', $n, $n),
        range(1, 9),
    ));

    return sprintf('%s { public function __construct(%s) {} }', $declaration, $params);
}

it('follows a project that keeps its controllers somewhere Sloppy would not guess', function (): void {
    $code = crowdedConstructor('namespace App\Ui\Web; class Checkout');
    $rule = new ExcessiveControllerDependenciesRule;

    expect(findingsWithArchitecture($rule, $code, []))->toBe([])
        ->and(findingsWithArchitecture($rule, $code, ['roles' => ['controller' => ['namespace' => 'App\Ui\Web\*']]]))->toHaveCount(1);
});

it('stops treating a class as a service once the project says what it is', function (): void {
    $code = crowdedConstructor('namespace App\Domain\Billing; class InvoiceGateway');
    $rule = new ExcessiveServiceDependenciesRule;

    expect(findingsWithArchitecture($rule, $code, []))->toHaveCount(1)
        ->and(findingsWithArchitecture($rule, $code, ['roles' => ['adapter' => ['suffix' => 'Gateway']]]))->toBe([]);
});

it('stops reporting a layer the project removed', function (): void {
    $code = 'namespace App\Http\Controllers; class Hook { public function __invoke() { return \Illuminate\Support\Facades\Http::get("https://x.test"); } }';
    $rule = new DirectExternalApiRule;

    expect(findingsWithArchitecture($rule, $code, []))->toHaveCount(1)
        ->and(findingsWithArchitecture($rule, $code, ['roles' => ['controller' => false]]))->toBe([]);
});
