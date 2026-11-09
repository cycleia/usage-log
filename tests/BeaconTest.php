<?php
declare(strict_types=1);

namespace Cycleia\UsageLog\Tests;

use Cycleia\UsageLog\Beacon;
use Cycleia\UsageLog\Event;

final class BeaconTest extends DatabaseTestCase
{
    private function beacon(): Beacon
    {
        return new Beacon($this->recorder(), fn () => $this->now());
    }

    private function identity(): Event
    {
        return new Event(app: 'crmapp', userId: 9, userRole: 'client', orgId: 3, sessionId: 'jti-1', userAgent: 'Mozilla/5.0 (iPhone)');
    }

    public function testRecordsValidEventsWithTheServersIdentity(): void
    {
        $result = $this->beacon()->accept([
            'events' => [
                ['type' => 'search', 'surface' => 'crm.contacts.inside', 'text' => 'jane', 'at' => '2026-11-09T14:59:00Z', 'user_id' => 1, 'org_id' => 99],
                ['type' => 'view', 'surface' => 'crm.contacts.inside', 'entity_type' => 'Contacts', 'entity_id' => '123'],
                ['type' => 'action', 'surface' => 'crm.contacts.keyword', 'name' => 'export_csv'],
            ],
        ], $this->identity());

        $this->assertSame(['accepted' => 3, 'rejected' => 0], $result);
        $rows = $this->rows();
        foreach ($rows as $row) {
            $this->assertSame('beacon', $row['source']);
            $this->assertSame(9, (int)$row['user_id']);
            $this->assertSame(3, (int)$row['org_id']);
            $this->assertSame('phone', $row['device']);
        }
        $this->assertSame('jane', $rows[0]['search_text']);
        $this->assertSame('2026-11-09 14:59:00', $rows[0]['created_at']);
        $this->assertSame('Contacts', $rows[1]['entity_type']);
        $this->assertSame(123, (int)$rows[1]['entity_id']);
        $this->assertSame('beacon:export_csv', $rows[2]['route']);
    }

    public function testRejectsUnknownTypesSurfacesAndMalformedEvents(): void
    {
        $result = $this->beacon()->accept([
            'events' => [
                ['type' => 'login', 'surface' => 'crm.contacts.inside'],
                ['type' => 'search', 'surface' => 'made.up.surface', 'text' => 'x'],
                ['type' => 'search', 'surface' => 'crm.contacts.inside', 'text' => '   '],
                ['type' => 'view', 'surface' => 'crm.contacts.inside', 'entity_type' => 'Users', 'entity_id' => 1],
                ['type' => 'view', 'surface' => 'crm.contacts.inside', 'entity_type' => 'Contacts', 'entity_id' => -4],
                ['type' => 'action', 'surface' => 'crm.contacts.inside', 'name' => 'DROP TABLE'],
                'not an object',
            ],
        ], $this->identity());

        $this->assertSame(['accepted' => 0, 'rejected' => 7], $result);
        $this->assertCount(0, $this->rows());
    }

    public function testRejectsEventsTooOldOrInTheFuture(): void
    {
        $result = $this->beacon()->accept([
            'events' => [
                ['type' => 'search', 'surface' => 'crm.contacts.inside', 'text' => 'old', 'at' => '2026-10-01T00:00:00Z'],
                ['type' => 'search', 'surface' => 'crm.contacts.inside', 'text' => 'future', 'at' => '2026-11-10T00:00:00Z'],
                ['type' => 'search', 'surface' => 'crm.contacts.inside', 'text' => 'garbage', 'at' => 'not a date'],
            ],
        ], $this->identity());

        $this->assertSame(['accepted' => 0, 'rejected' => 3], $result);
    }

    public function testCapsTheBatchSize(): void
    {
        $events = array_fill(0, 60, ['type' => 'search', 'surface' => 'crm.contacts.inside', 'text' => 'x']);

        $result = $this->beacon()->accept(['events' => $events], $this->identity());

        $this->assertSame(['accepted' => 50, 'rejected' => 10], $result);
    }

    public function testIgnoresAPayloadWithoutEvents(): void
    {
        $this->assertSame(['accepted' => 0, 'rejected' => 0], $this->beacon()->accept('nonsense', $this->identity()));
        $this->assertSame(['accepted' => 0, 'rejected' => 0], $this->beacon()->accept(['events' => 'x'], $this->identity()));
    }
}
