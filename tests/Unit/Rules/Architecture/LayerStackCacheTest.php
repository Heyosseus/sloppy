<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests\Unit\Rules\Architecture;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Rules\Architecture\AbstractionInflationRule;
use Heyosseus\Sloppy\Rules\Architecture\LayerStack;
use PHPUnit\Framework\TestCase;
use WeakReference;

final class LayerStackCacheTest extends TestCase
{
    public function test_groups_are_reused_for_the_same_index_and_options(): void
    {
        $parsed = (new Parser)->parse('app/Order.php', '<?php namespace App; class Order {} class OrderRepository {}');
        $index = ProjectIndex::build([$parsed]);
        $first = LayerStack::group($index);
        self::assertSame($first, LayerStack::group($index));
        self::assertCount(2, $first['Order']->members);
        self::assertSame(['Order', 'OrderRepository'], array_keys(LayerStack::group($index, ['Adapter'])));
        self::assertSame($first, LayerStack::group($index));
    }

    public function test_fresh_index_does_not_reuse_an_old_answer(): void
    {
        $parser = new Parser;
        $first = ProjectIndex::build([$parser->parse('app/Order.php', '<?php namespace App; class Order {} class OrderRepository {}')]);
        $second = ProjectIndex::build([$parser->parse('app/Order.php', '<?php namespace App; class Order {} class OrderService {} class OrderHandler {}')]);
        self::assertCount(2, LayerStack::group($first)['Order']->members);
        self::assertCount(3, LayerStack::group($second)['Order']->members);
        self::assertCount(2, LayerStack::group($first)['Order']->members);
    }

    public function test_filtered_groups_respect_each_rule_configuration(): void
    {
        $parsed = (new Parser)->parse('app/Invoice.php', '<?php namespace App; class FrameworkBase {} class InvoiceService extends FrameworkBase { public function run() {} } class InvoiceManager extends FrameworkBase { public function run() {} } class InvoiceRepository extends FrameworkBase { public function run() {} }');
        $index = ProjectIndex::build([$parsed]);
        $context = new AnalysisContext($parsed, $index);
        $normal = new AbstractionInflationRule(['min_signals' => 1, 'convention_bases' => []]);
        $excluded = new AbstractionInflationRule(['min_signals' => 1, 'convention_bases' => ['App\\FrameworkBase']]);
        $expected = iterator_to_array($normal->analyze($context));
        self::assertCount(3, $expected);
        self::assertSame([], iterator_to_array($excluded->analyze($context)));
        self::assertEquals($expected, iterator_to_array($normal->analyze($context)));
    }

    public function test_caches_do_not_keep_an_index_alive(): void
    {
        $parsed = (new Parser)->parse('app/Order.php', '<?php namespace App; class OrderService {}');
        $index = ProjectIndex::build([$parsed]);
        $reference = WeakReference::create($index);
        $rule = new AbstractionInflationRule;
        LayerStack::group($index);
        $context = new AnalysisContext($parsed, $index);
        iterator_to_array($rule->analyze($context));
        unset($context, $index);
        gc_collect_cycles();
        self::assertNull($reference->get());
    }
}
