<?php

declare(strict_types=1);

/**
 * Which Rector rules fix which Sloppy findings, and why the rest cannot be
 * fixed by anyone but a person.
 *
 * This is data rather than a class constant on purpose. Every name here is a
 * class in a package this one does not depend on, and a class-string sitting
 * in `src/` is a class some static analyser will eventually try to load --
 * which, for Rector, means booting Rector inside whatever process was only
 * reading the list.
 *
 * Every class named here is a real Rector rule, checked against the installed
 * package by the test suite rather than remembered: a generated configuration
 * that references a class that does not exist fails at the moment its user
 * runs it.
 *
 * @return array{fixes: array<string, list<string>>, unfixable: array<string, string>}
 */
return [
    'fixes' => [
        'SL103' => [
            'Rector\EarlyReturn\Rector\If_\ChangeNestedIfsToEarlyReturnRector',
            'Rector\EarlyReturn\Rector\Foreach_\ChangeNestedForeachIfsToEarlyContinueRector',
            'Rector\CodeQuality\Rector\If_\CombineIfRector',
        ],
        'SL105' => [
            'Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector',
        ],
        'SL106' => [
            'Rector\DeadCode\Rector\ClassMethod\RemoveUnusedConstructorParamRector',
            'Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector',
            'Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector',
        ],
        'SL107' => [
            'Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector',
            'Rector\DeadCode\Rector\TryCatch\RemoveDeadTryCatchRector',
        ],
        'SL108' => [
            'Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector',
            'Rector\EarlyReturn\Rector\If_\RemoveAlwaysElseRector',
            'Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector',
        ],
        'SL110' => [
            'Rector\CodeQuality\Rector\If_\SimplifyIfNotNullReturnRector',
            'Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector',
        ],
    ],

    // Why the rules above leave the rest alone. A reader who sees ten findings
    // and six fixes should be told what happened to the other four, in a
    // sentence, at the point they would have asked.
    'unfixable' => [
        'SL101' => 'splitting a long method is a design decision, not a rewrite',
        'SL102' => 'which responsibility leaves the class is a design decision',
        'SL104' => 'the shared code has to be named before it can be extracted',
        'SL109' => 'deleting a comment automatically risks deleting the one that mattered',
        'SL111' => 'only you know whether the drift between the copies was deliberate',
    ],
];
