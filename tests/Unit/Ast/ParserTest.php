<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\Parser;

it('resolves class names to their fully qualified form', function (): void {
    $file = (new Parser)->parse('app/Order.php', <<<'PHP'
    <?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    class Order extends Model {}
    PHP);

    $class = $file->classLikes()[0];

    expect(NodeHelper::className($class))->toBe('App\Models\Order')
        ->and(NodeHelper::shortName($class))->toBe('Order')
        ->and(NodeHelper::parentName($class))->toBe('Illuminate\Database\Eloquent\Model')
        ->and($file->namespaceName())->toBe('App\Models');
});

it('resolves imported facades so rules do not have to read imports', function (): void {
    $file = (new Parser)->parse('app/Client.php', <<<'PHP'
    <?php

    use Illuminate\Support\Facades\Http;

    class Client
    {
        public function go(): void
        {
            Http::get('https://example.com');
        }
    }
    PHP);

    $call = NodeHelper::find($file->ast, PhpParser\Node\Expr\StaticCall::class)[0];

    expect(NodeHelper::staticCallClass($call))->toBe('Illuminate\Support\Facades\Http');
});

it('connects nodes to their parents', function (): void {
    $file = (new Parser)->parse('app/Loop.php', <<<'PHP'
    <?php

    class Loop
    {
        public function go(array $rows): void
        {
            foreach ($rows as $row) {
                $this->save($row);
            }
        }
    }
    PHP);

    $call = NodeHelper::find($file->ast, PhpParser\Node\Expr\MethodCall::class)[0];

    expect(NodeHelper::isInsideLoop($call))->toBeTrue()
        ->and(NodeHelper::enclosingMethod($call)?->name->toString())->toBe('go');
});

it('reports a parse error instead of throwing', function (): void {
    $file = (new Parser)->parse('app/Broken.php', '<?php class Broken { public function');

    expect($file->isParsed())->toBeFalse()
        ->and($file->parseError)->toBeString()
        ->and($file->ast)->toBe([]);
});

it('reports an unreadable file instead of throwing', function (): void {
    $file = (new Parser)->parseFile(__DIR__.'/does-not-exist.php', 'app/Missing.php');

    expect($file->isParsed())->toBeFalse()
        ->and($file->parseError)->toBe('File could not be read.');
});

it('parses a real file from disk', function (): void {
    $file = (new Parser)->parseFile(dirname(__DIR__, 2).'/Fixtures/Good/PaymentClient.php', 'app/PaymentClient.php');

    expect($file->isParsed())->toBeTrue()
        ->and($file->relativePath)->toBe('app/PaymentClient.php')
        ->and($file->classLikes())->toHaveCount(1);
});

it('normalises line endings when counting lines', function (): void {
    $windows = (new Parser)->parse('app/A.php', "<?php\r\n\r\n\$a = 1;\r\n");
    $unix = (new Parser)->parse('app/A.php', "<?php\n\n\$a = 1;\n");

    expect($windows->lineCount())->toBe($unix->lineCount());
});

it('counts only significant lines of code', function (): void {
    $file = (new Parser)->parse('app/A.php', <<<'PHP'
    <?php

    // a comment

    /**
     * A docblock.
     */
    $a = 1;
    PHP);

    // `<?php` and `$a = 1;` are code; blanks, the comment and the docblock are not.
    expect($file->codeLineCount())->toBe(2)
        ->and($file->lineCount())->toBeGreaterThan(2);
});

it('returns source snippets and single lines', function (): void {
    $file = (new Parser)->parse('app/A.php', "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n");

    expect($file->lineAt(2))->toBe('$a = 1;')
        ->and($file->lineAt(999))->toBeNull()
        ->and($file->snippet(2, 3))->toBe("\$a = 1;\n\$b = 2;")
        ->and($file->snippet(-5, 1))->toBe('<?php');
});
