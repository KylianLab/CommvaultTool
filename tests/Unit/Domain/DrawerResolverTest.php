<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\DrawerResolver;
use App\Entity\LibraryDrawer;
use App\Entity\TapeLibrary;
use PHPUnit\Framework\TestCase;

final class DrawerResolverTest extends TestCase
{
    public function testKbrLayout(): void
    {
        $vsp = new TapeLibrary(36, 'IBM VSP');
        $vsp->setSlotCount(60);
        new LibraryDrawer($vsp, 1, 1, 20);
        new LibraryDrawer($vsp, 2, 21, 40);
        new LibraryDrawer($vsp, 3, 41, 60);
        $infra = new TapeLibrary(26, 'IBM INFRA');
        new LibraryDrawer($infra, 4, 1, 20);

        $resolver = new DrawerResolver();

        self::assertSame(1, $resolver->drawerFor($vsp, 1)->getNumber());
        self::assertSame(1, $resolver->drawerFor($vsp, 20)->getNumber());
        self::assertSame(2, $resolver->drawerFor($vsp, 21)->getNumber());
        self::assertSame(3, $resolver->drawerFor($vsp, 60)->getNumber());
        self::assertNull($resolver->drawerFor($vsp, 61));
        self::assertNull($resolver->drawerFor($vsp, null));
        self::assertSame(4, $resolver->drawerFor($infra, 5)->getNumber(), 'INFRA logical slots 1-20 are in physical drawer 4');
        self::assertSame([], $resolver->validate($vsp));
    }

    public function testOverlapAndOverflowAreReported(): void
    {
        $lib = new TapeLibrary(36, 'IBM VSP');
        $lib->setSlotCount(60);
        new LibraryDrawer($lib, 1, 1, 25);
        new LibraryDrawer($lib, 2, 21, 70);

        $problems = (new DrawerResolver())->validate($lib);

        self::assertCount(2, $problems);
    }

    public function testInvalidRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LibraryDrawer(new TapeLibrary(1, 'x'), 1, 10, 5);
    }
}
