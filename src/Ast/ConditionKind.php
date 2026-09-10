<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

/**
 * The direction of a simple presence check.
 */
enum ConditionKind: string
{
    case IsNull = 'is-null';
    case NotNull = 'not-null';
    case Falsy = 'falsy';
    case Truthy = 'truthy';
    case IsEmpty = 'empty';
    case NotEmpty = 'not-empty';

    public function negated(): self
    {
        return match ($this) {
            self::IsNull => self::NotNull,
            self::NotNull => self::IsNull,
            self::Falsy => self::Truthy,
            self::Truthy => self::Falsy,
            self::IsEmpty => self::NotEmpty,
            self::NotEmpty => self::IsEmpty,
        };
    }

    /**
     * Checks in the same family accept and reject the same values closely
     * enough that writing both is redundant.
     *
     * @return 'absent'|'present'
     */
    public function family(): string
    {
        return match ($this) {
            self::IsNull, self::Falsy, self::IsEmpty => 'absent',
            self::NotNull, self::Truthy, self::NotEmpty => 'present',
        };
    }

    public function describe(string $subject): string
    {
        return match ($this) {
            self::IsNull => $subject.' is null',
            self::NotNull => $subject.' is not null',
            self::Falsy => $subject.' is falsy',
            self::Truthy => $subject.' is truthy',
            self::IsEmpty => $subject.' is empty',
            self::NotEmpty => $subject.' is not empty',
        };
    }
}
