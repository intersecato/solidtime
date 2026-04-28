<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

final class Sql
{
    public static function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    public static function isMySql(): bool
    {
        return self::driver() === 'mysql';
    }

    public static function isPostgres(): bool
    {
        return self::driver() === 'pgsql';
    }

    public static function wrap(string $identifier): string
    {
        return match (self::driver()) {
            'mysql' => '`'.$identifier.'`',
            'sqlsrv' => '['.$identifier.']',
            default => '"'.$identifier.'"',
        };
    }

    public static function endColumn(): string
    {
        return self::wrap('end');
    }

    public static function timestampLiteral(string $timestamp): string
    {
        return "'".str_replace("'", "''", $timestamp)."'";
    }

    public static function currentTimestamp(): string
    {
        return self::isPostgres() ? 'now()' : 'current_timestamp';
    }

    public static function coalesceEndWithNow(): string
    {
        return 'coalesce('.self::endColumn().', '.self::currentTimestamp().')';
    }

    public static function coalesceEndWithTimestamp(string $timestamp): string
    {
        return 'coalesce('.self::endColumn().', '.self::timestampLiteral($timestamp).')';
    }

    public static function durationInSeconds(string $startExpression = 'start', ?string $endExpression = null): string
    {
        $endExpression ??= self::endColumn();

        return match (self::driver()) {
            'mysql' => 'timestampdiff(second, '.$startExpression.', '.$endExpression.')',
            'sqlite' => '(strftime(\'%s\', '.$endExpression.') - strftime(\'%s\', '.$startExpression.'))',
            'sqlsrv' => 'datediff(second, '.$startExpression.', '.$endExpression.')',
            default => 'extract(epoch from ('.$endExpression.' - '.$startExpression.'))',
        };
    }

    public static function sumDurationInSeconds(string $startExpression = 'start', ?string $endExpression = null): string
    {
        return 'round(sum('.self::durationInSeconds($startExpression, $endExpression).'))';
    }

    public static function sumBillableCost(string $durationExpression): string
    {
        $ratePerSecond = self::isPostgres()
            ? '(coalesce(billable_rate, 0)::float/60/60)'
            : '(coalesce(billable_rate, 0)/60/60)';

        return 'round(sum('.$durationExpression.' * '.$ratePerSecond.'))';
    }

    public static function dateWithTimezoneShift(string $dateExpression, int $timezoneShift): string
    {
        if ($timezoneShift === 0) {
            return $dateExpression;
        }

        $seconds = abs($timezoneShift);
        if (self::isMySql()) {
            $function = $timezoneShift > 0 ? 'date_add' : 'date_sub';

            return $function.'('.$dateExpression.', interval '.$seconds.' second)';
        }

        $operator = $timezoneShift > 0 ? '+' : '-';

        return $dateExpression.' '.$operator.' INTERVAL \''.$seconds.' second\'';
    }

    public static function date(string $dateExpression): string
    {
        return 'date('.$dateExpression.')';
    }

    public static function addMinutes(string $dateExpression, float $minutes): string
    {
        if (self::isMySql()) {
            $seconds = (int) round($minutes * 60);

            return 'timestampadd(second, '.$seconds.', '.$dateExpression.')';
        }

        return $dateExpression.' + interval \''.$minutes.' minutes\'';
    }

    public static function dateBinMinutes(int $minutes, string $dateExpression, string $originExpression): string
    {
        if (self::isMySql()) {
            $seconds = $minutes * 60;

            return 'timestampadd(second, floor(timestampdiff(second, '.$originExpression.', '.$dateExpression.') / '.$seconds.') * '.$seconds.', '.$originExpression.')';
        }

        return 'date_bin(\''.$minutes.' minutes\', '.$dateExpression.', '.$originExpression.')';
    }

    public static function monthGroup(string $dateExpression): string
    {
        return self::isMySql()
            ? 'date_format('.$dateExpression.', \'%Y-%m\')'
            : 'to_char('.$dateExpression.', \'YYYY-MM\')';
    }

    public static function yearGroup(string $dateExpression): string
    {
        return self::isMySql()
            ? 'date_format('.$dateExpression.', \'%Y\')'
            : 'to_char('.$dateExpression.', \'YYYY\')';
    }

    public static function weekGroup(string $dateExpression, string $startOfWeek): string
    {
        if (self::isMySql()) {
            $origin = 'date('.self::timestampLiteral($startOfWeek).')';
            $date = 'date('.$dateExpression.')';
            $offset = 'mod(mod(datediff('.$date.', '.$origin.'), 7) + 7, 7)';

            return 'date_format(date_sub('.$date.', interval '.$offset.' day), \'%Y-%m-%d\')';
        }

        return "to_char(date_bin('7 days', ".$dateExpression.", timestamp '".$startOfWeek."'), 'YYYY-MM-DD')";
    }
}
