<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        // Fixtures are deliberately bad code; tidying them would defeat them.
        __DIR__.'/tests/Fixtures',

        // Stubs mirror another package's signatures on purpose; "improving"
        // them would make them stop being stubs of anything.
        __DIR__.'/tests/Stubs',

        // Rule tests name classes that do not exist: `App\Models\Order` is a
        // string inside an analysed snippet, not a class to reference.
        StringClassNameToClassConstantRector::class => [
            __DIR__.'/tests',
        ],
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    );
