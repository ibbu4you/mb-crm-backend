<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class WorkLogReportExport implements WithMultipleSheets
{
    public function __construct(private array $report) {}

    public function sheets(): array
    {
        $sheets = [new WorkSummarySheet($this->report)];

        // Single-employee reports carry a day-by-day breakdown and their entries.
        if (! empty($this->report['entries'])) {
            $sheets[] = new WorkDailySheet($this->report);
            $sheets[] = new WorkEntriesSheet($this->report);
        }

        return $sheets;
    }
}

class WorkSummarySheet implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private array $report) {}

    public function collection()
    {
        return collect($this->report['employees'])->map(fn ($e) => [
            $e['name'], $e['expected'], $e['submitted'], $e['missed'], $e['compliance'].'%', $e['hours_logged'],
        ]);
    }

    public function headings(): array
    {
        return ['Employee', 'Expected updates', 'Submitted', 'Missed', 'Compliance', 'Hours logged'];
    }

    public function title(): string
    {
        return 'Summary';
    }
}

class WorkDailySheet implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private array $report) {}

    public function collection()
    {
        return collect($this->report['days'])->map(fn ($d) => [
            $d['date'], $d['expected'], $d['submitted'], $d['missed'], $d['compliance'].'%',
        ]);
    }

    public function headings(): array
    {
        return ['Date', 'Expected', 'Submitted', 'Missed', 'Compliance'];
    }

    public function title(): string
    {
        return 'Daily';
    }
}

class WorkEntriesSheet implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private array $report) {}

    public function collection()
    {
        return collect($this->report['entries'])->map(fn ($e) => [
            $e['date'], $e['hour'], $e['mode_label'], $e['note'], $e['link_label'], $e['is_late'] ? 'Late' : '',
        ]);
    }

    public function headings(): array
    {
        return ['Date', 'Hour', 'Mode', 'Note', 'Linked to', 'Flag'];
    }

    public function title(): string
    {
        return 'Entries';
    }
}
