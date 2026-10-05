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

    /**
     * Every kind that is certain to hold once this one does, itself included.
     *
     * Truthy and not-empty are the same test and both rule out null; null
     * rules in falsy and empty; falsy and empty are the same test. Not-null
     * says nothing about truthiness, which is why it implies only itself.
     *
     * @return list<self>
     */
    public function implied(): array
    {
        return match ($this) {
            self::Truthy, self::NotEmpty => [self::Truthy, self::NotEmpty, self::NotNull],
            self::NotNull => [self::NotNull],
            self::IsNull => [self::IsNull, self::Falsy, self::IsEmpty],
            self::Falsy, self::IsEmpty => [self::Falsy, self::IsEmpty],
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
