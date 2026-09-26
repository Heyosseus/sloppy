# Custom rules

Writing a rule of your own, and where it shows up once it is registered.

[← Back to the README](../README.md) · [All documentation](README.md)

## Custom rules

Extend `BaseRule` and register the class. Options come from
`sloppy.rules.<ID>`, keyed by whatever `id()` returns.

```php
namespace App\Sloppy;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Expr\StaticCall;

final class NoFacadesInDomainRule extends BaseRule
{
    public function id(): string
    {
        return 'APP001';
    }

    public function name(): string
    {
        return 'Facade In Domain';
    }

    public function description(): string
    {
        return 'Flags Laravel facades used inside the domain layer.';
    }

    public function explanation(): string
    {
        return 'The domain layer is meant to be testable without the framework booted.';
    }

    public function category(): Category
    {
        return Category::Architecture;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        if (! str_contains($context->relativePath(), 'app/Domain/')) {
            return;
        }

        foreach (NodeHelper::find($context->ast(), StaticCall::class) as $call) {
            $class = NodeHelper::staticCallClass($call);

            if ($class === null || ! str_contains($class, 'Illuminate\Support\Facades')) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $call,
                message: sprintf('%s is used in the domain layer.', NodeHelper::baseName($class)),
                suggestion: 'Inject the underlying service instead.',
                confidence: $this->intOption('confidence', 90),
                fingerprint: NodeHelper::baseName($class),
            );
        }
    }
}
```

```php
// config/sloppy.php
'custom_rules' => [
    App\Sloppy\NoFacadesInDomainRule::class,
],

'rules' => [
    'APP001' => ['confidence' => 80],
],
```

`AnalysisContext` gives you the parsed file and a project-wide index; write
rules against those rather than reading files yourself, so each file is parsed
once per run. `NodeHelper` carries the shared AST vocabulary — class
classification, metrics, chain walking — so a rule contains only the detection
it is named after.

Rules must be deterministic and side-effect free: no disk writes, no network,
no clock. A rule that throws is caught, recorded in `errors`, and does not stop
the run.

### Where a custom rule shows up

Once registered, it is a rule like any other: it scores, it appears in diffs
and CI reports, it can be baselined, and `sloppy:rules` writes it into the
agent ruleset -- see [Your custom rules teach the agents
too](agents.md#your-custom-rules-teach-the-agents-too) for why `description()` deserves
a sentence rather than a label.

One honest exception: `sloppy fix` will not fix it. The mapping from a rule to
the Rector rules that rewrite it lives in `resources/rector-rules.php` inside
this package and is not extendable from your project, so a custom rule's
findings are always reported as needing a person. That is the truthful answer
rather than a limitation worth hiding -- a fix pass that silently skipped your
rule while claiming to have run would be worse than one that says so.
