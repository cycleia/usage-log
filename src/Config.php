<?php
declare(strict_types=1);

namespace Cycleia\UsageLog;

use InvalidArgumentException;

/**
 * What one application allows into its usage table.
 *
 * Everything that is not listed here is dropped: parameters not on the
 * route's allow-list, search surfaces not on the surface list (stored as
 * "unknown"), entity types not on the entity list.
 */
final class Config
{
    /**
     * Parameter names that are never stored, even when allow-listed.
     * Deliberately broad: a dropped harmless parameter costs nothing, a stored
     * credential is an incident.
     */
    public const SECRET_KEYS = '/pass(word|wd|phrase)?|secret|token|api[_-]?key|auth|credential|cookie|session|signature|otp|code_verifier|private|ssn/i';

    /**
     * @param string $table Table name; letters, digits and underscores only.
     * @param array<string, list<string>> $allowedParams Route => parameter names to keep.
     *        The key '*' applies to every route.
     * @param list<string> $surfaces Known search surfaces, e.g. "app.screen.box".
     * @param list<string> $entityTypes Known record types for views. Empty = any.
     * @param string $hashSalt Mixed into the session key hash.
     */
    public function __construct(
        public readonly string $table = 'usage_events',
        public readonly array $allowedParams = [],
        public readonly array $surfaces = [],
        public readonly array $entityTypes = [],
        public readonly string $secretKeyPattern = self::SECRET_KEYS,
        public readonly string $hashSalt = '',
        public readonly int $maxSearchText = 500,
        public readonly int $maxParamValue = 200,
        public readonly int $maxParams = 30,
        public readonly int $maxListItems = 20,
        public readonly int $beaconMaxEvents = 50,
        public readonly int $beaconMaxAgeSeconds = 604800,
        public readonly int $beaconMaxFutureSeconds = 300,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $table)) {
            throw new InvalidArgumentException('Invalid table name: ' . $table);
        }
        if (@preg_match($secretKeyPattern, '') === false) {
            throw new InvalidArgumentException('Invalid secret key pattern');
        }
    }
}
