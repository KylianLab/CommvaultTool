<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\EjectPolicy;
use App\Entity\Media;
use App\Enum\EjectRule;
use App\Enum\PhysicalState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EjectPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{EjectRule, string, bool, bool}>
     */
    public static function matrix(): iterable
    {
        // rule, status, job running on the policy, expected
        yield 'on_full: full' => [EjectRule::OnFull, Media::STATUS_FULL, false, true];
        yield 'on_full: full, job running' => [EjectRule::OnFull, Media::STATUS_FULL, true, true];
        yield 'on_full: active' => [EjectRule::OnFull, Media::STATUS_ACTIVE, false, false];
        yield 'on_job_complete: active, no job' => [EjectRule::OnJobComplete, Media::STATUS_ACTIVE, false, true];
        yield 'on_job_complete: appendable, no job' => [EjectRule::OnJobComplete, Media::STATUS_APPENDABLE, false, true];
        yield 'on_job_complete: active, job running' => [EjectRule::OnJobComplete, Media::STATUS_ACTIVE, true, false];
        yield 'on_job_complete: full, job running' => [EjectRule::OnJobComplete, Media::STATUS_FULL, true, false];
        yield 'both: full, job running' => [EjectRule::Both, Media::STATUS_FULL, true, true];
        yield 'both: active, job running' => [EjectRule::Both, Media::STATUS_ACTIVE, true, false];
        yield 'both: active, no job' => [EjectRule::Both, Media::STATUS_ACTIVE, false, true];
    }

    #[DataProvider('matrix')]
    public function testRules(EjectRule $rule, string $status, bool $jobRunning, bool $expected): void
    {
        $media = Factory::media('K1', $status, true, Factory::copy($rule), slot: 3);

        self::assertSame($expected, (new EjectPolicy())->decide($media, $jobRunning)->toEject);
    }

    public function testMountedTapeIsNeverEjected(): void
    {
        $media = Factory::media('K1', Media::STATUS_FULL, true, Factory::copy(EjectRule::OnFull), drive: 'IBM ULT3580-HH9_1');

        $decision = (new EjectPolicy())->decide($media, false);

        self::assertFalse($decision->toEject);
        self::assertStringContainsString('lecteur', $decision->reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noData(): iterable
    {
        yield [Media::STATUS_SPARE];
        yield [Media::STATUS_AGED];
        yield [Media::STATUS_CLEANING];
        yield [Media::STATUS_BAD];
    }

    #[DataProvider('noData')]
    public function testTapesWithoutDataStay(string $status): void
    {
        self::assertFalse((new EjectPolicy())->decide(Factory::media('K1', $status, true, Factory::copy()), false)->toEject);
    }

    public function testExportedOrAlreadyEjectedTapesAreNotEjectedAgain(): void
    {
        $policy = new EjectPolicy();
        self::assertFalse($policy->decide(Factory::media('K1', Media::STATUS_FULL, false, Factory::copy()), false)->toEject);

        $ejected = Factory::media('K2', Media::STATUS_FULL, true, Factory::copy());
        $ejected->setPhysicalState(PhysicalState::Ejected);
        self::assertFalse($policy->decide($ejected, false)->toEject);

        $returned = Factory::media('K3', Media::STATUS_FULL, true, Factory::copy());
        $returned->setPhysicalState(PhysicalState::Returned);
        self::assertTrue($policy->decide($returned, false)->toEject);
    }

    public function testTapeWithoutCopyStays(): void
    {
        self::assertFalse((new EjectPolicy())->decide(Factory::media('K1', Media::STATUS_FULL, true, null), false)->toEject);
    }
}
