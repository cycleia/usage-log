<?php
declare(strict_types=1);

namespace Cycleia\UsageLog;

/**
 * A coarse device class from the user agent: desktop, phone, tablet or bot.
 */
final class Device
{
    public static function detect(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }
        if (preg_match('/bot|crawl|spider|slurp|HealthChecker|curl\/|wget|python-|httpx|Go-http-client|okhttp|Java\//i', $userAgent)) {
            return 'bot';
        }
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|Android(?!.*Mobile)/i', $userAgent)) {
            return 'tablet';
        }
        if (preg_match('/Mobi|iPhone|iPod|Android|Windows Phone/i', $userAgent)) {
            return 'phone';
        }

        return 'desktop';
    }
}
