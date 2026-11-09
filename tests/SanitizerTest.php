<?php
declare(strict_types=1);

namespace Cycleia\UsageLog\Tests;

use Cycleia\UsageLog\Config;
use Cycleia\UsageLog\Sanitizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase
{
    private function sanitizer(array $config = []): Sanitizer
    {
        return new Sanitizer(new Config(...array_merge([
            'allowedParams' => ['*' => ['page'], 'r' => ['search', 'tags', 'password', 'access_token', 'filter']],
            'surfaces' => ['a.b.c'],
            'entityTypes' => ['Contacts'],
            'maxParamValue' => 10,
            'maxListItems' => 3,
            'hashSalt' => 's',
        ], $config)));
    }

    public function testKeepsOnlyAllowListedParametersForTheRoute(): void
    {
        $params = $this->sanitizer()->params('r', ['search' => 'kim', 'page' => 2, 'city' => 'Boston']);

        $this->assertSame(['page' => 2, 'search' => 'kim'], $params);
    }

    public function testAnotherRouteGetsOnlyTheGlobalList(): void
    {
        $this->assertSame(['page' => 1], $this->sanitizer()->params('other', ['search' => 'kim', 'page' => 1]));
    }

    public function testNoAllowListMeansNoParameters(): void
    {
        $sanitizer = new Sanitizer(new Config());

        $this->assertSame([], $sanitizer->params('r', ['search' => 'kim']));
    }

    public function testSecretLookingKeysAreDroppedEvenWhenAllowListed(): void
    {
        $params = $this->sanitizer()->params('r', ['password' => 'hunter2', 'access_token' => 'abc', 'search' => 'x']);

        $this->assertSame(['search' => 'x'], $params);
    }

    public function testValuesAreTruncatedAndListsCapped(): void
    {
        $params = $this->sanitizer()->params('r', [
            'search' => 'abcdefghijklmnop',
            'tags' => ['a', 'b', ['nested'], 'c', 'd'],
            'filter' => ['deep' => ['x']],
        ]);

        $this->assertSame(['search' => 'abcdefghij', 'tags' => ['a', 'b', 'c']], $params);
    }

    public function testSurfaceNotOnTheListIsStoredAsUnknown(): void
    {
        $s = $this->sanitizer();

        $this->assertSame('a.b.c', $s->surface('a.b.c'));
        $this->assertSame('unknown', $s->surface('made.up'));
        $this->assertNull($s->surface(''));
        $this->assertNull($s->surface(null));
    }

    public function testEntityTypeMustBeKnownWhenAListIsGiven(): void
    {
        $this->assertSame('Contacts', $this->sanitizer()->entityType('Contacts'));
        $this->assertNull($this->sanitizer()->entityType('Users'));
        $this->assertSame('Users', $this->sanitizer(['entityTypes' => []])->entityType('Users'));
    }

    public function testPathDropsQueryAndRedactsTokenSegmentsButKeepsIds(): void
    {
        $s = $this->sanitizer();

        $this->assertSame('/contacts/view/12345', $s->path('/contacts/view/12345?search=x#top'));
        $this->assertSame('/users/reset/{redacted}', $s->path('/users/reset/a8Fk2LpQ9zXc7Vb3Nm4Rt6Yw'));
        $this->assertSame('/files/{redacted}', $s->path('/files/550e8400-e29b-41d4-a716-446655440000'));
        $this->assertSame('/contacts/autocomplete.json', $s->path('/contacts/autocomplete.json'));
    }

    public function testTextIsTrimmedAndStrippedOfControlCharacters(): void
    {
        $this->assertSame('joe kim', $this->sanitizer()->text("  joe\x00kim \n", 50));
        $this->assertSame('a b', $this->sanitizer()->text("a\tb", 50));
        $this->assertNull($this->sanitizer()->text('   ', 50));
    }

    public function testSessionKeyIsAShortHashNeverTheRawValue(): void
    {
        $key = $this->sanitizer()->sessionKey('jti-123');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $key);
        $this->assertStringNotContainsString('jti-123', $key);
        $this->assertSame($key, $this->sanitizer()->sessionKey('jti-123'));
        $this->assertNotSame($key, $this->sanitizer(['hashSalt' => 'other'])->sessionKey('jti-123'));
        $this->assertNull($this->sanitizer()->sessionKey(null));
    }

    public function testConfigRejectsUnsafeTableNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Config(table: 'usage`; DROP TABLE users; --');
    }
}
