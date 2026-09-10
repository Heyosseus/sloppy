<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Baseline;

use Heyosseus\Sloppy\Analysis\Finding;

/**
 * One accepted finding, recorded so it stops failing the build.
 *
 * The identity is a hash of rule, file and the rule's own fingerprint -- never
 * the line number. Adding an import at the top of a file must not turn every
 * baselined finding in it back into a new one.
 */
final readonly class BaselineEntry
{
    public function __construct(
        public string $id,
        public string $ruleId,
        public string $file,
        public string $fingerprint,
        public int $count = 1,
        public string $message = '',
    ) {}

    public static function fromFinding(Finding $finding, int $count = 1): self
    {
        return new self(
            id: $finding->identity(),
            ruleId: $finding->ruleId,
            file: $finding->location->relativePath,
            fingerprint: $finding->fingerprint,
            count: $count,
            message: $finding->message,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['id', 'rule', 'file', 'fingerprint'] as $required) {
            if (! isset($data[$required]) || ! is_string($data[$required])) {
                return null;
            }
        }

        /** @var string $id */
        $id = $data['id'];
        /** @var string $rule */
        $rule = $data['rule'];
        /** @var string $file */
        $file = $data['file'];
        /** @var string $fingerprint */
        $fingerprint = $data['fingerprint'];

        $count = $data['count'] ?? 1;
        $message = $data['message'] ?? '';

        return new self(
            id: $id,
            ruleId: $rule,
            file: $file,
            fingerprint: $fingerprint,
            count: is_int($count) && $count > 0 ? $count : 1,
            message: is_string($message) ? $message : '',
        );
    }

    public function withCount(int $count): self
    {
        return new self($this->id, $this->ruleId, $this->file, $this->fingerprint, $count, $this->message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'rule' => $this->ruleId,
            'file' => $this->file,
            'fingerprint' => $this->fingerprint,
            'count' => $this->count,
            'message' => $this->message,
        ];
    }
}
