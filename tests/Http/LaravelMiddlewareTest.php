<?php
declare(strict_types=1);

namespace Cycleia\UsageLog\Tests\Http;

use Cycleia\UsageLog\Event;
use Cycleia\UsageLog\Http\LaravelMiddleware;
use Cycleia\UsageLog\Tests\DatabaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

final class LaravelMiddlewareTest extends DatabaseTestCase
{
    private function request(): Request
    {
        $request = Request::create('/v1/contacts/search?name=jane&token=abc', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)']);

        return $request;
    }

    private function middleware(?\Closure $resolve = null): LaravelMiddleware
    {
        return new LaravelMiddleware($this->recorder($this->config([
            'allowedParams' => ['/contacts/search' => ['name', 'token']],
            'surfaces' => ['crmapp.contacts.outside'],
        ])), 'crmapp', $resolve ?? fn (Event $base, Request $r): Event => $base->with([
            'userId' => 11,
            'route' => '/contacts/search',
            'eventType' => 'search',
            'surface' => 'crmapp.contacts.outside',
            'searchText' => $r->query('name'),
        ]));
    }

    public function testRecordsTheRequest(): void
    {
        $response = $this->middleware()->handle($this->request(), fn () => new Response('ok', 200));

        $this->assertSame('ok', $response->getContent());
        $row = $this->rows()[0];
        $this->assertSame('crmapp', $row['app']);
        $this->assertSame('GET', $row['method']);
        $this->assertSame('/v1/contacts/search', $row['path']);
        $this->assertSame('jane', $row['search_text']);
        $this->assertSame(['name' => 'jane'], json_decode($row['params'], true));
        $this->assertSame('phone', $row['device']);
        $this->assertSame(200, (int)$row['status']);
    }

    public function testAThrowingRequestIsRecordedAs500AndRethrown(): void
    {
        try {
            $this->middleware()->handle($this->request(), fn () => throw new RuntimeException('boom'));
            $this->fail('expected the exception to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(500, (int)$this->rows()[0]['status']);
    }

    public function testABrokenResolverNeverChangesTheResponse(): void
    {
        $response = $this->middleware(fn () => throw new RuntimeException('bug'))
            ->handle($this->request(), fn () => new Response('ok', 201));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertCount(0, $this->rows());
    }
}
