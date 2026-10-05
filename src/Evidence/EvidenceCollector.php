<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Evidence;

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Architecture\PolicyRule;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Contracts\EvidenceSource;
use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\Git;
use Heyosseus\Sloppy\Support\StringListOption;

/**
 * The evidence sources a diff will use.
 *
 * The mirror of {@see \Heyosseus\Sloppy\Analysis\RuleRegistry}, and it obeys the
 * same configuration: a team switches `SL502` off exactly the way it switches
 * any rule off, rather than having to stop using diff mode.
 *
 * It holds the base path and builds the context itself, so the caller needs to
 * know only what changed and what it changed against.
 */
final readonly class EvidenceCollector
{
    /**
     * @param  list<EvidenceSource>  $sources
     */
    public function __construct(
        private array $sources = [],
        private string $basePath = '',
    ) {}

    public static function fromConfiguration(Configuration $config): self
    {
        $sources = [];

        $profile = $config->architecture();

        $candidates = [
            new BaselineGrowthSource(self::stringsFrom($config, 'SL502', 'files', ['phpstan-baseline.neon', 'psalm-baseline.xml'])),
            new WeakenedTestSource(self::stringsFrom($config, 'SL503', 'paths', ['tests'])),
            new MisplacedClassSource($profile),
        ];

        foreach ($candidates as $source) {
            // Like a policy rule, SL307 joins only when the architecture
            // declares where classes are expected to play a role.
            if ($config->isRuleEnabled($source->id()) && (! $source instanceof PolicyRule || $source->appliesTo($profile))) {
                $sources[] = $source;
            }
        }

        return new self($sources, $config->basePath);
    }

    /**
     * A list option of one evidence source: which baseline files `SL502`
     * watches, or which directories `SL503` treats as tests.
     *
     * Read from the detector's own options, the same place `SL501` reads its
     * suppression vocabulary. A detector's inputs belong with the detector --
     * `SL502`'s are other tools' baselines, not Sloppy's own, which is
     * `sloppy.baseline`.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function stringsFrom(Configuration $config, string $id, string $key, array $default): array
    {
        $configured = $config->ruleOptions($id)[$key] ?? null;

        return is_array($configured) ? StringListOption::from($configured) : $default;
    }

    /**
     * @param  list<EvidenceSource>  $sources
     */
    public static function of(array $sources, string $basePath = ''): self
    {
        return new self($sources, $basePath);
    }

    /**
     * @param  list<ChangedFile>  $changedFiles
     * @param  list<ChangedFile>|null  $repositoryChanges  Every file git reports changed, when the caller already asked.
     * @return list<Finding>
     */
    public function collect(Git $git, string $baseRevision, array $changedFiles, ?ProjectIndex $index = null, ?array $repositoryChanges = null): array
    {
        if ($this->sources === []) {
            return [];
        }

        $context = new EvidenceContext(
            git: $git,
            basePath: $this->basePath,
            baseRevision: $baseRevision,
            changedFiles: $changedFiles,
            index: $index,
            repositoryChanges: $repositoryChanges,
        );

        $findings = [];

        foreach ($this->sources as $source) {
            foreach ($source->evidence($context) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (EvidenceSource $source): string => $source->id(), $this->sources);
    }
}
