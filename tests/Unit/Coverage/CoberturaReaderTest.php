<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Coverage\CoberturaReader;

it('reads the line rate per class', function (): void {
    $root = writeReport('cobertura.xml', <<<'XML'
    <?xml version="1.0"?>
    <coverage>
      <packages><package><classes>
        <class name="Importer" filename="app/Importer.php" line-rate="0.75"/>
        <class name="Billing" filename="app/Billing.php" line-rate="1"/>
      </classes></package></packages>
    </coverage>
    XML);

    $map = CoberturaReader::read($root.'/cobertura.xml', $root);

    expect($map->forFile('app/Importer.php'))->toBe(0.75)
        ->and($map->forFile('app/Billing.php'))->toBe(1.0);

    removeTree($root);
});

it('clamps a rate outside zero and one', function (): void {
    $root = writeReport('cobertura.xml', '<?xml version="1.0"?><coverage><packages><package><classes>'
        .'<class name="A" filename="app/A.php" line-rate="1.4"/>'
        .'<class name="B" filename="app/B.php" line-rate="-0.2"/>'
        .'</classes></package></packages></coverage>');

    $map = CoberturaReader::read($root.'/cobertura.xml', $root);

    expect($map->forFile('app/A.php'))->toBe(1.0)
        ->and($map->forFile('app/B.php'))->toBe(0.0);

    removeTree($root);
});

it('skips a class with no filename', function (): void {
    $root = writeReport('cobertura.xml', '<?xml version="1.0"?><coverage><packages><package><classes>'
        .'<class name="Anonymous" line-rate="0.5"/>'
        .'</classes></package></packages></coverage>');

    expect(CoberturaReader::read($root.'/cobertura.xml', $root)->isEmpty())->toBeTrue();

    removeTree($root);
});

it('returns an empty map for a missing file', function (): void {
    expect(CoberturaReader::read('/does/not/exist.xml', '/abs')->isEmpty())->toBeTrue();
});

it('strips the project root from an absolute filename', function (): void {
    // Some cobertura writers emit absolute paths and some emit relative ones,
    // and a finding's location is always project-relative.
    $root = tempProject([]);
    file_put_contents($root.'/cobertura.xml', sprintf(
        '<?xml version="1.0"?><coverage><packages><package><classes>'
        .'<class name="A" filename="%s/app/Absolute.php" line-rate="0.4"/>'
        .'</classes></package></packages></coverage>',
        $root,
    ));

    expect(CoberturaReader::read($root.'/cobertura.xml', $root)->forFile('app/Absolute.php'))->toBe(0.4);

    removeTree($root);
});

it('returns an empty map for malformed xml', function (): void {
    $root = writeReport('cobertura.xml', 'not xml at all <<<');

    expect(CoberturaReader::read($root.'/cobertura.xml', $root)->isEmpty())->toBeTrue();

    removeTree($root);
});
