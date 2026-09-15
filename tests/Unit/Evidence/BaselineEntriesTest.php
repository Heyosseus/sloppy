<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Evidence\BaselineEntries;

it('counts phpstan entries per source path', function (): void {
    $neon = <<<'NEON'
    parameters:
    	ignoreErrors:
    		-
    			message: "#^Method foo\(\) has no return type\.$#"
    			count: 2
    			path: app/Orders/Importer.php
    		-
    			message: "#^Cannot call method on mixed\.$#"
    			count: 1
    			path: app/Orders/Importer.php
    		-
    			message: "#^Undefined variable\.$#"
    			count: 1
    			path: app/Billing/Invoice.php
    NEON;

    // Counted by the `count:` value, not by the number of blocks: one entry
    // saying count 2 has silenced two errors.
    expect(BaselineEntries::fromNeon($neon))->toBe([
        'app/Billing/Invoice.php' => 1,
        'app/Orders/Importer.php' => 3,
    ]);
});

it('treats a missing count as one', function (): void {
    $neon = <<<'NEON'
    parameters:
    	ignoreErrors:
    		-
    			message: "#^Undefined variable\.$#"
    			path: app/Billing/Invoice.php
    NEON;

    expect(BaselineEntries::fromNeon($neon))->toBe(['app/Billing/Invoice.php' => 1]);
});

it('returns nothing for an empty or unparsable baseline', function (): void {
    expect(BaselineEntries::fromNeon(''))->toBe([])
        ->and(BaselineEntries::fromNeon('parameters:'))->toBe([]);
});

it('counts psalm entries per source path', function (): void {
    $xml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <files psalm-version="5.0.0">
      <file src="app/Orders/Importer.php">
        <MixedReturnStatement occurrences="2"/>
        <MixedAssignment occurrences="1"/>
      </file>
      <file src="app/Billing/Invoice.php">
        <UndefinedVariable occurrences="1"/>
      </file>
    </files>
    XML;

    expect(BaselineEntries::fromXml($xml))->toBe([
        'app/Billing/Invoice.php' => 1,
        'app/Orders/Importer.php' => 3,
    ]);
});

it('dispatches on the file extension', function (): void {
    expect(BaselineEntries::for('psalm-baseline.xml', '<files></files>'))->toBe([])
        ->and(BaselineEntries::for('phpstan-baseline.neon', 'parameters:'))->toBe([]);
});

it('normalises windows separators and leading dots', function (): void {
    $neon = "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tcount: 1\n\t\t\tpath: ./app\Orders\Importer.php\n";

    expect(BaselineEntries::fromNeon($neon))->toBe(['app/Orders/Importer.php' => 1]);
});
