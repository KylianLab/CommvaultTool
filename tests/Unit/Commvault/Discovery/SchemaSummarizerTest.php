<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commvault\Discovery;

use App\Commvault\Discovery\SchemaSummarizer;
use PHPUnit\Framework\TestCase;

final class SchemaSummarizerTest extends TestCase
{
    public function testCollapsesListsAndCollectsExamples(): void
    {
        $summary = SchemaSummarizer::summarize(['mediaDetailsList' => [
            ['barcode' => 'K00067L8', 'status' => 'Full Media', 'slot' => ['id' => 12]],
            ['barcode' => 'K00057L8', 'status' => 'Appendable Media', 'slot' => ['id' => 13]],
            ['barcode' => 'K00058L8', 'status' => 'Full Media', 'slot' => null],
        ]]);

        self::assertSame(['list'], $summary['mediaDetailsList']['types']);
        self::assertSame(['max length 3'], $summary['mediaDetailsList']['examples']);
        self::assertSame(['Full Media', 'Appendable Media'], $summary['mediaDetailsList[].status']['examples']);
        self::assertSame(3, $summary['mediaDetailsList[].status']['count']);
        self::assertSame(['object', 'null'], $summary['mediaDetailsList[].slot']['types']);
        self::assertSame(['int'], $summary['mediaDetailsList[].slot.id']['types']);
    }

    public function testMasksIdentifyingValues(): void
    {
        $summary = SchemaSummarizer::summarize(['jobs' => [[
            'jobSummary' => ['subclient' => ['clientName' => 'srv-secret', 'subclientName' => 'default'], 'clientId' => 12, 'status' => 'Completed'],
        ]]]);

        self::assertSame(['«masked»'], $summary['jobs[].jobSummary.subclient.clientName']['examples']);
        self::assertSame(['«masked»'], $summary['jobs[].jobSummary.subclient.subclientName']['examples']);
        self::assertSame(['12'], $summary['jobs[].jobSummary.clientId']['examples']); // ids stay visible
        self::assertSame(['Completed'], $summary['jobs[].jobSummary.status']['examples']);
    }
}
