<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * Every dependency from classes in one role on classes in another, counted.
 */
final readonly class RoleEdge
{
    /**
     * @param  int  $count  Class-to-class dependencies along this edge.
     * @param  int  $forbidden  How many of them the source role's policy forbids.
     * @param  string|null  $example  One forbidden dependency, `OrderController -> OrderRepository`.
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $count,
        public int $forbidden = 0,
        public ?string $example = null,
    ) {}

    public function isForbidden(): bool
    {
        return $this->forbidden > 0;
    }

    public function label(): string
    {
        return $this->forbidden > 0 ? sprintf('%d (%d forbidden)', $this->count, $this->forbidden) : (string) $this->count;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'dependencies' => $this->count,
            'forbidden' => $this->forbidden,
            'example' => $this->example,
        ];
    }
}
