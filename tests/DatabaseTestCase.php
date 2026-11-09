<?php
declare(strict_types=1);

namespace Cycleia\UsageLog\Tests;

use Cycleia\UsageLog\Config;
use Cycleia\UsageLog\Recorder;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec((string)file_get_contents(__DIR__ . '/../sql/sqlite.sql'));
    }

    protected function config(array $overrides = []): Config
    {
        return new Config(...array_merge([
            'allowedParams' => [
                '*' => ['page', 'sort'],
                'Contacts::index' => ['search', 'city', 'tags', 'api_key'],
            ],
            'surfaces' => ['crm.contacts.keyword', 'crm.contacts.inside'],
            'entityTypes' => ['Contacts', 'Events'],
            'hashSalt' => 'test-salt',
        ], $overrides));
    }

    protected function recorder(?Config $config = null, ?\Closure $onFailure = null): Recorder
    {
        return new Recorder($this->pdo, $config ?? $this->config(), $onFailure, fn () => $this->now());
    }

    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-09 15:00:00', new DateTimeZone('UTC'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function rows(): array
    {
        return $this->pdo->query('SELECT * FROM usage_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
}
