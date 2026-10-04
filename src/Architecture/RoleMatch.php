<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * Which role a class plays, and which others it would have played had they
 * been tried first. The losers are what make a surprising answer debuggable.
 */
final readonly class RoleMatch
{
    /**
     * @param  list<Role>  $alsoMatched  In profile order.
     */
    public function __construct(
        public ?Role $role,
        public array $alsoMatched = [],
    ) {}

    public function name(): ?string
    {
        return $this->role?->name;
    }
}
