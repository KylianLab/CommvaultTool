<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commvault;

use App\Commvault\FixtureNaming;
use PHPUnit\Framework\TestCase;

final class FixtureNamingTest extends TestCase
{
    public function testPathOnly(): void
    {
        self::assertSame('GET_V4_Storage_Tape.json', FixtureNaming::fileName('get', '/V4/Storage/Tape'));
    }

    public function testQueryIsSortedAndSlugged(): void
    {
        self::assertSame(
            'GET_Job__clientId-2_jobFilter-Backup.json',
            FixtureNaming::fileName('GET', '/Job', ['jobFilter' => 'Backup', 'clientId' => 2]),
        );
    }

    public function testBodyHashIsStableRegardlessOfKeyOrder(): void
    {
        $a = FixtureNaming::fileName('POST', '/Jobs', [], ['a' => 1, 'b' => ['y' => 2, 'x' => 1]]);
        $b = FixtureNaming::fileName('POST', '/Jobs', [], ['b' => ['x' => 1, 'y' => 2], 'a' => 1]);

        self::assertSame($a, $b);
        self::assertMatchesRegularExpression('/^POST_Jobs__body-[0-9a-f]{8}\.json$/', $a);
    }

    public function testLongQueryIsTruncatedWithHash(): void
    {
        $name = FixtureNaming::fileName('GET', '/Job', ['filter' => str_repeat('x', 200)]);

        self::assertLessThan(130, \strlen($name));
        self::assertMatchesRegularExpression('/-[0-9a-f]{8}\.json$/', $name);
    }
}
