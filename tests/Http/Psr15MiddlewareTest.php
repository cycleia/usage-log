<?php
declare(strict_types=1);

namespace Cycleia\UsageLog\Tests\Http;

use Cycleia\UsageLog\Event;
use Cycleia\UsageLog\Http\Psr15Middleware;
use Cycleia\UsageLog\Tests\DatabaseTestCase;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class Psr15MiddlewareTest extends DatabaseTestCase
{
    private function handler(?callable $fn = null): RequestHandlerInterface
    {
        return new class ($fn) implements RequestHandlerInterface {
            public function __construct(private $fn)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->fn !== null ? ($this->fn)($request) : new Response(200, [], 'ok');
            }
        };
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequest('POST', 'https://crm.example.test/contacts?search=kim&secret=s3', ['User-Agent' => 'Mozilla/5.0 (Macintosh)']))
            ->withQueryParams(['search' => 'kim', 'secret' => 's3'])
            ->withParsedBody(['password' => 'hunter2']);
    }

    private function resolver(): \Closure
    {
        return fn (Event $base, ServerRequestInterface $r, ?ResponseInterface $res): Event => $base->with([
            'userId' => 5,
            'route' => 'Contacts::index',
            'eventType' => 'search',
            'surface' => 'crm.contacts.keyword',
            'searchText' => $r->getQueryParams()['search'] ?? null,
        ]);
    }

    public function testRecordsTheRequestWithoutItsBody(): void
    {
        $middleware = new Psr15Middleware($this->recorder(), 'crm', $this->resolver());

        $response = $middleware->process($this->request(), $this->handler());

        $this->assertSame('ok', (string)$response->getBody());
        $row = $this->rows()[0];
        $this->assertSame('crm', $row['app']);
        $this->assertSame('POST', $row['method']);
        $this->assertSame('/contacts', $row['path']);
        $this->assertSame(200, (int)$row['status']);
        $this->assertSame('kim', $row['search_text']);
        $this->assertSame(['search' => 'kim'], json_decode($row['params'], true));
        $this->assertGreaterThanOrEqual(0, (int)$row['duration_ms']);
        $this->assertStringNotContainsString('hunter2', json_encode($this->rows()));
        $this->assertStringNotContainsString('s3', (string)$row['params']);
    }

    public function testAThrowingRequestIsRecordedAs500AndRethrown(): void
    {
        $middleware = new Psr15Middleware($this->recorder(), 'crm', $this->resolver());

        try {
            $middleware->process($this->request(), $this->handler(fn () => throw new RuntimeException('boom')));
            $this->fail('expected the exception to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(500, (int)$this->rows()[0]['status']);
    }

    public function testResolverReturningNullSkipsTheRequest(): void
    {
        $middleware = new Psr15Middleware($this->recorder(), 'crm', fn () => null);

        $middleware->process($this->request(), $this->handler());

        $this->assertCount(0, $this->rows());
    }

    public function testABrokenResolverNeverChangesTheResponse(): void
    {
        $recorder = $this->recorder();
        $middleware = new Psr15Middleware($recorder, 'crm', fn () => throw new RuntimeException('resolver bug'));

        $response = $middleware->process($this->request(), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $recorder->failures());
        $this->assertCount(0, $this->rows());
    }
}
