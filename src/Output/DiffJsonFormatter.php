<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\TierMap;
use Heyosseus\Sloppy\Contracts\DiffFormatter;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Scoring\RiskCalculator;

/**
 * Diff output for CI: the same stable contract as {@see JsonFormatter}, with
 * findings split into new, existing and resolved.
 *
 * Every finding carries `risk` and `tier` exactly as it does in a scan, so a
 * consumer ranking findings reads one shape whichever command it ran.
 */
final readonly class DiffJsonFormatter implements DiffFormatter
{
    public function __construct(
        private bool $pretty = true,
        private bool $explainRisk = false,
        private RiskCalculator $risk = new RiskCalculator,
        private TierMap $tiers = new TierMap,
    ) {}

    public function format(DiffReport $report): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

        if ($this->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $rows = new JsonFormatter(explainRisk: $this->explainRisk, risk: $this->risk, tiers: $this->tiers);
        $data = $report->toArray();

        $data['new'] = $rows->rows($report->new);
        $data['existing'] = $rows->rows($report->existing);
        $data['resolved'] = $rows->rows($report->resolved);

        // Keyed by path, so always an object -- `{}` when empty, never `[]`.
        $data['errors'] = (object) $report->errors;

        return (new JsonEncoder($flags))->encode($data)."\n";
    }
}
