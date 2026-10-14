<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commvault\Http;

use App\Commvault\Exception\AuthenticationException;
use App\Commvault\Exception\ReadOnlyViolationException;
use App\Commvault\Http\HttpCommvaultClient;
use App\Commvault\Http\LoginTokenProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpCommvaultClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<mixed>}> */
    private array $requests = [];

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): HttpCommvaultClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 599]);
        }, 'https://cs.example.local/commandcenter/api/');

        return new HttpCommvaultClient($http, new LoginTokenProvider($http, new NullLogger(), 'api_sa', 's3cret'), new NullLogger());
    }

    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    public function testLoginThenAuthenticatedGet(): void
    {
        $client = $this->client([
            self::json(['token' => 'QSDK abc', 'userName' => 'api_sa']),
            self::json(['tapeList' => []]),
        ]);

        $response = $client->get('/V4/Storage/Tape', ['x' => 1]);

        self::assertSame(['tapeList' => []], $response->toArray());
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://cs.example.local/commandcenter/api/Login', $this->requests[0]['url']);
        self::assertSame(['username' => 'api_sa', 'password' => base64_encode('s3cret')], json_decode($this->requests[0]['options']['body'], true));
        self::assertSame('https://cs.example.local/commandcenter/api/V4/Storage/Tape?x=1', $this->requests[1]['url']);
        self::assertContains('Authtoken: QSDK abc', $this->requests[1]['options']['headers']);
        self::assertContains('Accept: application/json', $this->requests[1]['options']['headers']);
    }

    public function testReauthenticatesOnceOn401(): void
    {
        $client = $this->client([
            self::json(['token' => 'QSDK old']),
            self::json([], 401),
            self::json(['token' => 'QSDK new']),
            self::json(['ok' => true]),
        ]);

        self::assertSame(200, $client->get('WhoAmI')->statusCode);
        self::assertCount(4, $this->requests);
        self::assertContains('Authtoken: QSDK new', $this->requests[3]['options']['headers']);
    }

    public function testDoesNotLoopOnRepeated401(): void
    {
        $client = $this->client([
            self::json(['token' => 'QSDK a']),
            self::json([], 401),
            self::json(['token' => 'QSDK b']),
            self::json([], 401),
        ]);

        self::assertSame(401, $client->get('WhoAmI')->statusCode);
        self::assertCount(4, $this->requests);
    }

    public function testBadCredentialsReportedWithHttp200(): void
    {
        $client = $this->client([self::json(['errList' => [['errLogMessage' => 'Invalid credentials']]])]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid credentials');
        $client->get('WhoAmI');
    }

    public function testQCommandIsFormEncoded(): void
    {
        $client = $this->client([self::json(['token' => 'QSDK abc']), new MockResponse('BARCODE ...')]);

        $client->qcommand('qlist media -j 42');

        self::assertSame('https://cs.example.local/commandcenter/api/ExecuteQCommand', $this->requests[1]['url']);
        self::assertSame('command=qlist+media+-j+42', $this->requests[1]['options']['body']);
    }

    public function testWriteRequestsNeverLeave(): void
    {
        $client = $this->client([]);

        try {
            $client->qcommand('qoperation export -b K00067L8');
            self::fail('expected exception');
        } catch (ReadOnlyViolationException) {
        }
        try {
            $client->query('V4/Storage/Tape/3/Media', ['operationType' => 'MARK_MEDIA_FULL']);
            self::fail('expected exception');
        } catch (ReadOnlyViolationException) {
        }
        self::assertSame([], $this->requests);
    }
}
