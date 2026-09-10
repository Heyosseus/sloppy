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

it('sees is_null and empty as the same family', function (): void {
    $found = findings(defensive(), <<<'PHP'
    class Loader
    {
        public function load(?array $rows): ?array
        {
            if (is_null($rows)) {
                return null;
            }

            if (empty($rows)) {
                return null;
            }

            return $rows;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['first_check'])->toBe('is-null')
        ->and($found[0]->metrics['second_check'])->toBe('empty');
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
