<?php
declare(strict_types=1);

namespace Cycleia\UsageLog\Http;

use Closure;
use Cycleia\UsageLog\Event;
use Cycleia\UsageLog\Recorder;
use Illuminate\Http\Request;
use Throwable;

/**
 * Laravel / Lumen middleware. Same behaviour as Psr15Middleware: a base Event
 * from the request facts, completed or skipped by the application's resolver,
 * the body never read, a thrown request recorded as 500 and rethrown.
 *
 * Register it in the container with its dependencies, e.g. in Lumen:
 *
 *   $app->singleton(LaravelMiddleware::class, fn () => new LaravelMiddleware($recorder, 'clientapp', $resolve));
 *   $app->routeMiddleware(['usage' => LaravelMiddleware::class]);
 */
final class LaravelMiddleware
{
    /**
     * @param Closure(Event, Request, mixed): ?Event $resolve Receives the base
     *        event, the request and the response (null if the request threw).
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly string $app,
        private readonly Closure $resolve,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $start = hrtime(true);
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->log($request, null, 500, $start);
            throw $e;
        }
        $status = is_object($response) && method_exists($response, 'getStatusCode') ? (int)$response->getStatusCode() : 200;
        $this->log($request, $response, $status, $start);

        return $response;
    }

    private function log(Request $request, mixed $response, int $status, int|float $start): void
    {
        $base = null;
        try {
            $base = new Event(
                app: $this->app,
                params: $request->query->all(),
                method: $request->getMethod(),
                path: '/' . ltrim($request->path(), '/'),
                status: $status,
                durationMs: (int)round((hrtime(true) - $start) / 1_000_000),
                userAgent: $request->userAgent(),
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
