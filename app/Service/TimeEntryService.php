<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\TimeEntryRoundingType;
use App\Support\Database\Sql;
use Illuminate\Support\Carbon;
use LogicException;

class TimeEntryService
{
    public function getStartSelectRawForRounding(?TimeEntryRoundingType $roundingType, ?int $roundingMinutes): string
    {
        if ($roundingType === null || $roundingMinutes === null) {
            return 'start';
        }
        if ($roundingMinutes < 1) {
            throw new LogicException('Rounding minutes must be greater than 0');
        }

        $origin = Sql::isPostgres()
            ? 'TIMESTAMP \'1970-01-01\''
            : Sql::timestampLiteral('1970-01-01 00:00:00');

        return Sql::dateBinMinutes(1, 'start', $origin);
    }

    public function getEndSelectRawForRounding(?TimeEntryRoundingType $roundingType, ?int $roundingMinutes): string
    {
        if ($roundingType === null || $roundingMinutes === null) {
            return Sql::coalesceEndWithTimestamp(Carbon::now()->toDateTimeString());
        }
        if ($roundingMinutes < 1) {
            throw new LogicException('Rounding minutes must be greater than 0');
        }
        $end = Sql::coalesceEndWithTimestamp(Carbon::now()->toDateTimeString());
        $start = $this->getStartSelectRawForRounding($roundingType, $roundingMinutes);
        if ($roundingType === TimeEntryRoundingType::Down) {
            return Sql::dateBinMinutes($roundingMinutes, $end, $start);
        } elseif ($roundingType === TimeEntryRoundingType::Up) {
            $roundedEnd = Sql::dateBinMinutes($roundingMinutes, $end, $start);
            // If end is already on a boundary, keep it; otherwise round up to next boundary
            return 'CASE WHEN '.$end.' = '.$roundedEnd.' '.
                   'THEN '.$end.' '.
                   'ELSE '.Sql::dateBinMinutes($roundingMinutes, Sql::addMinutes($end, $roundingMinutes), $start).' '.
                   'END';
        } elseif ($roundingType === TimeEntryRoundingType::Nearest) {
            return Sql::dateBinMinutes($roundingMinutes, Sql::addMinutes($end, $roundingMinutes / 2), $start);
        }
    }
}
