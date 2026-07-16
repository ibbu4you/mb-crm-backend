<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** The full attendance grid — one row per employee, one column per day. */
class TeamAttendanceExport implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    public function __construct(private array $reg) {}

    public function headings(): array
    {
        $head = ['Employee'];
        foreach ($this->reg['days'] as $d) {
            $head[] = $d['dow'].' '.$d['day'];   // e.g. "Mon 13"
        }

        return array_merge($head, ['Present', 'Hours', 'Late']);
    }

    public function array(): array
    {
        return array_map(function (array $e) {
            $row = [$e['name']];
            foreach ($this->reg['days'] as $d) {
                $row[] = self::cell($e['cells'][$d['date']] ?? null);
            }
            $row[] = $e['present'];
            $row[] = $e['work_hours'];
            $row[] = $e['late'];

            return $row;
        }, $this->reg['employees']);
    }

    private static function cell(?array $c): string
    {
        if (! $c) {
            return '—';
        }
        $label = match ($c['status']) {
            'late' => 'Late',
            'half_day' => 'Half day',
            default => $c['on_site'] ? 'On-site' : 'Field',
        };

        return $c['in'] ? "{$label} {$c['in']}" : $label;
    }

    public function title(): string
    {
        return $this->reg['label'];
    }
}
