<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\TimeEntryAggregationType;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\V1\Report\DetailedWithDataReportResource;
use App\Models\Report;
use App\Models\TimeEntry;
use App\Service\Dto\ReportPropertiesDto;
use App\Service\TimeEntryAggregationService;
use App\Service\TimeEntryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Get report by a share secret
     *
     * This endpoint is public and does not require authentication. The report must be public and not expired.
     * The report is considered expired if the `public_until` field is set and the date is in the past.
     * The report is considered public if the `is_public` field is set to `true`.
     *
     * @operationId getPublicReport
     */
    public function show(Request $request, TimeEntryAggregationService $timeEntryAggregationService): DetailedWithDataReportResource
    {
        $shareSecret = $request->header('X-Api-Key');
        if (! is_string($shareSecret)) {
            throw new ModelNotFoundException;
        }

        $report = Report::query()
            ->with([
                'organization',
            ])
            ->where('share_secret', '=', $shareSecret)
            ->where('is_public', '=', true)
            ->where(function (Builder $builder): void {
                /** @var Builder<Report> $builder */
                $builder->whereNull('public_until')
                    ->orWhere('public_until', '>', now());
            })
            ->firstOrFail();
        /** @var ReportPropertiesDto $properties */
        $properties = $report->properties;

        $timeEntriesQuery = TimeEntry::query()
            ->whereBelongsTo($report->organization, 'organization');

        $filter = new TimeEntryFilter($timeEntriesQuery);
        $filter->addStart($properties->start);
        $filter->addEnd($properties->end);
        $filter->addActive($properties->active);
        if ((bool) config('app.enable_billable', true)) {
            $filter->addBillable($properties->billable);
        }
        $filter->addMemberIdsFilter($properties->memberIds?->toArray());
        $filter->addProjectIdsFilter($properties->projectIds?->toArray());
        $filter->addTagIdsFilter($properties->tagIds?->toArray());
        $filter->addTaskIdsFilter($properties->taskIds?->toArray());
        if ((bool) config('app.enable_clients', true)) {
            $filter->addClientIdsFilter($properties->clientIds?->toArray());
        }
        $timeEntriesQuery = $filter->get();
        $group = $report->properties->group;
        $subGroup = $report->properties->subGroup;
        if (! (bool) config('app.enable_billable', true)) {
            $group = $group === TimeEntryAggregationType::Billable ? TimeEntryAggregationType::Project : $group;
            $subGroup = $subGroup === TimeEntryAggregationType::Billable ? TimeEntryAggregationType::Task : $subGroup;
        }
        if (! (bool) config('app.enable_clients', true)) {
            $group = $group === TimeEntryAggregationType::Client ? TimeEntryAggregationType::Project : $group;
            $subGroup = $subGroup === TimeEntryAggregationType::Client ? TimeEntryAggregationType::Project : $subGroup;
        }

        $data = $timeEntryAggregationService->getAggregatedTimeEntriesWithDescriptions(
            $timeEntriesQuery->clone(),
            $group,
            $subGroup,
            $report->properties->timezone,
            $report->properties->weekStart,
            false,
            $report->properties->start,
            $report->properties->end,
            (bool) config('app.enable_billable', true),
            $report->properties->roundingType,
            $report->properties->roundingMinutes,
        );
        $historyData = $timeEntryAggregationService->getAggregatedTimeEntriesWithDescriptions(
            $timeEntriesQuery->clone(),
            TimeEntryAggregationType::fromInterval($report->properties->historyGroup),
            null,
            $report->properties->timezone,
            $report->properties->weekStart,
            true,
            $report->properties->start,
            $report->properties->end,
            (bool) config('app.enable_billable', true),
            $report->properties->roundingType,
            $report->properties->roundingMinutes,
        );

        return new DetailedWithDataReportResource($report, $data, $historyData);
    }
}
