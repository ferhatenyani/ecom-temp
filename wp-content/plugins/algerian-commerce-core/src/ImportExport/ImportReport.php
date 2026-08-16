<?php

declare(strict_types=1);

namespace AlgerianCommerce\ImportExport;

/**
 * What an import did, or would do — roadmap §64's "error report", PLAN §33's
 * "dry-run and error reporting".
 *
 * Pure — no WordPress — so the shape of the answer a shop owner reads before
 * confirming a 500-row import is testable without a shop.
 *
 * **A dry run and a real run produce the same report.** That is the whole point
 * of §64's pipeline: the preview is trustworthy only if it was produced by the
 * same code path that will do the work. Two code paths — one that guesses and
 * one that acts — is how a preview comes to say "40 updates" and the run comes
 * to make 40 deletions.
 *
 * **Errors are bounded and counted.** A file where every row is wrong produces
 * one error per row, and returning two thousand of them helps nobody and may
 * not fit in a response. The first `MAX_ERRORS` are returned in full and the
 * rest are counted, because "and 1,940 more" is the number that tells someone
 * their column mapping is wrong rather than their data.
 *
 * **A row that fails is skipped; it never stops the import.** A single bad SKU
 * on line 300 must not abandon the 299 rows that were fine — the shop owner
 * fixes that row and re-uploads it, rather than re-uploading everything and
 * wondering which half applied.
 */
final class ImportReport
{
    /** Enough to see the pattern, few enough to fit in a response. */
    public const MAX_ERRORS = 100;

    /** Enough to recognise the file, few enough to read. */
    public const MAX_PREVIEW = 20;

    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /** @var array<string, int> */
    private array $counts = [
        self::CREATED => 0,
        self::UPDATED => 0,
        self::SKIPPED => 0,
        self::FAILED => 0,
    ];

    /** @var list<array<string, mixed>> */
    private array $errors = [];

    /** @var list<array<string, mixed>> */
    private array $preview = [];

    private int $errorsOmitted = 0;

    public function __construct(public readonly bool $dryRun)
    {
    }

    /**
     * Record what happened — or, on a dry run, what would have.
     *
     * @param array<string, mixed> $detail anything that identifies the row to a person
     */
    public function record(string $action, int $line, array $detail = []): void
    {
        if (array_key_exists($action, $this->counts)) {
            $this->counts[$action]++;
        }

        if (count($this->preview) < self::MAX_PREVIEW) {
            $this->preview[] = ['line' => $line, 'action' => $action] + $detail;
        }
    }

    /**
     * A row that could not be applied. Always counts as `failed`, so the totals
     * and the error list cannot disagree about how many rows went wrong.
     *
     * @param array<string, string> $fields per-field messages, as everywhere else in this API
     */
    public function fail(int $line, string $message, array $fields = []): void
    {
        $this->counts[self::FAILED]++;

        if (count($this->errors) >= self::MAX_ERRORS) {
            $this->errorsOmitted++;

            return;
        }

        $error = ['line' => $line, 'message' => $message];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        $this->errors[] = $error;
    }

    public function failedCount(): int
    {
        return $this->counts[self::FAILED];
    }

    public function rowsSeen(): int
    {
        return array_sum($this->counts);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $report = [
            /*
             * First, and named rather than implied: a client that renders this
             * report has to be able to say "nothing has been written yet" in
             * the same breath as the numbers, or somebody will read a dry run
             * as a receipt.
             */
            'dry_run' => $this->dryRun,
            'rows' => $this->rowsSeen(),
            'created' => $this->counts[self::CREATED],
            'updated' => $this->counts[self::UPDATED],
            'skipped' => $this->counts[self::SKIPPED],
            'failed' => $this->counts[self::FAILED],
            'errors' => $this->errors,
            'preview' => $this->preview,
        ];

        if ($this->errorsOmitted > 0) {
            $report['errors_omitted'] = $this->errorsOmitted;
        }

        return $report;
    }
}
