<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\TimeEntry;

use App\Http\Requests\V1\BaseFormRequest;
use Carbon\CarbonTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

class TimeEntryDailyActivitiesRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|ValidationRule|\Illuminate\Contracts\Validation\Rule>>
     */
    public function rules(): array
    {
        return [
            // Day to return activities for, interpreted in the authenticated user's timezone.
            'date' => [
                'nullable',
                'date_format:Y-m-d',
            ],
        ];
    }

    public function getDate(CarbonTimeZone $timezone): Carbon
    {
        if ($this->input('date') === null) {
            return Carbon::now($timezone);
        }

        $date = Carbon::createFromFormat('Y-m-d', $this->input('date'), $timezone);
        if ($date === null) {
            throw new \LogicException('Validated date could not be parsed');
        }

        return $date;
    }
}
