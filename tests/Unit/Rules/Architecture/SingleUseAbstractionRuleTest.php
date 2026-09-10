<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Rules\Architecture\SingleUseAbstractionRule;

/**
 * @param  array<string, mixed>  $options
 */
function singleUse(array $options = []): SingleUseAbstractionRule
{
    return new SingleUseAbstractionRule($options);
}

it('flags an interface with one implementation and no other consumer', function (): void {
    $found = findingsAcross(singleUse(), [
        'app/PdfRendererInterface.php' => <<<'PHP'
        namespace App\Rendering;

        interface PdfRendererInterface
        {
            public function render(string $html): string;
        }
        PHP,
        'app/PdfRenderer.php' => <<<'PHP'
        namespace App\Rendering;

        class PdfRenderer implements PdfRendererInterface
        {
            public function render(string $html): string
            {
                return base64_encode($html);
            }
        }
        PHP,
    ]);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL303')
        ->and($found[0]->severity)->toBe(Severity::Low)
        ->and($found[0]->metrics['implementation'])->toBe('PdfRenderer')
        ->and($found[0]->metrics['usages'])->toBe(0);
});

it('presents itself as advice rather than a defect', function (): void {
    $found = findingsAcross(singleUse(), [
        'app/ClockInterface.php' => "namespace App;\n\ninterface ClockInterface\n{\n    public function now(): int;\n}",
        'app/Clock.php' => "namespace App;\n\nclass Clock implements ClockInterface\n{\n    public function now(): int\n    {\n        return time();\n    }\n}",
    ]);

    expect($found[0]->explanation)->toContain('advice, not a defect')
        ->and($found[0]->suggestion)->toContain('actually planned')
        ->and($found[0]->confidence)->toBeLessThan(80);
});

it('does not flag an interface with two implementations', function (): void {
    expect(findingsAcross(singleUse(), [
        'app/ClockInterface.php' => "namespace App;\n\ninterface ClockInterface\n{\n    public function now(): int;\n}",
        'app/SystemClock.php' => "namespace App;\n\nclass SystemClock implements ClockInterface\n{\n    public function now(): int\n    {\n        return time();\n    }\n}",
        'app/FrozenClock.php' => "namespace App;\n\nclass FrozenClock implements ClockInterface\n{\n    public function now(): int\n    {\n        return 0;\n    }\n}",
    ]))->toBeEmpty();
});

it('does not flag an interface used from several places', function (): void {
    expect(findingsAcross(singleUse(), [
        'app/ClockInterface.php' => "namespace App;\n\ninterface ClockInterface\n{\n    public function now(): int;\n}",
        'app/Clock.php' => "namespace App;\n\nclass Clock implements ClockInterface\n{\n    public function now(): int\n    {\n        return time();\n    }\n}",
        'app/A.php' => "namespace App;\n\nclass A\n{\n    public function __construct(private ClockInterface \$clock) {}\n}",
        'app/B.php' => "namespace App;\n\nclass B\n{\n    public function __construct(private ClockInterface \$clock) {}\n}",
    ]))->toBeEmpty();
});

it('does not flag a large interface', function (): void {
    expect(findingsAcross(singleUse(), [
        'app/BigInterface.php' => <<<'PHP'
        namespace App;

        interface BigInterface
        {
            public function a(): void;

            public function b(): void;

            public function c(): void;

            public function d(): void;
        }
        PHP,
        'app/Big.php' => <<<'PHP'
        namespace App;

        class Big implements BigInterface
        {
            public function a(): void {}

            public function b(): void {}

            public function c(): void {}

            public function d(): void {}
        }
        PHP,
    ]))->toBeEmpty();
});

it('does not flag an interface with no implementation at all', function (): void {
    // Probably a contract published for consumers to implement.
    expect(findingsAcross(singleUse(), [
        'app/ClockInterface.php' => "namespace App;\n\ninterface ClockInterface\n{\n    public function now(): int;\n}",
    ]))->toBeEmpty();
});

it('leaves deep layer stacks to SL301', function (): void {
    // Reporting both would say the same thing twice.
    $files = [
        'app/InvoiceRepositoryInterface.php' => "namespace App;\n\ninterface InvoiceRepositoryInterface\n{\n    public function find(int \$id): mixed;\n}",
        'app/InvoiceRepository.php' => "namespace App;\n\nclass InvoiceRepository implements InvoiceRepositoryInterface\n{\n    public function find(int \$id): mixed\n    {\n        return null;\n    }\n}",
        'app/InvoiceService.php' => "namespace App;\n\nclass InvoiceService\n{\n    public function __construct(private InvoiceRepositoryInterface \$r) {}\n}",
        'app/InvoiceManager.php' => "namespace App;\n\nclass InvoiceManager\n{\n    public function __construct(private InvoiceService \$s) {}\n}",
    ];

    expect(findingsAcross(singleUse(), $files))->toBeEmpty()
        ->and(findingsAcross(singleUse(['skip_layer_stacks' => false]), $files))->toHaveCount(1);
});

it('flags a trivial abstract class the same way', function (): void {
    $found = findingsAcross(singleUse(), [
        'app/BaseImporter.php' => "namespace App;\n\nabstract class BaseImporter\n{\n    abstract public function import(): void;\n}",
        'app/CsvImporter.php' => "namespace App;\n\nclass CsvImporter extends BaseImporter\n{\n    public function import(): void {}\n}",
    ]);

    expect($found)->toHaveCount(1)
        ->and($found[0]->message)->toStartWith('Abstract class BaseImporter');
});
