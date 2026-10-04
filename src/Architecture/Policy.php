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
 *         'advice' => 'Controllers hand the work to one action.',
 *     ],
 *
 * `may_not_depend_on` forbids what it names. `may_depend_on` is stricter: a
 * dependency on any class that plays a role must be on a role it lists (or
 * on the role itself). Classes no role covers -- the framework, vendor code,
 * unclassified helpers -- are never judged by the allow list; forbid them by
 * name with a glob.
 */
final readonly class Policy
{
    /**
     * @param  list<DependencyTarget>|null  $mayDependOn  Null when the policy has no allow list.
     * @param  list<DependencyTarget>  $mayNotDependOn
     * @param  list<Capability>  $mayNot
     */
    public function __construct(
        public string $role,
        public string $origin,
        public ?array $mayDependOn = null,
        public array $mayNotDependOn = [],
        public array $mayNot = [],
        public ?string $advice = null,
    ) {}

    public function constrainsDependencies(): bool
    {
        return $this->mayDependOn !== null || $this->mayNotDependOn !== [];
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

        return implode('; ', $parts);
    }

    /**
     * The role in a sentence: "classes in the controller role".
     */
    public function plural(): string
    {
        return sprintf('classes in the %s role', $this->role);
    }
}
