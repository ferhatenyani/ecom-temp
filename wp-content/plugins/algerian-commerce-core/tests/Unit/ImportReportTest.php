<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\ImportExport\ImportReport;
use PHPUnit\Framework\TestCase;

final class ImportReportTest extends TestCase
{
    public function testAnEmptyRunReportsZeroesRatherThanNothing(): void
    {
        $report = (new ImportReport(true))->toArray();

        self::assertTrue($report['dry_run']);
        self::assertSame(0, $report['rows']);
        self::assertSame([], $report['errors']);
        self::assertSame([], $report['preview']);
    }

    /**
     * A client rendering this has to be able to say "nothing has been written"
     * in the same breath as the numbers, or a dry run gets read as a receipt.
     */
    public function testTheDryRunFlagIsAlwaysInTheReport(): void
    {
        self::assertTrue((new ImportReport(true))->toArray()['dry_run']);
        self::assertFalse((new ImportReport(false))->toArray()['dry_run']);
    }

    public function testEachOutcomeIsCountedSeparately(): void
    {
        $report = new ImportReport(false);
        $report->record(ImportReport::CREATED, 2);
        $report->record(ImportReport::CREATED, 3);
        $report->record(ImportReport::UPDATED, 4);
        $report->record(ImportReport::SKIPPED, 5);
        $report->fail(6, 'bad row');

        $out = $report->toArray();

        self::assertSame(2, $out['created']);
        self::assertSame(1, $out['updated']);
        self::assertSame(1, $out['skipped']);
        self::assertSame(1, $out['failed']);
        self::assertSame(5, $out['rows']);
    }

    /** The totals and the error list must not be able to disagree. */
    public function testAFailureAlwaysCountsAsFailed(): void
    {
        $report = new ImportReport(false);

        for ($i = 0; $i < 3; $i++) {
            $report->fail($i + 2, 'bad');
        }

        self::assertSame(3, $report->failedCount());
        self::assertSame(3, $report->toArray()['failed']);
    }

    /**
     * A file where every row is wrong must not return two thousand errors —
     * "and 1,940 more" is the number that says the column mapping is wrong.
     */
    public function testErrorsAreCappedAndTheRemainderIsCounted(): void
    {
        $report = new ImportReport(false);
        $total = ImportReport::MAX_ERRORS + 40;

        for ($i = 0; $i < $total; $i++) {
            $report->fail($i + 2, 'bad row');
        }

        $out = $report->toArray();

        self::assertCount(ImportReport::MAX_ERRORS, $out['errors']);
        self::assertSame(40, $out['errors_omitted']);
        // The count is still the truth, even though the list is not complete.
        self::assertSame($total, $out['failed']);
    }

    public function testAnUntruncatedReportDoesNotMentionOmissions(): void
    {
        $report = new ImportReport(false);
        $report->fail(2, 'bad');

        self::assertArrayNotHasKey('errors_omitted', $report->toArray());
    }

    public function testThePreviewIsCappedButTheCountsAreNot(): void
    {
        $report = new ImportReport(true);
        $rows = ImportReport::MAX_PREVIEW + 15;

        for ($i = 0; $i < $rows; $i++) {
            $report->record(ImportReport::UPDATED, $i + 2, ['sku' => "A-{$i}"]);
        }

        $out = $report->toArray();

        self::assertCount(ImportReport::MAX_PREVIEW, $out['preview']);
        self::assertSame($rows, $out['updated']);
    }

    public function testAnErrorCarriesItsLineAndItsFields(): void
    {
        $report = new ImportReport(true);
        $report->fail(7, 'The row is invalid.', ['sku' => 'Required.']);

        $error = $report->toArray()['errors'][0];

        self::assertSame(7, $error['line']);
        self::assertSame('The row is invalid.', $error['message']);
        self::assertSame(['sku' => 'Required.'], $error['fields']);
    }

    public function testAPreviewEntryIdentifiesTheRowToAPerson(): void
    {
        $report = new ImportReport(true);
        $report->record(ImportReport::UPDATED, 4, ['sku' => 'A-1', 'from' => 10, 'to' => 3]);

        self::assertSame(
            ['line' => 4, 'action' => 'updated', 'sku' => 'A-1', 'from' => 10, 'to' => 3],
            $report->toArray()['preview'][0]
        );
    }

    /** An unknown action must not silently create a counter. */
    public function testAnUnknownActionIsNotCounted(): void
    {
        $report = new ImportReport(false);
        $report->record('exploded', 2);

        self::assertSame(0, $report->toArray()['rows']);
    }
}
