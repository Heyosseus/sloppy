<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ast\ConditionKind;
use Heyosseus\Sloppy\Ast\ConditionShape;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\Parser;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;

function shapeOf(string $expression): ?ConditionShape
{
    $file = (new Parser)->parse('app/E.php', '<?php return '.$expression.';');
    $statement = $file->ast[0];

    $expr = $statement instanceof Return_ ? $statement->expr : ($statement instanceof Expression ? $statement->expr : null);

    return $expr instanceof Expr ? ConditionShape::of($expr) : null;
}

it('reduces the spellings of a null check to one shape', function (): void {
    expect(shapeOf('$user === null')?->kind)->toBe(ConditionKind::IsNull)
        ->and(shapeOf('null === $user')?->kind)->toBe(ConditionKind::IsNull)
        ->and(shapeOf('is_null($user)')?->kind)->toBe(ConditionKind::IsNull)
        ->and(shapeOf('$user !== null')?->kind)->toBe(ConditionKind::NotNull)
        ->and(shapeOf('! is_null($user)')?->kind)->toBe(ConditionKind::NotNull)
        ->and(shapeOf('isset($user)')?->kind)->toBe(ConditionKind::NotNull);
});

it('treats loose comparison against null as a falsiness test', function (): void {
    // `$x == null` also matches 0, '' and [], so it is not a null test.
    expect(shapeOf('$user == null')?->kind)->toBe(ConditionKind::Falsy)
        ->and(shapeOf('$user != null')?->kind)->toBe(ConditionKind::Truthy);
});

it('recognises truthiness, falsiness and emptiness', function (): void {
    expect(shapeOf('$user')?->kind)->toBe(ConditionKind::Truthy)
        ->and(shapeOf('! $user')?->kind)->toBe(ConditionKind::Falsy)
        ->and(shapeOf('empty($user)')?->kind)->toBe(ConditionKind::IsEmpty)
        ->and(shapeOf('! empty($user)')?->kind)->toBe(ConditionKind::NotEmpty);
});

it('groups checks that accept the same values into one family', function (): void {
    $absent = [shapeOf('$u === null'), shapeOf('! $u'), shapeOf('empty($u)')];

    foreach ($absent as $shape) {
        expect($shape?->kind->family())->toBe('absent');
    }

    expect(shapeOf('$u !== null')?->kind->family())->toBe('present')
        ->and(shapeOf('$u === null')?->equivalentTo(shapeOf('! $u')))->toBeTrue()
        ->and(shapeOf('$u === null')?->equivalentTo(shapeOf('$u !== null')))->toBeFalse();
});

it('does not treat different subjects as equivalent', function (): void {
    expect(shapeOf('$user === null')?->equivalentTo(shapeOf('$order === null')))->toBeFalse();
});

it('reads property, static property and array subjects', function (): void {
    expect(shapeOf('$this->user === null')?->subject)->toBe('$this->user')
        ->and(shapeOf('self::$user === null')?->subject)->toBe('self::$user')
        ->and(shapeOf('$data["user"] === null')?->subject)->toBe('$data["user"]');
});

it('refuses subjects with side effects', function (): void {
    // Calling a method twice is not the same as testing a variable twice.
    expect(shapeOf('$this->user() === null'))->toBeNull()
        ->and(shapeOf('count($rows) === null'))->toBeNull()
        ->and(shapeOf('$a === $b'))->toBeNull()
        ->and(shapeOf('$a > 1'))->toBeNull()
        ->and(shapeOf('isset($a, $b)'))->toBeNull()
        ->and(shapeOf('is_null($a, $b)'))->toBeNull();
});

it('describes itself in words a report can print', function (): void {
    expect(shapeOf('$user === null')?->describe())->toBe('$user is null')
        ->and(shapeOf('! $user')?->describe())->toBe('$user is falsy')
        ->and(shapeOf('empty($user)')?->describe())->toBe('$user is empty')
        ->and(shapeOf('! empty($user)')?->describe())->toBe('$user is not empty')
        ->and(shapeOf('$user')?->describe())->toBe('$user is truthy')
        ->and(shapeOf('$user !== null')?->describe())->toBe('$user is not null');
});

it('negates every kind', function (): void {
    foreach (ConditionKind::cases() as $kind) {
        expect($kind->negated()->negated())->toBe($kind)
            ->and($kind->negated()->family())->not->toBe($kind->family());
    }
});

it('ignores non-null comparisons', function (): void {
    $file = (new Parser)->parse('app/E.php', '<?php return $a === 5;');
    $statement = $file->ast[0];
    $expr = $statement instanceof Return_ ? $statement->expr : null;

    expect($expr)->not->toBeNull()
        ->and(NodeHelper::printAny($expr))->toBe('$a === 5')
        ->and(ConditionShape::of($expr))->toBeNull();
});
