<?php
declare(strict_types=1);

namespace Cycleia\UsageLog;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * The facts of one request or browser event, before sanitising.
 *
 * Raw values go in; the Recorder applies the Config's rules on the way to the
 * table. `sessionId` is the raw token id or session id: it is hashed, never
 * stored. There is deliberately no field for a request body.
 */
final class Event
{
    public const SOURCE_SERVER = 'server';
    public const SOURCE_BEACON = 'beacon';

    /**
     * @param array<string, mixed> $params Query parameters (never the body).
     */
    public function __construct(
        public readonly string $app,
        public readonly string $eventType = 'request',
        public readonly string $source = self::SOURCE_SERVER,
        public readonly ?int $userId = null,
        public readonly ?string $userRole = null,
        public readonly ?int $orgId = null,
        public readonly ?string $surface = null,
        public readonly ?string $searchText = null,
        public readonly array $params = [],
        public readonly ?string $method = null,
        public readonly ?string $path = null,
        public readonly ?string $route = null,
        public readonly ?string $entityType = null,
        public readonly ?int $entityId = null,
        public readonly ?int $status = null,
        public readonly ?int $durationMs = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $userAgent = null,
        public readonly ?DateTimeInterface $occurredAt = null,
    ) {
    }

    /**
     * A copy with some fields changed: $event->with(['userId' => 5]).
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $fields = get_object_vars($this);
        foreach ($changes as $name => $value) {
            if (!array_key_exists($name, $fields)) {
                throw new InvalidArgumentException('Unknown event field: ' . $name);
            }
            $fields[$name] = $value;
        }

        return new self(...$fields);
    }
}
