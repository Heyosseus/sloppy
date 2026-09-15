<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Coverage\CloverReader;

function writeReport(string $name, string $contents): string
{
    $root = tempProject([]);
    file_put_contents($root.'/'.$name, $contents);

    return $root;
}

it('reads the covered ratio per file', function (): void {
    $root = writeReport('clover.xml', <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <coverage generated="1700000000">
      <project timestamp="1700000000">
        <file name="/abs/app/Importer.php">
          <metrics statements="10" coveredstatements="6"/>
        </file>
        <file name="/abs/app/Billing.php">
          <metrics statements="4" coveredstatements="4"/>
        </file>
      </project>
    </coverage>
    XML);

    $map = CloverReader::read($root.'/clover.xml', '/abs');

    expect($map->forFile('app/Importer.php'))->toBe(0.6)
        ->and($map->forFile('app/Billing.php'))->toBe(1.0)
        ->and($map->sourcePath())->toBe($root.'/clover.xml');

    removeTree($root);
});

it('treats a file with no statements as fully covered', function (): void {
    // An interface or a plain DTO has nothing to execute. Reporting it as 0%
    // would send every value object to the top of the reading order.
    $root = writeReport('clover.xml', '<?xml version="1.0"?><coverage><project>'
        .'<file name="/abs/app/Contract.php"><metrics statements="0" coveredstatements="0"/></file>'
        .'</project></coverage>');

    expect(CloverReader::read($root.'/clover.xml', '/abs')->forFile('app/Contract.php'))->toBe(1.0);

    removeTree($root);
});

it('returns an empty map for a missing or malformed file', function (): void {
    $root = writeReport('clover.xml', 'this is not xml at all <<<');

    expect(CloverReader::read($root.'/clover.xml', '/abs')->isEmpty())->toBeTrue()
        ->and(CloverReader::read($root.'/nope.xml', '/abs')->isEmpty())->toBeTrue();

    removeTree($root);
});

it('keeps a path that does not sit under the project root', function (): void {
    $root = writeReport('clover.xml', '<?xml version="1.0"?><coverage><project>'
        .'<file name="app/Relative.php"><metrics statements="2" coveredstatements="1"/></file>'
        .'</project></coverage>');

    expect(CloverReader::read($root.'/clover.xml', '/somewhere/else')->forFile('app/Relative.php'))->toBe(0.5);

    removeTree($root);
});

it('strips the project root from an absolute filename with windows separators', function (): void {
    $root = writeReport('clover.xml', '<?xml version="1.0"?><coverage><project>'
        .'<file name="C:\proj\app\Windows.php"><metrics statements="4" coveredstatements="3"/></file>'
        .'</project></coverage>');

    expect(CloverReader::read($root.'/clover.xml', 'C:/proj')->forFile('app/Windows.php'))->toBe(0.75);

    removeTree($root);
});
