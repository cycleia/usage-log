<?php
declare(strict_types=1);

namespace Cycleia\UsageLog;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Accepts a batch of events reported by the browser, for what never reaches
 * the server (searching a list held in the browser, views from a cache).
 *
 * The browser is not trusted: the user, role, org, session and app come from
 * the server's own identity event, never from the payload, and every event must
 * name a known type and a known surface.
 *
 * Payload: {"events": [
 *   {"type": "search", "surface": "app.screen.box", "text": "jane doe", "at": "2026-11-09T14:03:00Z"},
 *   {"type": "view", "surface": "app.contacts.cache", "entity_type": "Contacts", "entity_id": 123},
 *   {"type": "action", "surface": "app.contacts.list", "name": "export_csv"}
 * ]}
 */
final class Beacon
{
    public const TYPES = ['search', 'view', 'action'];

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock For tests.
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * @param Event $identity Built by the server from the authenticated
     *        request: app, userId, userRole, orgId, sessionId, userAgent.
     * @return array{accepted: int, rejected: int}
     */
    public function accept(mixed $payload, Event $identity): array
    {
        $config = $this->recorder->config();
        $events = is_array($payload) && isset($payload['events']) && is_array($payload['events'])
            ? array_values($payload['events'])
            : [];
        $accepted = 0;
        $rejected = 0;

        foreach ($events as $i => $item) {
            if ($i >= $config->beaconMaxEvents) {
                $rejected += count($events) - $i;
                break;
            }
            $event = is_array($item) ? $this->event($item, $identity) : null;
            if ($event !== null && $this->recorder->record($event)) {
                $accepted++;
            } else {
                $rejected++;
            }
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * @param array<mixed> $item
     */
    private function event(array $item, Event $identity): ?Event
    {
        $sanitizer = $this->recorder->sanitizer();
        $type = $item['type'] ?? null;
        $surface = $item['surface'] ?? null;
        if (!in_array($type, self::TYPES, true) || !is_string($surface) || !$sanitizer->isKnownSurface($surface)) {
            return null;
        }
        $at = $this->time($item['at'] ?? null);
        if ($at === null) {
            return null;
        }

        $base = $identity->with([
            'eventType' => $type,
            'source' => Event::SOURCE_BEACON,
            'surface' => $surface,
            'occurredAt' => $at,
            'params' => [],
            'method' => null,
            'path' => null,
            'route' => null,
            'status' => null,
            'durationMs' => null,
            'searchText' => null,
            'entityType' => null,
            'entityId' => null,
        ]);

        return match ($type) {
            'search' => $this->search($item, $base),
            'view' => $this->view($item, $base),
            'action' => $this->action($item, $base),
        };
    }

    /**
     * @param array<mixed> $item
     */
    private function search(array $item, Event $base): ?Event
    {
        $text = $item['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return null;
        }

        return $base->with(['searchText' => $text]);
    }

    /**
     * @param array<mixed> $item
     */
    private function view(array $item, Event $base): ?Event
    {
        $entityType = $item['entity_type'] ?? null;
        $entityId = $item['entity_id'] ?? null;
        if (is_string($entityId) && ctype_digit($entityId)) {
            $entityId = (int)$entityId;
        }
        if (!is_string($entityType) || $this->recorder->sanitizer()->entityType($entityType) === null
            || !is_int($entityId) || $entityId <= 0) {
            return null;
        }

        return $base->with(['entityType' => $entityType, 'entityId' => $entityId]);
    }

    /**
     * @param array<mixed> $item
     */
    private function action(array $item, Event $base): ?Event
    {
        $name = $item['name'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z0-9_.]{1,60}$/', $name) !== 1) {
            return null;
        }

        return $base->with(['route' => 'beacon:' . $name]);
    }

    private function time(mixed $value): ?DateTimeImmutable
    {
        $now = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($value === null) {
            return $now;
        }
        if (!is_string($value) || strlen($value) > 40) {
            return null;
        }
        try {
            $at = new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
        $config = $this->recorder->config();
        $age = $now->getTimestamp() - $at->getTimestamp();
        if ($age > $config->beaconMaxAgeSeconds || -$age > $config->beaconMaxFutureSeconds) {
            return null;
        }

        return $at;
    }
}
