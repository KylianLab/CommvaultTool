<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\MediaAlerts;
use App\Domain\ReturnPolicy;
use App\Entity\Media;
use App\Enum\PhysicalState;
use PHPUnit\Framework\TestCase;

final class ReturnAndAlertsTest extends TestCase
{
    public function testAgedTapeOutsideMustReturn(): void
    {
        $policy = new ReturnPolicy();

        self::assertTrue($policy->mustReturn(Factory::media('K1', Media::STATUS_AGED, false)));
        self::assertFalse($policy->mustReturn(Factory::media('K2', Media::STATUS_AGED, true)), 'already in the library');
        self::assertFalse($policy->mustReturn(Factory::media('K3', Media::STATUS_FULL, false, retainUntil: '2026-01-01')), 'retention passed but not aged by Commvault');
    }

    public function testRetentionPassedButNotAgedIsOnlyInformative(): void
    {
        $media = Factory::media('K1', Media::STATUS_FULL, false, retainUntil: '2026-01-01');

        self::assertTrue((new ReturnPolicy())->retentionPassedNotAged($media, Factory::at('2026-10-14')));
    }

    public function testDivergences(): void
    {
        $alerts = new MediaAlerts();

        $stored = Factory::media('K1', Media::STATUS_FULL, true, slot: 12);
        $stored->setPhysicalState(PhysicalState::Stored);
        self::assertSame(['Commvault voit la tape dans la TL (slot 12) alors qu\'elle est marquée « Rangée ».'], $alerts->for($stored, true));

        $notConfirmed = Factory::media('K2', Media::STATUS_FULL, false);
        self::assertStringContainsString('sortie non confirmée', $alerts->for($notConfirmed, true)[0]);

        $bad = Factory::media('K3', Media::STATUS_BAD, true);
        self::assertStringContainsString('défectueuse', $alerts->for($bad, true)[0]);

        self::assertStringContainsString('absente', $alerts->for(Factory::media('K4'), false)[0]);
        self::assertSame([], $alerts->for(Factory::media('K5'), true));
    }
}
