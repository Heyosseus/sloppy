<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * Something a class does that an architecture may reserve for some roles:
 * talk to the database, call out over HTTP, read the request.
 */
enum Capability: string
{
    case DatabaseRead = 'db.read';
    case DatabaseWrite = 'db.write';
    case Http = 'http';
    case Dispatch = 'dispatch';
    case Request = 'request';
    case Env = 'env';
    case View = 'view';
    case Container = 'container';

    /**
     * Read a policy's capability list. `db` and `db.*` stand for both
     * database capabilities.
     *
     * @return list<self>
     */
    public static function parse(string $name): array
    {
        $name = mb_strtolower(trim($name));

        if (in_array($name, ['db', 'db.*'], true)) {
            return [self::DatabaseRead, self::DatabaseWrite];
        }

        $capability = self::tryFrom($name);

        return $capability instanceof self ? [$capability] : [];
    }

    /**
     * What doing it looks like, for a finding: "queries the database".
     */
    public function verb(): string
    {
        return match ($this) {
            self::DatabaseRead => 'queries the database',
            self::DatabaseWrite => 'writes to the database',
            self::Http => 'makes an outbound HTTP request',
            self::Dispatch => 'dispatches a job, event, notification or mail',
            self::Request => 'reads the HTTP request',
            self::Env => 'reads the environment',
            self::View => 'renders a view',
            self::Container => 'resolves from the service container',
        };
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return ['db', ...array_map(static fn (self $capability): string => $capability->value, self::cases())];
    }
}
