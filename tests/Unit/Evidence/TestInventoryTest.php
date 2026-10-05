<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Evidence\TestBody;
use Heyosseus\Sloppy\Evidence\TestInventory;

/**
 * @return array<string, TestBody>
 */
function inventoryOf(string $code): array
{
    return TestInventory::of(parsedFile($code));
}

it('finds PHPUnit tests by prefix, attribute and docblock, and nothing else', function (): void {
    $tests = inventoryOf(<<<'PHP_WRAP'
    use PHPUnit\Framework\Attributes\Test;
    
    abstract class OrderTest extends TestCase
    {
        public function test_it_totals(): void { $this->assertSame(3, 1 + 2); }
    
        #[Test]
        public function it_refunds(): void { self::assertTrue($this->refunded); }
    
        /** @test */
        public function it_ships(): void { $this->assertNotNull($this->order); }
    
        public function helperThatIsNotATest(): void {}
    
        private function testPrivate(): void { $this->assertTrue(false); }
    
        abstract public function testAbstract(): void;
    }
    PHP_WRAP);

    expect(array_keys($tests))->toBe(['OrderTest::test_it_totals', 'OrderTest::it_refunds', 'OrderTest::it_ships'])
        ->and($tests['OrderTest::test_it_totals'])->toBeInstanceOf(TestBody::class)
        ->and($tests['OrderTest::test_it_totals']->assertions)->toBe(1)
        ->and($tests['OrderTest::it_refunds']->assertions)->toBe(1)
        ->and($tests['OrderTest::it_ships']->assertions)->toBe(1);
});

it('names Pest tests by description, prefixing it() and describe() blocks', function (): void {
    $tests = inventoryOf(<<<'PHP'
        it('totals an order', function () { expect(1 + 2)->toBe(3); });

        test('refunds an order', fn () => expect($order)->toBeNull());

        describe('shipping', function () {
            describe('abroad', function () {
                it('adds duty', function () { expect($duty)->toBeGreaterThan(0)->toBeInt(); });
            });
        });

        it($dynamicName, function () { expect(1)->toBe(1); });
        foo('not a test', function () {});
        $object->it('not a function call either', function () {});
        PHP);

    expect(array_keys($tests))->toBe(['it totals an order', 'refunds an order', 'shipping > abroad > it adds duty'])
        ->and($tests['it totals an order']->assertions)->toBe(1)
        ->and($tests['refunds an order']->assertions)->toBe(1)
        ->and($tests['shipping > abroad > it adds duty']->assertions)->toBe(2);
});

it('counts every kind of assertion call', function (): void {
    $tests = inventoryOf(<<<'PHP'
        final class ApiTest extends TestCase
        {
            public function test_everything(): void
            {
                $this->assertSame(1, $a);
                self::assertCount(2, $b);
                $response->assertOk();
                Http::assertSent(fn () => true);
                $this->expectException(RuntimeException::class);
                $this->expectExceptionMessage('boom');
                $mock->shouldReceive('charge');
                $spy->shouldHaveReceived('refund');
                $spy->shouldNotHaveReceived('void');
                $this->doSomethingElse();
                strlen('not an assertion');
                $callable();
            }
        }
        PHP);

    expect($tests['ApiTest::test_everything']->assertions)->toBe(9);
});

it('does not count a to*() method that is not on an expect() chain', function (): void {
    $tests = inventoryOf(<<<'PHP'
        it('converts', function () {
            $money->toArray();
            $date->toDateString();
            other($money)->toBe(1);
            expect($money)->toBeInstanceOf(Money::class);
        });
        PHP);

    expect($tests['it converts']->assertions)->toBe(1);
});

it('credits a local helper with the assertions it makes, in classes and in Pest files', function (): void {
    $class = inventoryOf(<<<'PHP'
        final class InvoiceTest extends TestCase
        {
            public function test_paid(): void
            {
                $this->assertInvoiceIsPaid($invoice);
                self::assertInvoiceIsPaid($other);
                static::assertInvoiceIsPaid($third);
            }

            private function assertInvoiceIsPaid(Invoice $invoice): void
            {
                $this->assertTrue($invoice->paid);
                $this->assertNotNull($invoice->paidAt);
                $this->assertSame('paid', $invoice->status);
            }
        }
        PHP);

    $pest = inventoryOf(<<<'PHP'
        function assertShipped($order): void
        {
            expect($order->shipped)->toBeTrue();
            expect($order->trackingNumber)->toBeString();
        }

        it('ships', function () { assertShipped($order); });
        PHP);

    expect($class['InvoiceTest::test_paid']->assertions)->toBe(9)
        ->and($pest['it ships']->assertions)->toBe(2);
});

it('counts a helper on another object by its name, not as the local helper', function (): void {
    $tests = inventoryOf(<<<'PHP'
        final class InvoiceTest extends TestCase
        {
            public function test_paid(): void
            {
                $other->assertInvoiceIsPaid($invoice);
                Other::assertInvoiceIsPaid($invoice);
            }

            private function assertInvoiceIsPaid(Invoice $invoice): void
            {
                $this->assertTrue($invoice->paid);
                $this->assertTrue($invoice->sent);
            }
        }
        PHP);

    // Someone else's assert*() is one assertion each, not this class's helper.
    expect($tests['InvoiceTest::test_paid']->assertions)->toBe(2);
});

it('marks a test skipped by markTestSkipped, markTestIncomplete or a Pest skip/todo chain', function (): void {
    $tests = inventoryOf(<<<'PHP'
        final class FlakyTest extends TestCase
        {
            public function test_skipped(): void { $this->markTestSkipped('later'); }
            public function test_incomplete(): void { $this->markTestIncomplete(); }
            public function test_running(): void { $this->assertTrue($x); }
        }

        it('is skipped', function () { expect(1)->toBe(2); })->skip();
        it('is a todo')->todo();
        it('is grouped', function () { expect($x)->toBe(1); })->group('slow');
        PHP);

    expect($tests['FlakyTest::test_skipped']->skipped)->toBeTrue()
        ->and($tests['FlakyTest::test_incomplete']->skipped)->toBeTrue()
        ->and($tests['FlakyTest::test_running']->skipped)->toBeFalse()
        ->and($tests['it is skipped']->skipped)->toBeTrue()
        ->and($tests['it is a todo']->skipped)->toBeTrue()
        ->and($tests['it is a todo']->assertions)->toBe(0)
        ->and($tests['it is grouped']->skipped)->toBeFalse();
});

it('counts assertions that cannot fail as trivial', function (): void {
    $tests = inventoryOf(<<<'PHP'
        final class HollowTest extends TestCase
        {
            public function test_hollow(): void
            {
                $this->assertTrue(true);
                $this->assertFalse(false);
                $this->assertNull(null);
                $this->assertSame($a, $a);
                $this->assertEquals('x', 'x');
                $this->addToAssertionCount(1);
            }

            public function test_real(): void
            {
                $this->assertTrue($order->paid);
                $this->assertFalse(true);
                $this->assertNull($value);
                $this->assertSame($a, $b);
                $this->assertSame($a);
                $this->assertTrue();
            }
        }

        it('is hollow', function () {
            expect(true)->toBeTrue();
            expect(false)->toBeFalse();
            expect(null)->toBeNull();
            expect(1)->toBe(1);
            expect('a')->toEqual('a');
        });

        it('is real', function () {
            expect($paid)->toBeTrue();
            expect(true)->toBeFalse();
            expect(1)->toBe(2);
            expect(1)->toBeInt();
            expect(1)->toBe();
        });
        PHP);

    expect($tests['HollowTest::test_hollow']->trivial)->toBe(6)
        ->and($tests['HollowTest::test_real']->trivial)->toBe(0)
        ->and($tests['it is hollow']->trivial)->toBe(5)
        ->and($tests['it is real']->trivial)->toBe(0);
});

it('records the line of each test and a hash of its body that ignores its name', function (): void {
    $tests = inventoryOf(<<<'PHP'
        final class RenamedTest extends TestCase
        {
            public function test_old_name(): void { $this->assertSame(1, $x); }

            public function test_new_name(): void { $this->assertSame(1, $x); }

            public function test_different(): void { $this->assertSame(2, $x); }
        }
        PHP);

    $old = $tests['RenamedTest::test_old_name'];

    expect($old->line)->toBe(5)
        ->and($old->name)->toBe('RenamedTest::test_old_name')
        ->and($old->hash)->toBe($tests['RenamedTest::test_new_name']->hash)
        ->and($old->hash)->not->toBe($tests['RenamedTest::test_different']->hash)
        ->and($old->hash)->toMatch('/^[0-9a-f]{32}$/');
});

it('treats a Pest test with no closure as an empty body', function (): void {
    $tests = inventoryOf("it('is pending');\nit('takes a callable', 'strlen');\n");

    expect($tests['it is pending']->assertions)->toBe(0)
        ->and($tests['it is pending']->hash)->toBe(md5(''))
        ->and($tests['it takes a callable']->assertions)->toBe(0);
});

it('finds nothing in a file without tests', function (): void {
    expect(inventoryOf('final class Plain { public function run(): void { $this->assertSomething(); } }'))->toBe([]);
});

it('keeps helpers scoped to the class that defines them', function (): void {
    $tests = inventoryOf(<<<'PHP'
        final class FirstTest extends TestCase
        {
            public function test_one(): void { $this->check(); }
            private function check(): void { $this->assertTrue($a); $this->assertTrue($b); }
        }

        final class SecondTest extends TestCase
        {
            public function test_two(): void { $this->check(); }
        }
        PHP);

    expect($tests['FirstTest::test_one']->assertions)->toBe(2)
        ->and($tests['SecondTest::test_two']->assertions)->toBe(0);
});
