<?php

declare(strict_types=1);

namespace App\Services\Outreach\Import;

/** Counters for one prospect import run (CSV or Finder). */
class ImportReport
{
    public int $rows = 0;

    public int $created = 0;

    public int $updated = 0;

    public int $duplicates = 0;

    public int $invalid = 0;

    public int $withEmail = 0;

    /** @var list<string> */
    public array $errors = [];

    public bool $dryRun = false;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'created' => $this->created,
            'updated' => $this->updated,
            'duplicates' => $this->duplicates,
            'invalid' => $this->invalid,
            'with_email' => $this->withEmail,
            'errors' => array_slice($this->errors, 0, 50),
            'dry_run' => $this->dryRun,
        ];
    }

    public function summary(): string
    {
        return sprintf(
            '%d rows: %d created, %d updated, %d duplicates, %d invalid, %d with an email%s',
            $this->rows, $this->created, $this->updated, $this->duplicates, $this->invalid, $this->withEmail,
            $this->dryRun ? ' (dry run, nothing written)' : '',
        );
    }
}
