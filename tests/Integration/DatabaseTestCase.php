<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Boots the kernel on a fresh SQLite schema, with the fake Commvault client
 * serving tests/Fixtures/commvault/demo and the clock frozen on
 * 14/10/2026 10:00 (Brussels), the reference date of the fixtures.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    use ClockSensitiveTrait;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime(new \DateTimeImmutable('2026-10-14 10:00', new \DateTimeZone('Europe/Brussels')));

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }
}
