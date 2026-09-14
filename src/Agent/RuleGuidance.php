<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

use Heyosseus\Sloppy\Contracts\Rule;

/**
 * What to do instead, per rule, in one sentence an agent can act on.
 *
 * A rule's description says what was detected and its explanation says why
 * that costs something. Neither tells a model writing the next file what to
 * write, and "avoid god methods" is advice nobody has ever successfully
 * followed. These are the instructions: concrete, in the second person, and
 * short enough to survive being pasted into a context window alongside
 * twenty-three others.
 *
 * A rule with no entry falls back to its own description, so a project's
 * custom rules still generate a usable line.
 */
final readonly class RuleGuidance
{
    /**
     * @var array<string, string>
     */
    private const array ADVICE = [
        'SL101' => 'Give a method one job. Extract each block you would have introduced with a comment into a named private method, or into its own action class.',
        'SL102' => 'Split the class by responsibility. If its honest description needs the word "and", it is two classes.',
        'SL103' => 'Return early. Put the guard clauses at the top and leave the work at one level of indentation.',
        'SL104' => 'Name the shared behaviour and call it from both places. If you cannot name it, the duplication is telling you the two cases are not the same case.',
        'SL105' => 'Delete private code nothing calls. Version control remembers it for you.',
        'SL106' => 'Inject only what the class uses. Every unused dependency is a line of setup in every test that constructs it.',
        'SL107' => 'Handle the failure or let it travel. If you catch, log the exception as the previous one and rethrow something meaningful -- never return null in place of an answer.',
        'SL108' => 'Drop conditions that cannot be false. A null check on a non-nullable value teaches the next reader that it can be null.',
        'SL109' => 'Write comments that say why, not what. If a comment restates the line below it, rename the thing and delete the comment.',
        'SL110' => 'Validate at the boundary once and trust your own types afterwards. Re-checking a typed argument in every method downstream is noise that hides the check that matters.',
        'SL111' => 'Copies drift. When two blocks started identical and one has changed, either re-unify them or make the difference explicit -- a divergence nobody chose is a bug waiting for its turn.',
        'SL201' => 'Keep controllers to translating HTTP into one call and back. Put the decision in an action, a service or the model.',
        'SL202' => 'Validate in a FormRequest, or in a single validate() call. Hand-rolled if-blocks drift away from the rules the API documents.',
        'SL203' => 'Eager-load what the loop will touch: with() the relations before you iterate, not inside the iteration.',
        'SL204' => 'Hoist the query out of the loop. One whereIn beats N wheres, and the database will thank you at the size you have not reached yet.',
        'SL205' => 'Filter in the database. get()->filter() loads the table into memory to throw most of it away.',
        'SL206' => 'Group the dependencies into a collaborator with a name. A controller with six of them is coordinating six things.',
        'SL207' => 'Group the dependencies into a collaborator with a name. A service with six of them is doing six jobs badly.',
        'SL208' => 'Put the HTTP call behind a small client class, so the timeout, the retry and the fake used in tests live in one place.',
        'SL209' => 'Keep models about data and relationships. Notifications, PDFs and payment calls belong in classes named after those things.',
        'SL210' => 'Ask for what you need: a where, a select, or pagination. Model::all() loads the whole table before you filter it.',
        'SL301' => 'Add a layer when a second implementation or a real test seam needs it -- not in advance. Interfaces are cheap to add later and expensive to read past.',
        'SL302' => 'A class that only forwards to another is a rename with extra steps. Call the other one.',
        'SL303' => 'An interface with one implementation and one caller is indirection with nothing on the other side. Inline it until a second implementation shows up.',
    ];

    public static function for(Rule $rule): string
    {
        return self::ADVICE[mb_strtoupper($rule->id())] ?? $rule->description();
    }

    /**
     * Whether the shipped advice covers a rule, so the test suite can insist
     * that every shipped rule has some.
     */
    public static function has(string $ruleId): bool
    {
        return isset(self::ADVICE[mb_strtoupper($ruleId)]);
    }
}
