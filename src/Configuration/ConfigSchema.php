<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Configuration;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\Tier;
use Heyosseus\Sloppy\Contracts\Rule;
use Heyosseus\Sloppy\Scoring\ScoreBand;

/**
 * What `config/sloppy.php` may contain, and what each value must look like.
 *
 * Every setting used to be read with a type check and a silent default: a
 * string where a list belonged, `'90'` where a number belonged, `'false'`
 * where a boolean belonged, a misspelt key -- each was quietly replaced by
 * the default, and the run went on analysing something other than what its
 * configuration said. Values that mean one obvious thing are accepted in that
 * meaning (a single path, a numeric string, `'false'`); anything else is a
 * problem, reported together so one run lists them all.
 */
final class ConfigSchema
{
    /**
     * Keys Sloppy does not know. A stale or misspelt key is reported but never
     * fails a run: upgrading must not turn a configuration that worked into an
     * error. A known key with an unusable value is a problem, since running
     * with it would analyse something the configuration did not ask for.
     *
     * @var list<string>
     */
    private array $unknown = [];

    /**
     * @var list<string>
     */
    private const array TOP_LEVEL = [
        'enabled', 'framework', 'architecture', 'paths', 'exclude', 'fail_on', 'min_confidence',
        'baseline', 'score', 'risk', 'rules', 'custom_rules', 'health',
    ];

    /**
     * Options every rule accepts.
     *
     * @var list<string>
     */
    private const array RULE_COMMON = ['enabled', 'severity', 'tier'];

    /**
     * Each shipped rule's own options and their types. A rule that reads a
     * new option must be listed here, or the option is rejected; a test
     * keeps this in step with the rules' source.
     *
     * @var array<string, array<string, 'int'|'float'|'bool'|'list'>>
     */
    public const array RULE_OPTIONS = [
        'SL101' => [
            'max_lines' => 'int', 'max_complexity' => 'int', 'max_statements' => 'int', 'max_nesting' => 'int',
            'max_calls' => 'int', 'max_collaborators' => 'int', 'min_signals' => 'int',
        ],
        'SL102' => [
            'max_lines' => 'int', 'max_methods' => 'int', 'max_public_methods' => 'int', 'max_statements' => 'int',
            'max_dependencies' => 'int', 'max_collaborators' => 'int', 'min_signals' => 'int',
            'model_leniency' => 'float', 'framework_leniency' => 'float', 'framework_bases' => 'list',
        ],
        'SL103' => ['max_depth' => 'int'],
        'SL104' => ['min_statements' => 'int', 'ignore_methods' => 'list'],
        'SL105' => [],
        'SL106' => [],
        'SL107' => [],
        'SL108' => [],
        'SL109' => ['max_words' => 'int', 'detect_step_comments' => 'bool'],
        'SL110' => [],
        'SL111' => [
            'min_statements' => 'int', 'max_token_distance' => 'int', 'max_divergence_ratio' => 'float',
            'max_comparisons' => 'int',
        ],
        'SL112' => [],
        'SL201' => ['min_score' => 'int', 'min_statements' => 'int', 'max_complexity' => 'int', 'max_statements' => 'int'],
        'SL202' => ['max_rules' => 'int', 'controllers_only' => 'bool'],
        'SL203' => ['ignore_relations' => 'list'],
        'SL204' => [],
        'SL205' => [],
        'SL206' => ['max_dependencies' => 'int'],
        'SL207' => ['max_dependencies' => 'int'],
        'SL208' => [],
        'SL209' => ['max_method_lines' => 'int', 'min_signals' => 'int'],
        'SL210' => ['ignore_models' => 'list'],
        'SL301' => [
            'min_layers' => 'int', 'min_signals' => 'int', 'trivial_max_statements' => 'int',
            'trivial_max_methods' => 'int', 'convention_bases' => 'list', 'layer_suffixes' => 'list',
        ],
        'SL302' => ['min_methods' => 'int', 'min_delegation_ratio' => 'float'],
        'SL303' => ['max_methods' => 'int', 'max_usages' => 'int', 'skip_layer_stacks' => 'bool', 'layer_stack_depth' => 'int', 'layer_suffixes' => 'list'],
        'SL304' => [],
        'SL305' => [],
        'SL306' => [],
        'SL307' => [],
        'SL308' => [],
        'SL501' => ['annotations' => 'list'],
        'SL502' => ['files' => 'list'],
        'SL503' => ['paths' => 'list'],
    ];

    /**
     * @var list<string>
     */
    private const array SCORE = ['weights', 'lines_per_unit', 'penalty_multiplier', 'rule_cap', 'bands'];

    /**
     * @var list<string>
     */
    private const array RISK = [
        'severity_weights', 'reach_weight', 'exposure_weight', 'coverage', 'churn_weight', 'churn_commits',
    ];

    /**
     * @var list<string>
     */
    private const array HEALTH = ['cache', 'ttl', 'top'];

    /**
     * The configuration with every value in the shape the rest of the package
     * reads, and what could not be put in that shape.
     *
     * @param  array<array-key, mixed>  $config
     * @return array{config: array<string, mixed>, problems: list<string>, unknown: list<string>}
     */
    public function normalise(array $config): array
    {
        $this->unknown = [];
        $problems = [];
        $normalised = [];

        /** @var mixed $value */
        foreach ($config as $key => $value) {
            $key = (string) $key;

            if (! in_array($key, self::TOP_LEVEL, true)) {
                $this->unknown[] = sprintf('sloppy.%s is not a setting Sloppy knows%s.', $key, $this->suggest($key, self::TOP_LEVEL));
                $normalised[$key] = $value;

                continue;
            }

            $normalised[$key] = match ($key) {
                'enabled' => $this->bool('sloppy.enabled', $value, $problems),
                'framework', 'baseline' => $this->string('sloppy.'.$key, $value, $problems),
                'paths', 'exclude', 'custom_rules' => $this->stringList('sloppy.'.$key, $value, $problems),
                'fail_on' => $this->failOn($value, $problems),
                'min_confidence' => $this->int('sloppy.min_confidence', $value, $problems, 0, 100),
                'architecture' => $this->architecture($value, $problems),
                'score' => $this->score($value, $problems),
                'risk' => $this->risk($value, $problems),
                'health' => $this->health($value, $problems),
                // Rules are checked once every key is read: which IDs exist
                // depends on `custom_rules`, wherever in the array it is.
                default => $value,
            };
        }

        if (array_key_exists('rules', $normalised)) {
            $normalised['rules'] = $this->rules($normalised['rules'], $normalised['custom_rules'] ?? [], $problems);
        }

        return ['config' => $normalised, 'problems' => $problems, 'unknown' => $this->unknown];
    }

    /**
     * @param  list<string>  $problems
     */
    private function architecture(mixed $value, array &$problems): mixed
    {
        // The profile has its own loader, which reports its own mistakes in
        // its own words; all this layer can say is that it is not a profile.
        if ($value !== null && ! is_array($value)) {
            $problems[] = 'sloppy.architecture must be an array.';
        }

        return $value;
    }

    /**
     * @param  list<string>  $problems
     */
    private function failOn(mixed $value, array &$problems): ?string
    {
        if (in_array($value, [null, false, ''], true)) {
            return null;
        }

        if (is_string($value)) {
            $normalised = mb_strtolower(trim($value));

            if (in_array($normalised, ['never', 'none', 'off'], true)) {
                return null;
            }

            if (Severity::tryFrom($normalised) instanceof Severity) {
                return $normalised;
            }
        }

        $problems[] = sprintf(
            'sloppy.fail_on must be one of %s, or null to never fail; got %s.',
            implode(', ', array_column(Severity::cases(), 'value')),
            $this->describe($value),
        );

        return null;
    }

    /**
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function score(mixed $value, array &$problems): array
    {
        $score = $this->section('sloppy.score', $value, self::SCORE, $problems);

        /** @var mixed $item */
        foreach ($score as $key => $item) {
            $score[$key] = match ($key) {
                'weights' => $this->weights('sloppy.score.weights', $item, $problems),
                'bands' => $this->bands($item, $problems),
                'lines_per_unit' => $this->int('sloppy.score.lines_per_unit', $item, $problems, 1),
                'penalty_multiplier', 'rule_cap' => $this->number('sloppy.score.'.$key, $item, $problems),
                default => $item,
            };
        }

        return $score;
    }

    /**
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function risk(mixed $value, array &$problems): array
    {
        $risk = $this->section('sloppy.risk', $value, self::RISK, $problems);

        /** @var mixed $item */
        foreach ($risk as $key => $item) {
            $risk[$key] = match ($key) {
                'severity_weights' => $this->weights('sloppy.risk.severity_weights', $item, $problems),
                'coverage' => $item === null ? null : $this->string('sloppy.risk.coverage', $item, $problems),
                'churn_commits' => $this->int('sloppy.risk.churn_commits', $item, $problems, 0),
                'reach_weight', 'exposure_weight', 'churn_weight' => $this->number('sloppy.risk.'.$key, $item, $problems),
                default => $item,
            };
        }

        return $risk;
    }

    /**
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function health(mixed $value, array &$problems): array
    {
        $health = $this->section('sloppy.health', $value, self::HEALTH, $problems);

        /** @var mixed $item */
        foreach ($health as $key => $item) {
            $health[$key] = match ($key) {
                'cache' => $this->string('sloppy.health.cache', $item, $problems),
                'ttl' => $this->int('sloppy.health.ttl', $item, $problems, 0),
                'top' => $this->int('sloppy.health.top', $item, $problems, 1),
                default => $item,
            };
        }

        return $health;
    }

    /**
     * @param  list<mixed>|mixed  $customRules
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function rules(mixed $value, mixed $customRules, array &$problems): array
    {
        if (! is_array($value)) {
            $problems[] = sprintf('sloppy.rules must be an array keyed by rule ID; got %s.', $this->describe($value));

            return [];
        }

        $custom = $this->customRuleIds($customRules);
        $rules = [];

        /** @var mixed $options */
        foreach ($value as $id => $options) {
            $id = (string) $id;
            $where = 'sloppy.rules.'.$id;
            $known = self::RULE_OPTIONS[$id] ?? null;

            if ($known === null && ! in_array($id, $custom, true)) {
                $this->unknown[] = sprintf('%s: there is no rule %s%s.', $where, $id, $this->suggest($id, [...array_keys(self::RULE_OPTIONS), ...$custom]));
            }

            if (! is_array($options)) {
                $problems[] = sprintf('%s must be an array of options, e.g. [\'enabled\' => false]; got %s.', $where, $this->describe($options));

                continue;
            }

            $rules[$id] = $this->ruleOptions($where, $options, $known, $problems);
        }

        return $rules;
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @param  array<string, 'int'|'float'|'bool'|'list'>|null  $known  Null for a custom rule, whose options are its own business.
     * @param  list<string>  $problems
     * @return array<array-key, mixed>
     */
    private function ruleOptions(string $where, array $options, ?array $known, array &$problems): array
    {
        /** @var mixed $option */
        foreach ($options as $key => $option) {
            $key = (string) $key;
            $at = $where.'.'.$key;

            $options[$key] = match (true) {
                $key === 'enabled' => $this->bool($at, $option, $problems),
                $key === 'severity' => $this->enum($at, $option, array_column(Severity::cases(), 'value'), $problems),
                $key === 'tier' => $this->enum($at, $option, array_column(Tier::cases(), 'value'), $problems),
                $known === null => $option,
                isset($known[$key]) => $this->typed($at, $option, $known[$key], $problems),
                default => $this->unknownOption($at, $key, $option, [...self::RULE_COMMON, ...array_keys($known)]),
            };
        }

        return $options;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function unknownOption(string $at, string $key, mixed $value, array $allowed): mixed
    {
        $this->unknown[] = sprintf(
            '%s is not an option of this rule%s. It takes: %s.',
            $at,
            $this->suggest($key, $allowed),
            implode(', ', $allowed),
        );

        return $value;
    }

    /**
     * @param  'int'|'float'|'bool'|'list'  $type
     * @param  list<string>  $problems
     */
    private function typed(string $at, mixed $value, string $type, array &$problems): mixed
    {
        return match ($type) {
            'int' => $this->int($at, $value, $problems, 0),
            'float' => $this->number($at, $value, $problems),
            'bool' => $this->bool($at, $value, $problems),
            'list' => $this->stringList($at, $value, $problems),
        };
    }

    /**
     * @return list<string>
     */
    private function customRuleIds(mixed $customRules): array
    {
        $ids = [];

        foreach (is_array($customRules) ? $customRules : [] as $class) {
            if (is_string($class) && is_subclass_of($class, Rule::class)) {
                $ids[] = (new $class)->id();
            }
        }

        return $ids;
    }

    /**
     * An array section whose keys must all be known.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function section(string $where, mixed $value, array $keys, array &$problems): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            $problems[] = sprintf('%s must be an array; got %s.', $where, $this->describe($value));

            return [];
        }

        $section = [];

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            $key = (string) $key;

            if (! in_array($key, $keys, true)) {
                $this->unknown[] = sprintf('%s.%s is not a setting Sloppy knows%s.', $where, $key, $this->suggest($key, $keys));
            }

            $section[$key] = $item;
        }

        return $section;
    }

    /**
     * @param  list<string>  $problems
     * @return array<string, float>
     */
    private function weights(string $where, mixed $value, array &$problems): array
    {
        $weights = [];
        $severities = array_column(Severity::cases(), 'value');

        if (! is_array($value)) {
            $problems[] = sprintf('%s must map severities to weights; got %s.', $where, $this->describe($value));

            return [];
        }

        /** @var mixed $weight */
        foreach ($value as $severity => $weight) {
            $severity = (string) $severity;

            if (! in_array($severity, $severities, true)) {
                $problems[] = sprintf('%s.%s is not a severity; expected %s.', $where, $severity, implode(', ', $severities));

                continue;
            }

            $weights[$severity] = $this->number($where.'.'.$severity, $weight, $problems);
        }

        return $weights;
    }

    /**
     * @param  list<string>  $problems
     * @return array<string, int>
     */
    private function bands(mixed $value, array &$problems): array
    {
        $bands = [];
        $names = array_values(array_diff(array_column(ScoreBand::cases(), 'value'), [ScoreBand::Severe->value]));

        if (! is_array($value)) {
            $problems[] = sprintf('sloppy.score.bands must map bands to minimum scores; got %s.', $this->describe($value));

            return [];
        }

        /** @var mixed $minimum */
        foreach ($value as $band => $minimum) {
            $band = (string) $band;

            if (! in_array($band, $names, true)) {
                $problems[] = sprintf('sloppy.score.bands.%s is not a band; expected %s.', $band, implode(', ', $names));

                continue;
            }

            $bands[$band] = $this->int('sloppy.score.bands.'.$band, $minimum, $problems, 0, 100);
        }

        return $bands;
    }

    /**
     * @param  list<string>  $problems
     */
    private function bool(string $where, mixed $value, array &$problems): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalised = is_string($value) || is_int($value) ? mb_strtolower(trim((string) $value)) : null;

        if (in_array($normalised, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalised, ['false', '0', 'no', 'off', ''], true)) {
            return false;
        }

        $problems[] = sprintf('%s must be true or false; got %s.', $where, $this->describe($value));

        return true;
    }

    /**
     * @param  list<string>  $problems
     */
    private function int(string $where, mixed $value, array &$problems, ?int $min = null, ?int $max = null): int
    {
        $int = match (true) {
            is_int($value) => $value,
            is_float($value) && floor($value) === $value => (int) $value,
            is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1 => (int) trim($value),
            default => null,
        };

        if ($int === null || ($min !== null && $int < $min) || ($max !== null && $int > $max)) {
            $problems[] = sprintf('%s must be a whole number%s; got %s.', $where, $this->range($min, $max), $this->describe($value));

            // Reported either way; a number out of range is still read as
            // the nearest one in range, for the surfaces that do not validate.
            return $int === null ? $min ?? 0 : max($min ?? $int, min($max ?? $int, $int));
        }

        return $int;
    }

    /**
     * A non-negative number: every weight and multiplier in the package is
     * one, and a negative one turns a penalty into a reward.
     *
     * @param  list<string>  $problems
     */
    private function number(string $where, mixed $value, array &$problems): float
    {
        $number = is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))
            ? (float) (is_string($value) ? trim($value) : $value)
            : null;

        if ($number === null || $number < 0 || is_nan($number) || is_infinite($number)) {
            $problems[] = sprintf('%s must be a number of 0 or more; got %s.', $where, $this->describe($value));

            return 0.0;
        }

        return $number;
    }

    /**
     * @param  list<string>  $problems
     */
    private function string(string $where, mixed $value, array &$problems): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $problems[] = sprintf('%s must be a non-empty string; got %s.', $where, $this->describe($value));

        return '';
    }

    /**
     * A list of strings; a single string is a list of one.
     *
     * @param  list<string>  $problems
     * @return list<string>
     */
    private function stringList(string $where, mixed $value, array &$problems): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            $problems[] = sprintf('%s must be a list of strings; got %s.', $where, $this->describe($value));

            return [];
        }

        $list = [];

        /** @var mixed $item */
        foreach ($value as $item) {
            if (! is_string($item)) {
                $problems[] = sprintf('%s must be a list of strings; it contains %s.', $where, $this->describe($item));

                continue;
            }

            if (trim($item) !== '') {
                $list[] = trim($item);
            }
        }

        return $list;
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $problems
     */
    private function enum(string $where, mixed $value, array $allowed, array &$problems): ?string
    {
        // Left empty, a severity or tier falls back to the rule's own default.
        if ($value === null || $value === '') {
            return null;
        }

        $normalised = is_string($value) ? mb_strtolower(trim($value)) : null;

        if ($normalised !== null && in_array($normalised, $allowed, true)) {
            return $normalised;
        }

        $problems[] = sprintf('%s must be one of %s; got %s.', $where, implode(', ', $allowed), $this->describe($value));

        return $allowed[0];
    }

    private function range(?int $min, ?int $max): string
    {
        return match (true) {
            $min !== null && $max !== null => sprintf(' from %d to %d', $min, $max),
            $min !== null => sprintf(' of %d or more', $min),
            default => '',
        };
    }

    /**
     * @param  list<string>  $candidates
     */
    private function suggest(string $key, array $candidates): string
    {
        $best = null;
        $distance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $candidateDistance = levenshtein(mb_strtolower($key), mb_strtolower($candidate));

            if ($candidateDistance < $distance) {
                $best = $candidate;
                $distance = $candidateDistance;
            }
        }

        return $best !== null && $distance <= max(2, intdiv(strlen($key), 3)) ? sprintf(' (did you mean %s?)', $best) : '';
    }

    private function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => sprintf("'%s'", $value),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => 'an array',
            default => get_debug_type($value),
        };
    }
}
