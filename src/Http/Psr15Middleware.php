<?php
declare(strict_types=1);

namespace Cycleia\UsageLog\Http;

use Closure;
use Cycleia\UsageLog\Event;
use Cycleia\UsageLog\Recorder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * PSR-15 middleware (CakePHP 5, Slim, Mezzio, ...).
 *
 * After the request is handled it builds a base Event from the request facts
 * (method, path, query parameters, status, duration, user agent) and passes it
 * to the application's resolver, which adds what only the application knows
 * (user, role, org, route name, surface, record viewed) or returns null to skip
 * the request. The request body is never read.
 *
 * A request that throws is recorded with status 500 and the exception is
 * rethrown. Nothing the logging does can change the response.
 */
final class Psr15Middleware implements MiddlewareInterface
{
    /**
     * @param Closure(Event, ServerRequestInterface, ?ResponseInterface): ?Event $resolve
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly string $app,
        private readonly Closure $resolve,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = hrtime(true);
        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $this->log($request, null, 500, $start);
            throw $e;
        }
        $this->log($request, $response, $response->getStatusCode(), $start);

        return $response;
    }

    private function log(ServerRequestInterface $request, ?ResponseInterface $response, int $status, int|float $start): void
    {
        $base = null;
        try {
            $base = new Event(
                app: $this->app,
                params: $request->getQueryParams(),
                method: $request->getMethod(),
                path: $request->getUri()->getPath(),
                status: $status,
                durationMs: (int)round((hrtime(true) - $start) / 1_000_000),
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
            );
            $event = ($this->resolve)($base, $request, $response);
            if ($event !== null) {
                $this->recorder->record($event);
            }
        } catch (Throwable $e) {
            $this->recorder->fail($e, $base);
        }
    }
}
