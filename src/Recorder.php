<?php
declare(strict_types=1);

namespace Cycleia\UsageLog;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Writes one sanitised row per event. Never throws: a failed write is counted
 * and handed to the onFailure callback so the application can log it.
 */
final class Recorder
{
    public const COLUMNS = [
        'created_at', 'source', 'app', 'user_id', 'user_role', 'org_id', 'event_type',
        'surface', 'search_text', 'params', 'method', 'path', 'route', 'entity_type',
        'entity_id', 'status', 'duration_ms', 'session_key', 'device', 'user_agent',
    ];

    private readonly Sanitizer $sanitizer;
    private ?Writer $writer = null;
    private int $failures = 0;

    /**
     * @param PDO|Writer|Closure(): (PDO|Writer) $connection A PDO, a Writer,
     *        or a closure returning either (resolved on the first write, so no
     *        connection is opened for requests that are not logged).
     * @param (Closure(Throwable, ?Event): void)|null $onFailure
     * @param (Closure(): DateTimeImmutable)|null $clock For tests.
     */
    public function __construct(
        private readonly PDO|Writer|Closure $connection,
        private readonly Config $config = new Config(),
        private readonly ?Closure $onFailure = null,
        private readonly ?Closure $clock = null,
    ) {
        $this->sanitizer = new Sanitizer($config);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function sanitizer(): Sanitizer
    {
        return $this->sanitizer;
    }

    public function record(Event $event): bool
    {
        try {
            $this->insert($this->row($event));

            return true;
        } catch (Throwable $e) {
            $this->fail($e, $event);

            return false;
        }
    }

    /**
     * Counts a failure that happened around logging (for example in an
     * adapter's resolver) and reports it, without throwing.
     */
    public function fail(Throwable $error, ?Event $event = null): void
    {
        $this->failures++;
        if ($this->onFailure !== null) {
            try {
                ($this->onFailure)($error, $event);
            } catch (Throwable) {
                // The failure handler must not break the request either.
            }
        }
    }

    /**
     * Failed writes in this process.
     */
    public function failures(): int
    {
        return $this->failures;
    }

    /**
     * The row that would be written, after every rule is applied.
     *
     * @return array<string, scalar|null>
     */
    public function row(Event $event): array
    {
        $s = $this->sanitizer;
        $params = $s->params($event->route, $event->params);
        $when = $event->occurredAt !== null
            ? DateTimeImmutable::createFromInterface($event->occurredAt)
            : $this->now();

        return [
            'created_at' => $when->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'source' => $s->truncate($event->source, 10),
            'app' => $s->truncate($event->app, 20),
            'user_id' => $event->userId,
            'user_role' => $event->userRole === null ? null : $s->truncate($event->userRole, 20),
            'org_id' => $event->orgId,
            'event_type' => $s->truncate($event->eventType, 20),
            'surface' => $s->surface($event->surface),
            'search_text' => $s->text($event->searchText, $this->config->maxSearchText),
            'params' => $params === [] ? null : json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'method' => $event->method === null ? null : strtoupper($s->truncate($event->method, 8)),
            'path' => $s->path($event->path),
            'route' => $event->route === null ? null : $s->truncate($event->route, 120),
            'entity_type' => $s->entityType($event->entityType),
            'entity_id' => $event->entityId,
            'status' => $event->status,
            'duration_ms' => $event->durationMs,
            'session_key' => $s->sessionKey($event->sessionId),
            'device' => Device::detect($event->userAgent),
            'user_agent' => $s->text($event->userAgent, 255),
        ];
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private function insert(array $row): void
    {
        $this->writer()->insert($this->config->table, $row);
    }

    private function writer(): Writer
    {
        if ($this->writer === null) {
            $resolved = $this->connection instanceof Closure ? ($this->connection)() : $this->connection;
            $this->writer = match (true) {
                $resolved instanceof Writer => $resolved,
                $resolved instanceof PDO => new PdoWriter($resolved),
                default => throw new \RuntimeException('The connection must be a PDO or a Writer'),
            };
        }

        return $this->writer;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
