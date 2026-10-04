<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Evidence\EvidenceContext;
use Heyosseus\Sloppy\Evidence\MisplacedClassSource;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * The SL307 findings of a diff against the commit before the change.
 *
 * @param  array<string, mixed>  $architecture
 * @param  array<string, string>  $before
 * @param  array<string, string|null>  $after  Null deletes the file.
 * @return list<Finding>
 */
function misplaced(array $architecture, array $before, array $after): array
{
    $repository = TempRepository::create()
        ->write('sloppy.php', '<?php return '.var_export(['paths' => ['app', 'lib'], 'architecture' => $architecture], true).';');

    foreach ($before as $path => $source) {
        $repository->write($path, $source);
    }

    $repository->commit('base');

    foreach ($after as $path => $source) {
        $source === null ? $repository->delete($path) : $repository->write($path, $source);
    }

    $report = Sloppy::forProject($repository->path)->diff('HEAD');
    $repository->remove();

    return array_values(array_filter($report->new, static fn (Finding $finding): bool => $finding->ruleId === 'SL307'));
}

const COVERED = ['covers' => ['app/*']];

it('reports a new class in a covered path that plays no role', function (): void {
    $findings = misplaced(COVERED, ['app/Http/Controllers/OrderController.php' => '<?php namespace App\Http\Controllers; class OrderController {}'], [
        'app/Support/Helpers/DataUtils.php' => "<?php\nnamespace App\\Support\\Helpers;\n\nfinal class DataUtils {}\n",
        'app/Services/Billing.php' => '<?php namespace App\Services; class Billing {}',
        'lib/Loose.php' => '<?php namespace Lib; class Loose {}',
    ]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Low)
        ->and($findings[0]->category)->toBe(Category::Dependencies)
        ->and($findings[0]->location->relativePath)->toBe('app/Support/Helpers/DataUtils.php')
        ->and($findings[0]->location->line)->toBe(4)
        ->and($findings[0]->message)->toBe('New class App\Support\Helpers\DataUtils plays no role in this project\'s architecture, and app/Support/Helpers/DataUtils.php is in a path where every class should.')
        ->and($findings[0]->suggestion)->toBe('Put it where one of the roles expects it (form-request, model, controller, middleware, service) -- `sloppy architecture place` says where each one lives -- or, if it is a new kind of class, declare its role in sloppy.architecture.roles (sloppy.php).')
        ->and($findings[0]->fingerprint)->toBe('App\Support\Helpers\DataUtils')
        ->and($findings[0]->metrics)->toBe(['class' => 'App\Support\Helpers\DataUtils', 'kind' => 'class']);
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('reports a class added to a file that already existed, but never one that was there before', function (): void {
    $findings = misplaced(COVERED, ['app/Support/Str.php' => '<?php namespace App\Support; class Str {}'], [
        'app/Support/Str.php' => '<?php namespace App\Support; class Str { public function a() {} } class Arr {}',
    ]);

    expect(array_map(static fn (Finding $finding): string => $finding->fingerprint, $findings))->toBe(['App\Support\Arr']);
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('reports a class that moved by name, and nothing for a project that covers nothing', function (): void {
    $before = ['app/Services/Pricing.php' => '<?php namespace App\Services; class Pricing {}'];
    $after = ['app/Services/Pricing.php' => null, 'app/Pricing/Pricing.php' => '<?php namespace App\Pricing; class Pricing {}'];

    expect(array_map(static fn (Finding $finding): string => $finding->fingerprint, misplaced(COVERED, $before, $after)))->toBe(['App\Pricing\Pricing'])
        ->and(misplaced([], $before, $after))->toBe([])
        ->and(misplaced(['covers' => 'app/*'], [], ['app/Helper.php' => '<?php namespace App; class Helper {}']))->toHaveCount(1);
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('names the file the profile came from when there are no roles to suggest', function (): void {
    $findings = misplaced(['preset' => 'none', 'covers' => 'app/*'], [], ['app/Anything.php' => '<?php namespace App; class Anything {}']);

    expect($findings[0]->suggestion)->toBe('Declare the role this class plays in sloppy.architecture.roles (sloppy.php), or move it out of the covered paths.');
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available');

it('describes itself, and says nothing without an index or a covered change', function (): void {
    $source = new MisplacedClassSource(Profile::fromArray(COVERED));
    $context = static fn (array $files): EvidenceContext => new EvidenceContext(
        git: new Heyosseus\Sloppy\Git\Git(__DIR__),
        basePath: __DIR__,
        baseRevision: 'HEAD',
        changedFiles: $files,
        index: Heyosseus\Sloppy\Ast\ProjectIndex::build([]),
    );

    expect($source->id())->toBe('SL307')
        ->and($source->name())->toBe('Misplaced Class')
        ->and($source->category())->toBe(Category::Dependencies)
        ->and($source->severity())->toBe(Severity::Low)
        ->and($source->explanation())->toContain('no policy holds to anything')
        ->and($source->description())->not->toBe('')
        ->and($source->appliesTo(Profile::fromArray(COVERED)))->toBeTrue()
        ->and($source->appliesTo(Profile::default()))->toBeFalse()
        ->and(iterator_to_array($source->evidence(new EvidenceContext(new Heyosseus\Sloppy\Git\Git(__DIR__), __DIR__, 'HEAD', []))))->toBe([])
        ->and(iterator_to_array($source->evidence($context([new Heyosseus\Sloppy\Git\ChangedFile('lib/A.php', 'added')]))))->toBe([])
        ->and(iterator_to_array((new MisplacedClassSource(Profile::default()))->evidence($context([]))))->toBe([]);
});
