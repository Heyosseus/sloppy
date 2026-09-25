<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Laravel\QueryInsideLoopRule;

function queryInLoop(): QueryInsideLoopRule
{
    return new QueryInsideLoopRule;
}

it('flags an Eloquent query inside a loop', function (): void {
    $found = findings(queryInLoop(), <<<'PHP'
    class Rows
    {
        public function build(array $users): array
        {
            $rows = [];

            foreach ($users as $user) {
                $rows[] = Order::where('user_id', $user->id)->get();
            }

            return $rows;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL204')
        ->and($found[0]->metrics['subject'])->toBe('Order')
        ->and($found[0]->metrics['chain'])->toBe('where->get')
        ->and($found[0]->suggestion)->toContain('whereIn');
});

it('reads the far end of a fluent chain', function (): void {
    // The static call is DB::table(); whether it reaches the database is
    // decided by the ->get() at the end.
    $found = findings(queryInLoop(), <<<'PHP'
    class Rows
    {
        public function build(array $orders): int
        {
            $total = 0;

            foreach ($orders as $order) {
                $total += DB::table('payments')->where('order_id', $order->id)->sum('amount');
            }

            return $total;
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['subject'])->toBe('DB')
        ->and($found[0]->metrics['chain'])->toBe('table->where->sum');
});

it('does not flag a query outside a loop', function (): void {
    expect(findings(queryInLoop(), <<<'PHP'
    class Rows
    {
        public function build(array $userIds): Collection
        {
            return Order::whereIn('user_id', $userIds)->get()->groupBy('user_id');
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag an unrelated facade call inside a loop', function (): void {
    expect(findings(queryInLoop(), <<<'PHP'
    class Rows
    {
        public function build(array $items): void
        {
            foreach ($items as $item) {
                Log::info('processing', ['id' => $item->id]);
                Cache::forget('item.'.$item->id);
            }
        }
    }
    PHP))->toBeEmpty();
});

it('does not flag a query builder chain that never runs a query', function (): void {
    expect(findings(queryInLoop(), <<<'PHP'
    class Rows
    {
        public function build(array $filters): Builder
        {
            $query = Order::query();

            foreach ($filters as $field => $value) {
                $query = $query->where($field, $value);
            }

            return $query;
        }
    }
    PHP))->toBeEmpty();
});

it('reports one finding per distinct query in a loop', function (): void {
    $found = findings(queryInLoop(), <<<'PHP'
    class Rows
    {
        public function build(array $users): array
        {
            $rows = [];

            foreach ($users as $user) {
                $rows[] = Order::where('user_id', $user->id)->get();
                $rows[] = Order::where('user_id', $user->id)->get();
                $rows[] = Invoice::where('user_id', $user->id)->first();
            }

            return $rows;
        }
    }
    PHP);

    expect($found)->toHaveCount(2);
});

it('flags a query inside a while loop', function (): void {
    expect(findings(queryInLoop(), <<<'PHP'
    class Cursor
    {
        public function drain(): void
        {
            while ($this->hasMore()) {
                $batch = Order::where('processed', false)->limit(100)->get();
                $this->handle($batch);
            }
        }
    }
    PHP))->toHaveCount(1);
});

it('does not flag a static helper that merely shares a name with a query', function (): void {
    // `find`, `get`, `first` and `count` are not unique to Eloquent.
    expect(findings(queryInLoop(), <<<'SNIPPET'
    class Walker
    {
        public function walk(array $nodes): array
        {
            $found = [];

            foreach ($nodes as $node) {
                $found[] = self::find($node);
                $found[] = static::first($node);
            }

            return $found;
        }

        public static function find(mixed $node): mixed
        {
            return $node;
        }

        public static function first(mixed $node): mixed
        {
            return $node;
        }
    }
    SNIPPET))->toBeEmpty();
});

it('does not flag a class the project knows is not a model', function (): void {
    expect(findingsAcross(queryInLoop(), [
        'app/NodeHelper.php' => <<<'SNIPPET'
        namespace App\Ast;

        class NodeHelper
        {
            public static function find(mixed $node): array
            {
                return [];
            }
        }
        SNIPPET,
        'app/Walker.php' => <<<'SNIPPET'
        namespace App\Ast;

        class Walker
        {
            public function walk(array $nodes): array
            {
                $found = [];

                foreach ($nodes as $node) {
                    $found[] = NodeHelper::find($node);
                }

                return $found;
            }
        }
        SNIPPET,
    ]))->toBeEmpty();
});

it('still flags a class the project knows is a model', function (): void {
    expect(findingsAcross(queryInLoop(), [
        'app/Order.php' => "namespace App\Models;\n\nuse Illuminate\Database\Eloquent\Model;\n\nclass Order extends Model {}",
        'app/Walker.php' => <<<'SNIPPET'
        namespace App\Models;

        class Walker
        {
            public function walk(array $ids): array
            {
                $found = [];

                foreach ($ids as $id) {
                    $found[] = Order::find($id);
                }

                return $found;
            }
        }
        SNIPPET,
    ]))->toHaveCount(1);
});

it('does not flag a lookup memoized with ??=', function (): void {
    // Each key is queried once however many rows share it, which is the
    // in-memory lookup the suggestion asks for, built lazily.
    expect(findings(queryInLoop(), <<<'PHP'
    class Importer
    {
        private array $residents = [];

        public function import(array $rows): void
        {
            $units = [];

            foreach ($rows as $row) {
                $units[$row['unit']] ??= Unit::where('code', $row['unit'])->first();
                $this->residents[$row['email']] ??= Resident::find($row['email']);
            }
        }
    }
    PHP))->toBeEmpty();
});

it('still flags a keyed assignment that is not memoized', function (): void {
    // `=` runs the query on every row; only `??=` skips keys already seen.
    $found = findings(queryInLoop(), <<<'PHP'
    class Importer
    {
        public function import(array $rows): void
        {
            foreach ($rows as $row) {
                $row['unit_id'] = Unit::where('code', $row['unit'])->value('id');
            }
        }
    }
    PHP);

    expect($found)->toHaveCount(1);
});

it('gives write advice for a write inside a loop', function (): void {
    $found = findings(queryInLoop(), <<<'PHP'
    class Import
    {
        public function run(array $rows): void
        {
            foreach ($rows as $row) {
                Branch::query()->create($row);
            }
        }
    }
    PHP);

    expect($found)->toHaveCount(1)
        ->and($found[0]->metrics['kind'])->toBe('write')
        ->and($found[0]->message)->toContain('writes to Branch')
        ->and($found[0]->suggestion)->toContain('insert(')
        ->and($found[0]->suggestion)->toContain('upsert(')
        ->and($found[0]->suggestion)->not->toContain('whereIn');
});

it('labels a read inside a loop as a read', function (): void {
    $found = findings(queryInLoop(), <<<'PHP'
    class Rows
    {
        public function build(array $ids): void
        {
            foreach ($ids as $id) {
                Order::where('id', $id)->first();
            }
        }
    }
    PHP);

    expect($found[0]->metrics['kind'])->toBe('read');
});

it('does not flag a bulk write over chunks', function (): void {
    // One insert per chunk is the bulk pattern, not a query per row.
    expect(findings(queryInLoop(), <<<'PHP'
    class Import
    {
        public function run(array $rows, Collection $items): void
        {
            foreach (array_chunk($rows, 500) as $chunk) {
                Branch::query()->insert($chunk);
            }

            foreach ($items->chunk(500) as $batch) {
                DB::table('items')->upsert($batch->all(), ['id']);
            }
        }
    }
    PHP))->toBeEmpty();
});

it('still flags a per-row write inside a loop over chunks', function (): void {
    expect(findings(queryInLoop(), <<<'PHP'
    class Import
    {
        public function run(array $rows): void
        {
            foreach (array_chunk($rows, 500) as $chunk) {
                foreach ($chunk as $row) {
                    Branch::query()->create($row);
                }
            }
        }
    }
    PHP))->toHaveCount(1);
});
