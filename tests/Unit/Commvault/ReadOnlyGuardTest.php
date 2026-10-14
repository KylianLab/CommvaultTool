<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commvault;

use App\Commvault\Exception\ReadOnlyViolationException;
use App\Commvault\ReadOnlyGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadOnlyGuardTest extends TestCase
{
    #[DataProvider('allowed')]
    public function testAllowed(string $method, string $path): void
    {
        (new ReadOnlyGuard())->assertAllowed($method, $path);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<array{string, string}> */
    public static function allowed(): iterable
    {
        yield ['GET', '/V4/Storage/Tape'];
        yield ['get', 'StoragePolicy/12?propertyLevel=10'];
        yield ['POST', '/Login'];
        yield ['POST', 'JobDetails'];
        yield ['POST', '/jobs'];
    }

    #[DataProvider('forbidden')]
    public function testForbidden(string $method, string $path): void
    {
        $this->expectException(ReadOnlyViolationException::class);
        (new ReadOnlyGuard())->assertAllowed($method, $path);
    }

    /** @return iterable<array{string, string}> */
    public static function forbidden(): iterable
    {
        yield ['POST', '/Library/3/Media/Export'];
        yield ['POST', '/ExecuteQCommand'];
        yield ['PUT', '/V4/StoragePolicy/1'];
        yield ['DELETE', '/Job/42'];
        yield ['PATCH', '/Login'];
    }
}
