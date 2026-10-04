<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * What one role may depend on and what it may do.
 *
 *     'controller' => [
 *         'may_depend_on' => ['action', 'form-request'],
 *         'may_not_depend_on' => ['Illuminate\Support\Facades\DB'],
 *         'may_not' => ['db', 'http'],
 *         'public_methods' => ['__invoke'],
 *         'final' => true,
 *         'advice' => 'Controllers hand the work to one action.',
 *     ],
 *
 * `may_not_depend_on` forbids what it names. `may_depend_on` is stricter: a
 * dependency on any class that plays a role must be on a role it lists (or
 * on the role itself). Classes no role covers -- the framework, vendor code,
 * unclassified helpers -- are never judged by the allow list; forbid them by
 * name with a glob.
 *
 * `public_methods` and `final` describe the role's shape (SL308): the public
 * methods a class in the role may declare, by name or glob -- the constructor
 * and the other magic methods always may -- and whether it must be final.
 */
final readonly class Policy
{
    /**
     * @param  list<DependencyTarget>|null  $mayDependOn  Null when the policy has no allow list.
     * @param  list<DependencyTarget>  $mayNotDependOn
     * @param  list<Capability>  $mayNot
     * @param  list<Glob>|null  $publicMethods  Null when the policy does not limit public methods.
     */
    public function __construct(
        public string $role,
        public string $origin,
        public ?array $mayDependOn = null,
        public array $mayNotDependOn = [],
        public array $mayNot = [],
        public ?string $advice = null,
        public ?array $publicMethods = null,
        public bool $final = false,
    ) {}

    public function constrainsDependencies(): bool
    {
        return $this->mayDependOn !== null || $this->mayNotDependOn !== [];
    }

    public function constrainsShape(): bool
    {
        return $this->publicMethods !== null || $this->final;
    }

    /**
     * Whether a class in this role may declare a public method of this name.
     * Magic methods -- the constructor, `__invoke`, `__toString` -- always may.
     */
    public function allowsPublicMethod(string $name): bool
    {
        if ($this->publicMethods === null || str_starts_with($name, '__')) {
            return true;
        }

        foreach ($this->publicMethods as $allowed) {
            if ($allowed->matches($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The public methods the role allows, for a message: `handle, as*`.
     */
    public function publicMethodList(): string
    {
        $methods = $this->publicMethods ?? [];

        return $methods === []
            ? 'none besides magic methods'
            : implode(', ', array_map(static fn (Glob $glob): string => $glob->pattern, $methods));
    }

    /**
     * Why depending on a class breaks this policy, or null when it does not.
     *
     * @param  string|null  $targetRole  The role the depended-on class plays, if any.
     */
    public function dependencyViolation(string $target, ?string $targetRole): ?string
    {
        foreach ($this->mayNotDependOn as $forbidden) {
            if ($forbidden->matches($target, $targetRole)) {
                return sprintf('%s may not depend on %s', $this->plural(), $forbidden->describe());
            }
        }

        if ($this->mayDependOn === null || $targetRole === null || $targetRole === $this->role) {
            return null;
        }

        foreach ($this->mayDependOn as $allowed) {
            if ($allowed->matches($target, $targetRole)) {
                return null;
            }
        }

        return sprintf(
            '%s may depend only on %s',
            $this->plural(),
            implode(', ', array_map(static fn (DependencyTarget $allowed): string => $allowed->describe(), $this->mayDependOn)),
        );
    }

    public function forbids(Capability $capability): bool
    {
        return in_array($capability, $this->mayNot, true);
    }

    /**
     * The policy in a line: "may not: db.read, db.write; may not depend on: repository".
     */
    public function describe(): string
    {
        $targets = static fn (array $list): string => implode(', ', array_map(static fn (DependencyTarget $target): string => $target->describe(), $list));
        $parts = [];

        if ($this->mayDependOn !== null) {
            $parts[] = 'may depend only on: '.$targets($this->mayDependOn);
        }

        if ($this->mayNotDependOn !== []) {
            $parts[] = 'may not depend on: '.$targets($this->mayNotDependOn);
        }

        if ($this->mayNot !== []) {
            $parts[] = 'may not: '.implode(', ', array_map(static fn (Capability $capability): string => $capability->value, $this->mayNot));
        }

        if ($this->publicMethods !== null) {
            $parts[] = 'public methods: '.$this->publicMethodList();
        }

        if ($this->final) {
            $parts[] = 'final';
        }

        return implode('; ', $parts);
    }

    /**
     * The policy as instructions for whoever writes the next class in the
     * role, its advice first.
     *
     * @return list<string>
     */
    public function instructions(): array
    {
        $targets = static fn (array $list): string => implode(', ', array_map(static fn (DependencyTarget $target): string => $target->describe(), $list));
        $nouns = array_map(static fn (Capability $capability): string => $capability->noun(), $this->mayNot);
        $last = array_pop($nouns);

        return array_values(array_filter([
            $this->advice,
            match ($this->mayDependOn) {
                null => null,
                [] => 'Depend on no other role.',
                default => sprintf('Depend on no other role than: %s.', $targets($this->mayDependOn)),
            },
            $this->mayNotDependOn === [] ? null : sprintf('Never depend on: %s.', $targets($this->mayNotDependOn)),
            $last === null ? null : sprintf('No %s.', $nouns === [] ? $last : implode(', ', $nouns).' or '.$last),
            $this->publicMethods === null ? null : sprintf('Public methods: %s.', $this->publicMethodList()),
            $this->final ? 'Declare the class final.' : null,
        ]));
    }

    /**
     * The role in a sentence: "classes in the controller role".
     */
    public function plural(): string
    {
        return sprintf('classes in the %s role', $this->role);
    }
}
