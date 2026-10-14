<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commvault\Http;

use App\Commvault\Exception\AuthenticationException;
use App\Commvault\Http\AccessTokenProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AccessTokenProviderTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir().'/tapetrack-token-'.bin2hex(random_bytes(4)).'/access-token.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
        @unlink($this->stateFile.'.lock');
        @rmdir(\dirname($this->stateFile));
    }

    public function testUsesInitialTokenAsBearer(): void
    {
        $provider = new AccessTokenProvider(new MockHttpClient([]), new NullLogger(), 'acc1', 'ref1', $this->stateFile);

        self::assertSame(['Authorization' => 'Bearer acc1'], $provider->authHeaders());
    }

    public function testRenewsAndPersistsTheNewPair(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = [$method, $url, json_decode($options['body'], true)];

            return new MockResponse(json_encode(['accessToken' => 'acc2', 'refreshToken' => 'ref2']));
        }, 'https://cs.example.local/commandcenter/api/');
        $provider = new AccessTokenProvider($http, new NullLogger(), 'acc1', 'ref1', $this->stateFile);
        $provider->authHeaders();

        $provider->invalidate();

        self::assertSame(['Authorization' => 'Bearer acc2'], $provider->authHeaders());
        self::assertSame(['POST', 'https://cs.example.local/commandcenter/api/V4/AccessToken/Renew', ['accessToken' => 'acc1', 'refreshToken' => 'ref1']], $sent);
        self::assertSame(0o600, fileperms($this->stateFile) & 0o777);

        // a new process starts from the persisted pair, not from the (now burnt) secrets
        $next = new AccessTokenProvider(new MockHttpClient([]), new NullLogger(), 'acc1', 'ref1', $this->stateFile);
        self::assertSame(['Authorization' => 'Bearer acc2'], $next->authHeaders());
    }

    public function testFailedRenewal(): void
    {
        $provider = new AccessTokenProvider(new MockHttpClient([new MockResponse('{"errorMessage":"expired"}', ['http_code' => 500])]), new NullLogger(), 'acc1', 'ref1', $this->stateFile);
        $provider->authHeaders();
        $provider->invalidate();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('expired');
        $provider->authHeaders();
    }

    public function testNotConfigured(): void
    {
        $this->expectException(AuthenticationException::class);
        (new AccessTokenProvider(new MockHttpClient([]), new NullLogger(), '', '', $this->stateFile))->authHeaders();
    }
}
