<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Integrations\Tooling;

/**
 * Which Rector rules fix which Sloppy findings.
 *
 * Sloppy reports patterns; Rector rewrites code. Where the two overlap, the
 * honest thing is to hand the fix over rather than describe it -- and where
 * they do not, to say so instead of pointing at a rule that will not touch the
 * problem. Most of Sloppy's rules are in the second group on purpose: no
 * automated rewrite can split a god method into the three methods it wanted to
 * be, and one that tried would produce three worse ones.
 *
 * The table itself lives in `resources/rector-rules.php`. A class-string
 * belonging to another package is a class some static analyser will eventually
 * resolve, and resolving a Rector rule loads Rector into a process that only
 * wanted to read a list of names.
 */
final class RectorRules
{
    /**
     * @var array{fixes: array<string, list<string>>, unfixable: array<string, string>}|null
     */
    private static ?array $table = null;

    private function __construct() {}

    /**
     * @return list<string>
     */
    public static function for(string $ruleId): array
    {
        return self::table()['fixes'][mb_strtoupper($ruleId)] ?? [];
    }

    public static function fixes(string $ruleId): bool
    {
        return self::for($ruleId) !== [];
    }

    /**
     * Why a rule has no automated fix, when there is something to say.
     */
    public static function reason(string $ruleId): ?string
    {
        return self::table()['unfixable'][mb_strtoupper($ruleId)] ?? null;
    }

    /**
     * Every Rector rule this mapping can produce, deduplicated and sorted.
     *
     * @param  list<string>  $ruleIds
     * @return list<string>
     */
    public static function forAll(array $ruleIds): array
    {
        $rules = [];

        foreach ($ruleIds as $id) {
            foreach (self::for($id) as $class) {
                $rules[$class] = true;
            }
        }

        $names = array_keys($rules);
        sort($names);

        return $names;
    }

    /**
     * @return array{fixes: array<string, list<string>>, unfixable: array<string, string>}
     */
    private static function table(): array
    {
        if (self::$table === null) {
            /** @var array{fixes: array<string, list<string>>, unfixable: array<string, string>} $table */
            $table = require dirname(__DIR__, 3).'/resources/rector-rules.php';

            self::$table = $table;
        }

        return self::$table;
    }
}
