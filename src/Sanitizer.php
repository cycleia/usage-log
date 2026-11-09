<?php
declare(strict_types=1);

namespace Cycleia\UsageLog;

/**
 * The safety rules. Every value on its way to the table passes through here.
 */
final class Sanitizer
{
    public const UNKNOWN_SURFACE = 'unknown';
    public const REDACTED_SEGMENT = '{redacted}';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Keeps only allow-listed, non-secret parameters with scalar values (or
     * short lists of scalars), truncated. Sorted by name.
     *
     * @param array<mixed> $params
     * @return array<string, scalar|list<scalar>>
     */
    public function params(?string $route, array $params): array
    {
        $allowed = $this->config->allowedParams['*'] ?? [];
        if ($route !== null && isset($this->config->allowedParams[$route])) {
            $allowed = array_merge($allowed, $this->config->allowedParams[$route]);
        }
        if ($allowed === []) {
            return [];
        }

        $out = [];
        foreach ($params as $name => $value) {
            if (!is_string($name) || !in_array($name, $allowed, true) || $this->isSecretKey($name)) {
                continue;
            }
            $clean = $this->paramValue($value);
            if ($clean === null) {
                continue;
            }
            $out[$name] = $clean;
            if (count($out) >= $this->config->maxParams) {
                break;
            }
        }
        ksort($out);

        return $out;
    }

    public function isSecretKey(string $name): bool
    {
        return preg_match($this->config->secretKeyPattern, $name) === 1;
    }

    /**
     * A known surface, "unknown" for anything else, null when none was given.
     */
    public function surface(?string $surface): ?string
    {
        if ($surface === null || trim($surface) === '') {
            return null;
        }

        return in_array($surface, $this->config->surfaces, true) ? $surface : self::UNKNOWN_SURFACE;
    }

    public function isKnownSurface(?string $surface): bool
    {
        return $surface !== null && in_array($surface, $this->config->surfaces, true);
    }

    public function entityType(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        if ($this->config->entityTypes !== [] && !in_array($type, $this->config->entityTypes, true)) {
            return null;
        }

        return $this->truncate($type, 40);
    }

    /**
     * The path without query string or fragment, with any segment that looks
     * like a token (long, mixed letters and digits) replaced. Numeric ids are
     * kept.
     */
    public function path(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        $path = preg_split('/[?#]/', $path, 2)[0];
        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if ($this->looksLikeToken(rawurldecode($segment))) {
                $segments[$i] = self::REDACTED_SEGMENT;
            }
        }

        return $this->truncate(implode('/', $segments), 255);
    }

    /**
     * Trimmed, control characters removed, truncated; null when empty.
     */
    public function text(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
        if ($text === '') {
            return null;
        }

        return $this->truncate($text, $max);
    }

    /**
     * A 16-character hash that groups one sitting. Never the raw id.
     */
    public function sessionKey(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return substr(hash('sha256', $this->config->hashSalt . $raw), 0, 16);
    }

    public function truncate(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }

    /**
     * @return scalar|list<scalar>|null
     */
    private function paramValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->text($value, $this->config->maxParamValue);
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }
        if (is_array($value)) {
            $list = [];
            foreach ($value as $item) {
                if (is_array($item) || is_object($item)) {
                    continue;
                }
                $clean = $this->paramValue($item);
                if ($clean !== null) {
                    $list[] = $clean;
                }
                if (count($list) >= $this->config->maxListItems) {
                    break;
                }
            }

            return $list === [] ? null : $list;
        }

        return null;
    }

    private function looksLikeToken(string $segment): bool
    {
        return strlen($segment) >= 20
            && preg_match('/^[A-Za-z0-9_\-.=~+\/]+$/', $segment) === 1
            && preg_match('/\d/', $segment) === 1
            && preg_match('/[A-Za-z]/', $segment) === 1;
    }
}
