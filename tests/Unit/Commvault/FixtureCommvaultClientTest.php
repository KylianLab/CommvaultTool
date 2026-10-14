<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commvault;

use App\Commvault\Exception\FixtureNotFoundException;
use App\Commvault\Exception\ReadOnlyViolationException;
use App\Commvault\Fake\FixtureCommvaultClient;
use PHPUnit\Framework\TestCase;

final class FixtureCommvaultClientTest extends TestCase
{
    private FixtureCommvaultClient $client;

    protected function setUp(): void
    {
        $this->client = new FixtureCommvaultClient(__DIR__.'/../../Fixtures/commvault/unit');
    }

    public function testServesExactFixture(): void
    {
        $response = $this->client->get('/V4/Storage/Tape');

        self::assertSame(200, $response->statusCode);
        self::assertSame('TL-TEST', $response->toArray()['tapeLibraries'][0]['name']);
    }

    public function testFallsBackToGenericFixtureWhenQueryDiffers(): void
    {
        self::assertSame(200, $this->client->get('/V4/Storage/Tape', ['limit' => 5])->statusCode);
    }

    public function testWrappedFixtureSimulatesHttpError(): void
    {
        $response = $this->client->get('/Job', ['clientId' => 2]);

        self::assertSame(404, $response->statusCode);
        self::assertFalse($response->isSuccessful());
        self::assertSame('errorCode 1: not found', $response->applicationError());
    }

    public function testMissingFixture(): void
    {
        $this->expectException(FixtureNotFoundException::class);
        $this->client->get('/Nope');
    }

    public function testReadOnlyIsEnforcedToo(): void
    {
        $this->expectException(ReadOnlyViolationException::class);
        $this->client->query('/Library/1/Media/Export');
    }
}
