<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Entity\BackupJob;
use App\Entity\Media;
use App\Entity\StoragePolicy;
use App\Entity\StoragePolicyCopy;
use App\Enum\EjectRule;

/**
 * Builders for domain tests. Dates are given in Europe/Brussels local time.
 */
final class Factory
{
    private static int $seq = 1000;

    public static function at(string $local): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Brussels')))->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function copy(EjectRule $rule = EjectRule::Both, int $id = 167): StoragePolicyCopy
    {
        $copy = new StoragePolicyCopy($id, new StoragePolicy(138, 'SP_VSP_Online-N'), 'Master');
        $copy->setEjectRule($rule);

        return $copy;
    }

    public static function media(
        string $barcode,
        string $status = Media::STATUS_FULL,
        bool $inLibrary = true,
        ?StoragePolicyCopy $copy = null,
        ?string $lastWrite = null,
        ?string $retainUntil = null,
        ?int $slot = null,
        ?string $drive = null,
    ): Media {
        $m = new Media(++self::$seq, $barcode, self::at('2026-10-14 08:00'));
        $m->setCommvaultStatus($status);
        $m->setInLibrary($inLibrary);
        $m->setCopy($copy);
        $m->setLastWriteAt(null === $lastWrite ? null : self::at($lastWrite));
        $m->setRetainUntil(null === $retainUntil ? null : self::at($retainUntil));
        $m->setSlot($slot);
        $m->setDrive($drive);

        return $m;
    }

    public static function job(int $id, StoragePolicyCopy $copy, string $type, string $start, ?string $retainUntil): BackupJob
    {
        $j = new BackupJob($id, $copy, self::at('2026-10-14 08:00'));
        $j->setJobType($type);
        $j->setStartAt(self::at($start));
        $j->setRetainUntil(null === $retainUntil ? null : self::at($retainUntil));

        return $j;
    }
}
