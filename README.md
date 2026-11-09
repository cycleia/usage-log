# usage-log

Framework-neutral usage logging for PHP applications: one database row per request or browser
event, with the rules that keep credentials and personal data out of it built in.

It answers questions like who uses which feature, how often, what people search for and in which
search box, and when the application is busy. It is not an audit log (what changed) and not an
error log.

- PHP 8.2+, PDO (MySQL, MariaDB, SQLite, PostgreSQL with your own table definition)
- A PSR-15 middleware (CakePHP 5, Slim, Mezzio) and a Laravel/Lumen middleware
- A validator for events reported by the browser, for what never reaches the server
- No dependencies beyond PDO; the framework packages are optional

## Install

```bash
composer require cycleia/usage-log
```

Create the table from `sql/mysql.sql` (or `sql/sqlite.sql`), under any name.

## The safety rules

These are enforced by the package, so an application cannot get them wrong:

- **Request bodies are never stored.** The adapters read only the query string.
- **Parameters are allow-listed per route.** Anything not listed is dropped. Keys that look
  secret (`password`, `token`, `secret`, `api_key`, `auth`, `session`, …) are dropped even when
  listed.
- **Paths lose their query string**, and any segment that looks like a token (20+ characters
  mixing letters and digits, such as a password-reset code or a UUID) becomes `{redacted}`.
  Numeric ids are kept.
- **Search surfaces are a closed list.** A search records where it was typed (for example
  `crm.contacts.keyword`); any value not on the list is stored as `unknown`, so gaps show up
  instead of polluting the data.
- **Session keys are hashes.** Pass the raw session or token id; only a 16-character salted
  SHA-256 prefix is stored.
- **Lengths are capped** on every text column.
- **Logging never breaks a request.** A failed write is counted and passed to your `onFailure`
  callback; the response is unchanged. A request that throws is recorded with status 500 and
  the exception is rethrown.
- **Browser events are not trusted.** The user, organisation and session come from the server,
  never from the payload; unknown event types and surfaces are rejected, as are timestamps older
  than 7 days or more than 5 minutes ahead.

## Configure

```php
use Cycleia\UsageLog\Config;
use Cycleia\UsageLog\Recorder;

$config = new Config(
    table: 'usage_events',
    allowedParams: [
        '*' => ['page', 'sort', 'direction'],          // every route
        'Contacts::index' => ['search', 'city', 'type'],
    ],
    surfaces: ['crm.contacts.keyword', 'crm.contacts.picker'],
    entityTypes: ['Contacts', 'Events'],
    hashSalt: getenv('USAGE_LOG_SALT') ?: '',
);

$recorder = new Recorder(
    fn () => $pdo,                                      // resolved on first write
    $config,
    fn (Throwable $e) => error_log('usage-log: ' . $e->getMessage()),
);
```

## CakePHP 5 (PSR-15)

```php
// src/Application.php, in middleware()
$middlewareQueue->add(new \Cycleia\UsageLog\Http\Psr15Middleware(
    $recorder,
    'admin',
    function (Event $base, ServerRequestInterface $request, ?ResponseInterface $response): ?Event {
        $params = $request->getAttribute('params');      // routing result
        $user = $request->getAttribute('identity');
        if ($params === null || $params['controller'] === 'Health') {
            return null;                                 // skip
        }

        return $base->with([
            'route' => $params['controller'] . '::' . $params['action'],
            'userId' => $user?->getIdentifier(),
            'userRole' => $user?->get('role'),
            'sessionId' => $request->getSession()->id(),
        ]);
    },
));
```

Add it after routing and authentication so the resolver can see both.

## Laravel / Lumen

```php
use Cycleia\UsageLog\Http\LaravelMiddleware;

$app->singleton(LaravelMiddleware::class, fn () => new LaravelMiddleware(
    $recorder,
    'clientapp',
    fn (Event $base, Request $request, $response): ?Event => $base->with([
        'userId' => $request->user()?->id,
        'userRole' => $request->user()?->role,
        'route' => 'pattern or name of the matched route',
    ]),
));
$app->routeMiddleware(['usage' => LaravelMiddleware::class]);
```

## Events the server knows directly

```php
$recorder->record(new Event(
    app: 'admin',
    eventType: 'login',
    userId: $user->id,
    userRole: $user->role,
));
```

## Browser events

For searches and views that happen entirely in the browser:

```php
use Cycleia\UsageLog\Beacon;

$result = (new Beacon($recorder))->accept(
    json_decode($request->getContent(), true),
    new Event(app: 'clientapp', userId: $user->id, userRole: $user->role, orgId: $user->org_id,
              sessionId: $tokenId, userAgent: $request->userAgent()),
);
// ['accepted' => 3, 'rejected' => 0]
```

Payload:

```json
{"events": [
  {"type": "search", "surface": "app.contacts.inside", "text": "jane doe", "at": "2026-11-09T14:03:00Z"},
  {"type": "view", "surface": "app.contacts.cache", "entity_type": "Contacts", "entity_id": 123},
  {"type": "action", "surface": "app.contacts.list", "name": "export_csv"}
]}
```

Send search text after a pause in typing, not on every keystroke.

## Columns

`created_at` (UTC), `source` (`server` or `beacon`), `app`, `user_id`, `user_role` (at the time),
`org_id`, `event_type`, `surface`, `search_text`, `params` (JSON), `method`, `path`, `route`,
`entity_type`, `entity_id`, `status`, `duration_ms`, `session_key`, `device`
(`desktop`/`phone`/`tablet`/`bot`), `user_agent`.

The package only writes. Reporting, rollups and retention belong to the application.

## Development

```bash
composer update
vendor/bin/phpunit
```

## Licence

MIT
