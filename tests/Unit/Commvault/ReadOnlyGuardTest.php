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

    #[DataProvider('qcommands')]
    public function testQCommands(string $command, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(ReadOnlyViolationException::class);
        }
        (new ReadOnlyGuard())->assertAllowed('POST', 'ExecuteQCommand', ['command' => $command]);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<array{string, bool}> */
    public static function qcommands(): iterable
    {
        yield ['qlist media -j 42', true];
        yield ['QLIST MEDIA -sp "Online 1" -spc "Primary"', true];
        yield ['qinfo media -b K00067L8', true];
        yield ["qoperation execscript -sn QS_JobsinSPCopy -si @i_policyName='Online 1'", true];
        yield ['qlist mediaagent', false];
        yield ['qoperation execscript -sn RetainJobsOnMedia', false];
        yield ['qoperation export -b K00067L8', false];
        yield ['qdelete job -j 1', false];
        yield ['qlist media -j 1 ; qdelete job -j 1', false];
        yield ["qlist media -j 1
qdelete job -j 1", false];
        yield ['', false];
    }

    public function testExecuteQCommandWithoutCommandIsRefused(): void
    {
        $this->expectException(ReadOnlyViolationException::class);
        (new ReadOnlyGuard())->assertAllowed('POST', 'ExecuteQCommand', ['xml' => '<x/>']);
    }
}
