<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Php\ExcessiveNestingRule;
use Heyosseus\Sloppy\Tests\Support\RuleTester;

/**
 * @param  array<string, mixed>  $options
 */
function nesting(array $options = []): ExcessiveNestingRule
{
    return new ExcessiveNestingRule($options);
}

it('flags control flow nested past the limit', function (): void {
    $found = findings(nesting(), <<<'PHP'
    class Importer
    {
        public function run(array $rows): void
        {
            if ($rows !== []) {
                foreach ($rows as $row) {
                    if ($row['active']) {
                        try {
                            foreach ($row['lines'] as $line) {
                                $this->save($line);
                            }
                        } catch (Throwable $e) {
                            $this->logger->error('failed', ['e' => $e]);
                        }
                    }
                }
            }
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL103')
        ->and($found[0]->metrics['depth'])->toBe(5)
        ->and($found[0]->fingerprint)->toBe('Importer::run');
});

it('points at the deepest statement, not the top of the method', function (): void {
    $found = findings(nesting(['max_depth' => 2]), <<<'PHP'
    class Importer
    {
        public function run(array $rows): void
        {
            foreach ($rows as $row) {
                if ($row['active']) {
                    foreach ($row['lines'] as $line) {
                        $this->save($line);
                    }
                }
            }
        }
    }
    PHP);

    // The inner foreach is on line 8 of the generated source; the method
    // starts well above it.
    expect($found)->toHaveCount(1)
        ->and($found[0]->location->line)->toBeGreaterThan(6);
});

it('accepts flow nested up to the limit', function (): void {
    expect(findings(nesting(), <<<'PHP'
    class Importer
    {
        public function run(array $rows): void
        {
            foreach ($rows as $row) {
                if ($row['active']) {
                    foreach ($row['lines'] as $line) {
                        $this->save($line);
                    }
                }
            }
        }
    }
    PHP))->toBeEmpty();
});

it('does not count closures as nesting', function (): void {
    // Idiomatic Laravel: a transaction closure wrapping a loop and a guard.
    // Counting the closure would push this over the limit for no good reason.
    expect(findings(nesting(), <<<'PHP'
    class Importer
    {
        public function run(array $rows): void
        {
            DB::transaction(function () use ($rows): void {
                foreach ($rows as $row) {
                    if ($row['active']) {
                        collect($row['lines'])->each(function (array $line): void {
                            if ($line['qty'] > 0) {
                                $this->save($line);
                            }
                        });
                    }
                }
            });
        }
    }
    PHP))->toBeEmpty();
});

it('raises confidence with depth', function (): void {
    $shallow = findings(nesting(['max_depth' => 1]), <<<'PHP'
    class A
    {
        public function go(array $rows): void
        {
            foreach ($rows as $row) {
                if ($row) {
                    $this->save($row);
                }
            }
        }
    }
    PHP);

    $deep = findings(nesting(['max_depth' => 1]), <<<'PHP'
    class A
    {
        public function go(array $rows): void
        {
            foreach ($rows as $row) {
                if ($row) {
                    foreach ($row as $line) {
                        if ($line) {
                            while ($line) {
                                $this->save($line);
                            }
                        }
                    }
                }
            }
        }
    }
    PHP);

    expect($deep[0]->confidence)->toBeGreaterThan($shallow[0]->confidence);
});

it('does not count else if as a level deeper than elseif', function (): void {
    expect(findings(nesting(['max_depth' => 2]), <<<'PHP'
    class Router
    {
        public function route(array $rows): void
        {
            foreach ($rows as $row) {
                if ($row === 'a') {
                    $this->a();
                } else if ($row === 'b') {
                    $this->b();
                } else if ($row === 'c') {
                    $this->c();
                } else if ($row === 'd') {
                    $this->d();
                }
            }
        }
    }
    PHP))->toBeEmpty();
});

it('measures functions, property hooks and top-level code too', function (): void {
    $deep = 'if ($a) { foreach ($b as $c) { while ($c) { if ($d) { try { $e = 1; } catch (\Throwable $x) { throw $x; } } } } }';

    $found = findings(nesting(), <<<PHP
    function helper(\$a, \$b, \$d): void
    {
        {$deep}
    }

    class Settings
    {
        public int \$level {
            set (int \$a) {
                \$b = []; \$d = true;
                {$deep}
                \$this->level = \$a;
            }
        }
    }

    Route::get('/', function () use (\$a, \$b, \$d) {
        {$deep}
    });
    PHP);

    expect(RuleTester::fingerprints($found))->toBe(['helper', 'Settings::$level::set', '{main}']);
});

it('does not count the body of an anonymous class against the method that creates it', function (): void {
    $found = findings(nesting(['max_depth' => 3]), <<<'PHP'
    class Factory
    {
        public function make(array $rows): object
        {
            if ($rows !== []) {
                return new class {
                    public function run(array $rows): void
                    {
                        foreach ($rows as $row) {
                            if ($row) {
                                while ($row) {
                                    if ($row > 1) {
                                        $row--;
                                    }
                                }
                            }
                        }
                    }
                };
            }

            return new \stdClass;
        }
    }
    PHP);

    expect(RuleTester::fingerprints($found))->toBe(['anonymous class::run']);
});
