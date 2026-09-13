<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\BlastRadiusEnricher;

it('resolves the subject to the class enclosing a finding deep inside a method, not only at the declaration line', function (): void {
    $index = indexOf([
        'app/Order.php' => <<<'PHP'
        <?php

        namespace App;

        class Order
        {
            public function place(): void
            {
                $a = 1;
                $b = 2;
                $c = 3;
                $d = 4;
                $e = 5;
            }
        }
        PHP,
    ]);

    // Line 10 is deep inside place()'s body, nowhere near the class
    // declaration on line 5.
    $enriched = (new BlastRadiusEnricher($index))->enrich([finding(file: 'app/Order.php', line: 10)]);

    expect($enriched[0]->metrics['subject'])->toBe('App\Order')
        ->and($enriched[0]->metrics)->toHaveKey('blast_radius');
});

it('picks the innermost class when one is declared inside another', function (): void {
    $index = indexOf([
        'app/Outer.php' => <<<'PHP'
        <?php

        namespace App;

        class Outer
        {
            public function factory(): object
            {
                class Inner
                {
                    public function work(): void
                    {
                        $noop = true;
                    }
                }

                return new Inner();
            }
        }
        PHP,
    ]);

    // Line 13 ($noop = true;) sits inside BOTH Outer (lines 5-19) and Inner
    // (lines 9-15). The smaller span -- Inner -- is the more specific answer.
    $enriched = (new BlastRadiusEnricher($index))->enrich([finding(file: 'app/Outer.php', line: 13)]);

    expect($enriched[0]->metrics['subject'])->toBe('App\Inner');
});

it('sets blast_radius to the exact usage count for a class two other files reference', function (): void {
    $index = indexOf([
        'app/Order.php' => <<<'PHP'
        <?php

        namespace App;

        class Order
        {
            public function place(): void
            {
            }
        }
        PHP,
        'app/Checkout.php' => <<<'PHP'
        <?php

        namespace App;

        class Checkout
        {
            public function __construct(private Order $order) {}
        }
        PHP,
        'app/Refund.php' => <<<'PHP'
        <?php

        namespace App;

        class Refund
        {
            public function __construct(private Order $order) {}
        }
        PHP,
    ]);

    expect($index->usageCount('App\Order'))->toBe(2);

    $enriched = (new BlastRadiusEnricher($index))->enrich([finding(file: 'app/Order.php', line: 7)]);

    expect($enriched[0]->metrics['blast_radius'])->toBe(2);
});

it('omits blast_radius entirely, never as zero, when nothing resolves a subject', function (): void {
    $index = indexOf([
        'app/functions.php' => <<<'PHP'
        <?php

        namespace App;

        function helper(): int
        {
            return 1;
        }
        PHP,
    ]);

    $enriched = (new BlastRadiusEnricher($index))->enrich([finding(file: 'app/functions.php', line: 6)]);

    expect($enriched[0]->metrics)->not->toHaveKey('blast_radius')
        ->and($enriched[0]->metrics)->not->toHaveKey('subject');
});

it('falls back to the enclosing class when blast_subject names something the project does not know', function (): void {
    $index = indexOf([
        'app/Order.php' => <<<'PHP'
        <?php

        namespace App;

        class Order
        {
            public function place(): void
            {
            }
        }
        PHP,
        'app/Checkout.php' => <<<'PHP'
        <?php

        namespace App;

        class Checkout
        {
            public function __construct(private Order $order) {}
        }
        PHP,
        'app/Refund.php' => <<<'PHP'
        <?php

        namespace App;

        class Refund
        {
            public function __construct(private Order $order) {}
        }
        PHP,
    ]);

    $enriched = (new BlastRadiusEnricher($index))->enrich([
        finding(file: 'app/Order.php', line: 7, metrics: ['blast_subject' => 'App\NoSuchClass']),
    ]);

    // The override is unknown, so it falls back to the enclosing class (Order,
    // referenced twice) rather than reporting nothing at all.
    expect($enriched[0]->metrics['subject'])->toBe('App\Order')
        ->and($enriched[0]->metrics['blast_radius'])->toBe(2);
});

it('honours a blast_subject the project does know, overriding the enclosing class', function (): void {
    $index = indexOf([
        'app/Order.php' => <<<'PHP'
        <?php

        namespace App;

        class Order
        {
            public function place(): void
            {
            }
        }
        PHP,
        'app/Checkout.php' => <<<'PHP'
        <?php

        namespace App;

        class Checkout
        {
            public function __construct(private Order $order) {}
        }
        PHP,
        'app/Refund.php' => <<<'PHP'
        <?php

        namespace App;

        class Refund
        {
            public function __construct(private Order $order) {}
        }
        PHP,
    ]);

    // The finding lives inside Checkout, but declares App\Order -- which the
    // project knows -- as the more relevant subject.
    $enriched = (new BlastRadiusEnricher($index))->enrich([
        finding(file: 'app/Checkout.php', line: 7, metrics: ['blast_subject' => 'App\Order']),
    ]);

    expect($enriched[0]->metrics['subject'])->toBe('App\Order')
        ->and($enriched[0]->metrics['blast_radius'])->toBe(2);
});

it('regression: a rule-supplied subject metric with an unrelated short name does not suppress reach', function (): void {
    // SL204 and SL110 both already put an unrelated short name in a `subject`
    // metric before this feature existed. Reading that key as a class to
    // resolve silently suppressed reach for every finding they produce -- the
    // enricher must read `blast_subject` instead and leave `subject` alone.
    $index = indexOf([
        'app/Order.php' => <<<'PHP'
        <?php

        namespace App;

        class Order
        {
            public function place(): void
            {
            }
        }
        PHP,
        'app/Checkout.php' => <<<'PHP'
        <?php

        namespace App;

        class Checkout
        {
            public function __construct(private Order $order) {}
        }
        PHP,
        'app/Refund.php' => <<<'PHP'
        <?php

        namespace App;

        class Refund
        {
            public function __construct(private Order $order) {}
        }
        PHP,
    ]);

    $enriched = (new BlastRadiusEnricher($index))->enrich([
        finding(file: 'app/Order.php', line: 7, metrics: ['subject' => 'SomeModel']),
    ]);

    expect($enriched[0]->metrics)->toHaveKey('blast_radius')
        ->and($enriched[0]->metrics['blast_radius'])->toBe(2);
});

it('leaves findings whose subject cannot be resolved otherwise unchanged', function (): void {
    $index = indexOf([
        'app/functions.php' => <<<'PHP'
        <?php

        function helper(): int
        {
            return 1;
        }
        PHP,
    ]);

    $original = finding(file: 'app/functions.php', line: 4, metrics: ['lines' => 5]);
    $enriched = (new BlastRadiusEnricher($index))->enrich([$original]);

    expect($enriched[0])->toBe($original);
});
