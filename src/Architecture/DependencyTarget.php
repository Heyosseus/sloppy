<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * What a dependency policy names: a role, or a glob on class names for code
 * no role covers -- `Illuminate\*`, a vendor SDK.
 */
final readonly class DependencyTarget
{
    private function __construct(
        public ?string $role,
        public ?Glob $glob,
    ) {}

    public static function role(string $role): self
    {
        return new self($role, null);
    }

    public static function glob(string $pattern): self
    {
        return new self(null, new Glob($pattern));
    }

    public function matches(string $fqn, ?string $role): bool
    {
        return $this->role !== null ? $this->role === $role : (bool) $this->glob?->matches($fqn);
    }

    public function describe(): string
    {
        return $this->role ?? (string) $this->glob?->pattern;
    }
}
