<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Contracts\Formatter;
use Heyosseus\Sloppy\Contracts\Rule;

/**
 * SARIF 2.1.0 -- the answer to "why not SonarQube", without a dashboard.
 *
 * SARIF is what GitHub code scanning, VS Code and the JetBrains IDEs already
 * read, so one formatter puts findings inline on a pull request diff and in the
 * problems panel of an editor for the cost of a serialiser. No server, no
 * database, no second tool to keep running.
 *
 * The hard half was already built. GitHub deduplicates findings across runs
 * with `partialFingerprints`, and it wants exactly what `Finding::identity()`
 * already is: a stable hash of rule, file and a rule-supplied fingerprint that
 * deliberately excludes the line number, so adding an import at the top of a
 * file does not resurrect every finding beneath it.
 *
 * @see https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html
 */
final readonly class SarifFormatter implements Formatter
{
    public const string SCHEMA = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json';

    public const string VERSION = '2.1.0';

    public function __construct(private string $toolVersion = 'dev') {}

    public function format(AnalysisResult $result): string
    {
        return json_encode([
            '$schema' => self::SCHEMA,
            'version' => self::VERSION,
            'runs' => [[
                'tool' => ['driver' => $this->driver($result)],
                'results' => array_map($this->result(...), $result->findings),
                'invocations' => [[
                    'executionSuccessful' => $result->errors === [],
                    // Rules skipped for a missing framework are part of what
                    // this run did and did not do, and a reader looking at an
                    // empty report deserves to know ten rules never ran.
                    'toolExecutionNotifications' => $this->notifications($result),
                ]],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }

    /**
     * @return array<string, mixed>
     */
    private function driver(AnalysisResult $result): array
    {
        return [
            'name' => 'sloppy',
            'version' => $this->toolVersion,
            'informationUri' => 'https://github.com/heyosseus/sloppy',
            'rules' => $this->rules($result),
        ];
    }

    /**
     * Only the rules that actually fired.
     *
     * A viewer showing twenty-four rule descriptions for a report containing
     * two findings buries the two, and SARIF allows the rule array to describe
     * exactly the results present.
     *
     * @return list<array<string, mixed>>
     */
    private function rules(AnalysisResult $result): array
    {
        $seen = [];

        foreach ($result->findings as $finding) {
            $seen[$finding->ruleId] = $finding;
        }

        ksort($seen);

        $registry = RuleRegistry::withDefaults();
        $rules = [];

        foreach ($seen as $id => $finding) {
            $rule = $registry->get((string) $id);

            $rules[] = [
                'id' => (string) $id,
                'name' => $finding->ruleName,
                'shortDescription' => ['text' => $finding->ruleName],
                'fullDescription' => ['text' => $rule instanceof Rule ? $rule->description() : $finding->explanation],
                'help' => ['text' => $finding->explanation],
                'properties' => [
                    'category' => $finding->category->value,
                    'tags' => ['sloppy', $finding->category->value],
                ],
                'defaultConfiguration' => ['level' => $this->level($finding->severity)],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Finding $finding): array
    {
        $region = ['startLine' => max(1, $finding->location->line)];

        if ($finding->location->endLine !== null && $finding->location->endLine >= $finding->location->line) {
            $region['endLine'] = $finding->location->endLine;
        }

        if ($finding->location->column !== null && $finding->location->column > 0) {
            $region['startColumn'] = $finding->location->column;
        }

        return [
            'ruleId' => $finding->ruleId,
            'level' => $this->level($finding->severity),
            'message' => ['text' => $finding->message."\n\n".$finding->suggestion],
            'locations' => [[
                'physicalLocation' => [
                    'artifactLocation' => [
                        'uri' => $finding->location->relativePath,
                        'uriBaseId' => '%SRCROOT%',
                    ],
                    'region' => $region,
                ],
            ]],
            // The mechanism GitHub uses to recognise a finding it has already
            // seen. `identity()` excludes the line number by design, which is
            // exactly the property a fingerprint needs.
            'partialFingerprints' => ['sloppyIdentity/v1' => $finding->identity()],
            'properties' => [
                'confidence' => $finding->confidence,
                'category' => $finding->category->value,
                'severity' => $finding->severity->value,
            ] + $finding->metrics,
        ];
    }

    /**
     * SARIF has four levels against this package's five severities.
     *
     * `critical` and `high` both map to `error` because both are things SARIF
     * consumers should surface as blocking; the distinction survives in
     * `properties.severity` for anything that cares.
     */
    private function level(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical, Severity::High => 'error',
            Severity::Medium => 'warning',
            Severity::Low, Severity::Info => 'note',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(AnalysisResult $result): array
    {
        $notifications = [];

        if ($result->skippedRules !== []) {
            $notifications[] = [
                'level' => 'note',
                'message' => ['text' => sprintf(
                    '%d rule(s) were skipped because their framework is not present: %s.',
                    count($result->skippedRules),
                    implode(', ', $result->skippedRules),
                )],
            ];
        }

        foreach ($result->errors as $where => $message) {
            $notifications[] = [
                'level' => 'error',
                'message' => ['text' => sprintf('%s: %s', $where, $message)],
            ];
        }

        return $notifications;
    }
}
