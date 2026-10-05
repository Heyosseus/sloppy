<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Php\DefensiveProgrammingNoiseRule;

function defensive(): DefensiveProgrammingNoiseRule
{
    return new DefensiveProgrammingNoiseRule;
}

it('flags the same guard written twice in different words', function (): void {
    $found = findings(defensive(), <<<'PHP'
    class Loader
    {
        public function load(?User $user): ?string
        {
            if (! $user) {
                return null;
            }

            if ($user === null) {
                return null;
            }

            return $user->name;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL110')
        ->and($found[0]->metrics['subject'])->toBe('$user')
        ->and($found[0]->message)->toContain('already guarded');
});

it('sees an is_null after an empty check as already guarded', function (): void {
    $found = findings(defensive(), <<<'PHP'
    class Loader
    {
        public function load(?array $rows): ?array
        {
            if (empty($rows)) {
                return null;
            }

            if (is_null($rows)) {
                return null;
            }

            return $rows;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['first_check'])->toBe('empty')
        ->and($found[0]->metrics['second_check'])->toBe('is-null');
});

it('does not flag a falsiness guard after a null guard, which still catches 0, empty strings and arrays', function (): void {
    expect(findings(defensive(), <<<'PHP'
    class Loader
    {
        public function load(?array $rows, ?string $code): ?array
        {
            if (is_null($rows)) {
                return null;
            }

            if (empty($rows)) {
                return null;
            }

            if ($code === null) {
                return null;
            }

            if (! $code) {
                return null;
            }

            return $rows;
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag the same guard in two loops one after the other', function (): void {
    expect(findings(defensive(), <<<'PHP'
    class Loader
    {
        public function load(array $a, array $b): void
        {
            foreach ($a as $item) {
                if ($item === null) {
                    continue;
                }

                $this->keep($item);
            }

            foreach ($b as $item) {
                if ($item === null) {
                    continue;
                }

                $this->keep($item);
            }
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a guard repeated after the subject is rebound', function (string $rebinding): void {
    expect(findings(defensive(), <<<PHP
    class Loader
    {
        public function load(?User \$user, array \$rows): ?string
        {
            if (\$user === null) {
                return null;
            }

            {$rebinding}

            if (\$user === null) {
                return null;
            }

            return \$user->name;
        }
    }
    PHP))->toBeEmpty();
})->with([
    'list destructuring' => ['[$user, $other] = $rows;'],
    'list() destructuring' => ['list(, $user) = $rows;'],
    'keyed destructuring' => ["['user' => \$user] = \$rows;"],
    'assignment by reference' => ['$user = &$rows[0];'],
    'compound assignment' => ['$user ??= $this->fallback();'],
    'foreach value' => ['foreach ($rows as $user) { $this->touch($user); }'],
    'foreach key' => ['foreach ($rows as $user => $row) { $this->touch($row); }'],
]);

it('looks inside functions', function (): void {
    $found = findings(defensive(), <<<'PHP'
    function label(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        if ($name === null) {
            return null;
        }

        return $name;
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->fingerprint)->toBe('label:$name')
        ->and($found[0]->message)->toStartWith('label() already guarded');
});

it('does not flag guards with different outcomes', function (): void {
    expect(findings(defensive(), <<<'PHP'
    class Loader
    {
        public function load(?User $user): string
        {
            if (! $user) {
                throw new UserRequired();
            }

            if ($user === null) {
                return 'unreachable';
            }

            return $user->name;
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag guards on different subjects', function (): void {
    expect(findings(defensive(), <<<'PHP'
    class Loader
    {
        public function load(?User $user, ?Order $order): ?string
        {
            if (! $user) {
                return null;
            }

            if (! $order) {
                return null;
            }

            return $user->name;
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a guard repeated after the subject is reassigned', function (): void {
    // The second check is meaningful: $user changed in between.
    expect(findings(defensive(), <<<'PHP'
    class Loader
    {
        public function load(?User $user): ?string
        {
            if (! $user) {
                return null;
            }

            $user = $this->users->refresh($user);

            if ($user === null) {
                return null;
            }

            return $user->name;
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag opposite checks on the same subject', function (): void {
    expect(findings(defensive(), <<<'PHP'
    class Loader
    {
        public function load(?User $user): string
        {
            if ($user === null) {
                return 'anonymous';
            }

            if ($user !== null) {
                return $user->name;
            }

            return 'unreachable';
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag an if that does more than leave', function (): void {
    expect(findings(defensive(), <<<'PHP'
    class Loader
    {
        public function load(?User $user): ?string
        {
            if (! $user) {
                return null;
            }

            if ($user === null) {
                $this->logger->warning('lost the user');

                return null;
            }

            return $user->name;
        }
    }
    PHP))->toBeEmpty();
});

it('reports one finding per subject even with three repeats', function (): void {
    $found = findings(defensive(), <<<'PHP'
    class Loader
    {
        public function load(?User $user): ?string
        {
            if (! $user) {
                return null;
            }

            if ($user === null) {
                return null;
            }

            if (is_null($user)) {
                return null;
            }

            return $user->name;
        }
    }
    PHP);

    expect($found)->toHaveCount(1);
});
