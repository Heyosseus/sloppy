<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Architecture\ArchitectureSnapshot;
use Heyosseus\Sloppy\Architecture\DependencyGraph;
use Heyosseus\Sloppy\Architecture\DeptracImporter;
use Heyosseus\Sloppy\Architecture\Placement;
use Heyosseus\Sloppy\Architecture\ProfileException;
use Heyosseus\Sloppy\Architecture\ProfileInference;
use Heyosseus\Sloppy\Architecture\ProfilePrompt;
use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Sloppy;

/**
 * Show the architecture as Sloppy reads it -- which role every class plays,
 * which roles depend on which, where a new class belongs -- and help a
 * project write one down.
 *
 * Every rule about where code belongs stands on these answers, so they have to
 * be visible. A finding that calls a class "a controller" is only as good as
 * the reason the class counts as one, and this is where that reason is shown.
 */
final readonly class ArchitectureRunner
{
    public function __construct(private ArchitectureReport $report = new ArchitectureReport) {}

    public function run(Sloppy $sloppy, ArchitectureOptions $options, RunnerOutput $output): ExitCode
    {
        try {
            return match ($options->action) {
                'init' => (new ProposalWriter)->write($sloppy, ProfileInference::for($sloppy)->propose(), $options, $output, 'sloppy architecture init'),
                'import' => $this->import($sloppy, $options, $output),
                'prompt' => $this->prompt($sloppy, $options, $output),
                default => $this->describe($sloppy, $options, $output),
            };
        } catch (ProfileException $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }
    }

    private function describe(Sloppy $sloppy, ArchitectureOptions $options, RunnerOutput $output): ExitCode
    {
        $snapshot = ArchitectureSnapshot::of($sloppy);

        if ($options->action === 'graph') {
            $output->report(DependencyGraph::of($snapshot)->render($options->graphFormat), $options->graphFormat === 'json' ? OutputFormat::Json : OutputFormat::Markdown);

            return ExitCode::Success;
        }

        if ($options->action === 'place') {
            $placement = new Placement($snapshot);
            $answer = $placement->answer((string) $options->query, $options->name);

            $output->report($options->format === OutputFormat::Json ? $this->report->json($answer) : $placement->text($answer), $options->format);

            return ExitCode::Success;
        }

        return $options->class === null
            ? $this->overview($snapshot, $options->format, $output)
            : $this->explain($snapshot, $options->class, $options->format, $output);
    }

    private function overview(ArchitectureSnapshot $snapshot, OutputFormat $format, RunnerOutput $output): ExitCode
    {
        $output->report($this->report->overview($snapshot, $format), $format);

        if ($format === OutputFormat::Console) {
            foreach ($this->report->emptyRoles($snapshot) as $role) {
                $output->warn(sprintf('Role [%s] matches no class in the analysed paths. Check its matchers: %s.', $role->name, $role->matcher->describe()));
            }
        }

        return ExitCode::Success;
    }

    private function explain(ArchitectureSnapshot $snapshot, string $name, OutputFormat $format, RunnerOutput $output): ExitCode
    {
        $candidates = $this->report->find($snapshot, $name);

        if (count($candidates) !== 1) {
            $output->error($candidates === []
                ? sprintf('No class named %s in the analysed paths.', $name)
                : sprintf('%s is ambiguous. Name one of: %s.', $name, implode(', ', array_map(static fn (ClassSummary $summary): string => $summary->fqn, $candidates))));

            return ExitCode::Error;
        }

        $output->report($this->report->explain($snapshot, $candidates[0], $format), $format);

        return ExitCode::Success;
    }

    private function import(Sloppy $sloppy, ArchitectureOptions $options, RunnerOutput $output): ExitCode
    {
        $base = $sloppy->configuration->basePath;
        $file = $options->query ?? DeptracImporter::find($base);

        if ($file === null) {
            $output->error(sprintf('No deptrac configuration found. Name the file: sloppy architecture import path/to/deptrac.yaml (looked for %s).', implode(', ', DeptracImporter::FILES)));

            return ExitCode::Error;
        }

        $path = is_file($file) ? $file : $base.'/'.$file;

        if (! is_file($path)) {
            $output->error(sprintf('%s does not exist.', $file));

            return ExitCode::Error;
        }

        return (new ProposalWriter)->write($sloppy, (new DeptracImporter)->import($path, $file), $options, $output, 'sloppy architecture import');
    }

    private function prompt(Sloppy $sloppy, ArchitectureOptions $options, RunnerOutput $output): ExitCode
    {
        $prompt = new ProfilePrompt(ProfileInference::for($sloppy));

        $output->report(
            $options->format === OutputFormat::Json ? $this->report->json($prompt->toArray()) : $prompt->markdown(),
            $options->format === OutputFormat::Json ? OutputFormat::Json : OutputFormat::Markdown,
        );

        return ExitCode::Success;
    }
}
