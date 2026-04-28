<?php

declare(strict_types=1);

namespace App\Service\ReportExport;

use App\Models\TimeEntry;
use App\Service\IntervalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends CsvExport<TimeEntry>
 */
class TimeEntriesDetailedCsvExport extends CsvExport
{
    public const array HEADER = [
        'Description',
        'Task',
        'Project',
        'Client',
        'User',
        'Start',
        'End',
        'Duration',
        'Duration (decimal)',
        'Billable',
        'Tags',
    ];

    protected const string CARBON_FORMAT = 'Y-m-d H:i:s';

    private string $timezone;

    private bool $showBillableRate;

    public function __construct(string $disk, string $folderPath, string $filename, Builder $builder, int $chunk, string $timezone, bool $showBillableRate)
    {
        parent::__construct($disk, $folderPath, $filename, $builder, $chunk);

        $this->timezone = $timezone;
        $this->showBillableRate = $showBillableRate;
    }

    protected function header(): array
    {
        if ($this->showBillableRate) {
            return self::HEADER;
        }

        return array_values(array_filter(
            self::HEADER,
            fn (string $header): bool => $header !== 'Billable'
        ));
    }

    /**
     * @param  TimeEntry  $model
     */
    public function mapRow(Model $model): array
    {
        $interval = app(IntervalService::class);
        $duration = $model->getDuration();

        $row = [
            'Description' => $model->description,
            'Task' => $model->task?->name,
            'Project' => $model->project?->name,
            'Client' => $model->client?->name,
            'User' => $model->user->name,
            'Start' => $model->start->timezone($this->timezone),
            'End' => $model->end->timezone($this->timezone),
            'Duration' => $duration !== null ? $interval->format($model->getDuration()) : null,
            'Duration (decimal)' => $duration?->totalHours,
            'Tags' => $model->tagsRelation->pluck('name')->implode(', '),
        ];

        if ($this->showBillableRate) {
            $row = array_slice($row, 0, 9, true)
                + ['Billable' => $model->billable ? 'Yes' : 'No']
                + array_slice($row, 9, null, true);
        }

        return $row;
    }
}
