<?php
declare(strict_types=1);

namespace Cycleia\UsageLog\Tests;

use Cycleia\UsageLog\Event;
use Cycleia\UsageLog\Recorder;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class RecorderTest extends DatabaseTestCase
{
    public function testWritesOneSanitisedRow(): void
    {
        $ok = $this->recorder()->record(new Event(
            app: 'crm',
            eventType: 'search',
            userId: 7,
            userRole: 'client',
            orgId: 42,
            surface: 'crm.contacts.keyword',
            searchText: '  Joe Kim ',
            params: ['search' => 'Joe Kim', 'city' => 'Boston', 'password' => 'x', 'api_key' => 'k', 'other' => 'y'],
            method: 'get',
            path: '/contacts?search=Joe+Kim',
            route: 'Contacts::index',
            status: 200,
            durationMs: 35,
            sessionId: 'raw-session-id',
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        ));

        $this->assertTrue($ok);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('2026-11-09 15:00:00', $row['created_at']);
        $this->assertSame('server', $row['source']);
        $this->assertSame('crm', $row['app']);
        $this->assertSame(7, (int)$row['user_id']);
        $this->assertSame(42, (int)$row['org_id']);
        $this->assertSame('crm.contacts.keyword', $row['surface']);
        $this->assertSame('Joe Kim', $row['search_text']);
        $this->assertSame(['city' => 'Boston', 'search' => 'Joe Kim'], json_decode($row['params'], true));
        $this->assertSame('GET', $row['method']);
        $this->assertSame('/contacts', $row['path']);
        $this->assertSame('desktop', $row['device']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $row['session_key']);
    }

    public function testNoSecretReachesTheTable(): void
    {
        $this->recorder()->record(new Event(
            app: 'crm',
            params: ['search' => 'a', 'password' => 'hunter2', 'api_key' => 'sk_live_123'],
            path: '/reset/Zx81kLm20qPvB7nT5yR3wQ1',
            route: 'Contacts::index',
            sessionId: 'session-secret-value',
        ));

        $dump = json_encode($this->rows());
        $this->assertStringNotContainsString('hunter2', $dump);
        $this->assertStringNotContainsString('sk_live_123', $dump);
        $this->assertStringNotContainsString('session-secret-value', $dump);
        $this->assertStringNotContainsString('Zx81kLm20qPvB7nT5yR3wQ1', $dump);
    }

    public function testOccurredAtIsStoredInUtc(): void
    {
        $this->recorder()->record(new Event(
            app: 'crm',
            occurredAt: new DateTimeImmutable('2026-11-09 10:30:00', new DateTimeZone('America/New_York')),
        ));

        $this->assertSame('2026-11-09 15:30:00', $this->rows()[0]['created_at']);
    }

    public function testEmptyParamsAreStoredAsNull(): void
    {
        $this->recorder()->record(new Event(app: 'crm', params: ['nope' => 1]));

        $this->assertNull($this->rows()[0]['params']);
    }

    public function testAFailedWriteIsCountedReportedAndNeverThrown(): void
    {
        $reported = [];
        $recorder = $this->recorder(
            $this->config(['table' => 'missing_table']),
            function (Throwable $e, ?Event $event) use (&$reported): void {
                $reported[] = [$e::class, $event?->app];
            }
        );

        $ok = $recorder->record(new Event(app: 'crm'));

        $this->assertFalse($ok);
        $this->assertSame(1, $recorder->failures());
        $this->assertCount(1, $reported);
        $this->assertSame('crm', $reported[0][1]);
    }

    public function testAThrowingFailureHandlerIsContained(): void
    {
        $recorder = $this->recorder($this->config(['table' => 'missing_table']), function (): void {
            throw new \RuntimeException('handler broke');
        });

        $this->assertFalse($recorder->record(new Event(app: 'crm')));
        $this->assertSame(1, $recorder->failures());
    }

    public function testTheConnectionClosureIsResolvedOnlyOnFirstWrite(): void
    {
        $calls = 0;
        $recorder = new Recorder(function () use (&$calls): PDO {
            $calls++;

            return $this->pdo;
        }, $this->config());

        $this->assertSame(0, $calls);
        $recorder->record(new Event(app: 'crm'));
        $recorder->record(new Event(app: 'crm'));
        $this->assertSame(1, $calls);
        $this->assertCount(2, $this->rows());
    }

    public function testEventWithRejectsUnknownFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Event(app: 'crm'))->with(['body' => 'x']);
    }
}
