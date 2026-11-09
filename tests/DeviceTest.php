<?php
declare(strict_types=1);

namespace Cycleia\UsageLog\Tests;

use Cycleia\UsageLog\Device;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceTest extends TestCase
{
    public static function agents(): array
    {
        return [
            'mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15', 'desktop'],
            'windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36', 'desktop'],
            'iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile/15E148', 'phone'],
            'android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/130.0 Mobile Safari/537.36', 'phone'],
            'android tablet' => ['Mozilla/5.0 (Linux; Android 14; SM-X710) Chrome/130.0 Safari/537.36', 'tablet'],
            'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)', 'tablet'],
            'health check' => ['ELB-HealthChecker/2.0', 'bot'],
            'crawler' => ['Mozilla/5.0 (compatible; Googlebot/2.1)', 'bot'],
            'script' => ['python-httpx/0.27', 'bot'],
        ];
    }

    #[DataProvider('agents')]
    public function testDetects(string $agent, string $expected): void
    {
        $this->assertSame($expected, Device::detect($agent));
    }

    public function testEmptyAgentIsNull(): void
    {
        $this->assertNull(Device::detect(null));
        $this->assertNull(Device::detect(' '));
    }
}
