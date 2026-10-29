<?php

namespace FluentAffiliate\Framework\Support;

use DateTimeZone;
use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use DateTime as PHPDateTime;
use FluentAffiliate\Framework\Foundation\App;

/**
 * The helpers DateTime and DateTimeImmutable share, so the two
 * classes answer the same questions the same way.
 */
trait DateTimeHelpersTrait
{
    /**
     * Get the default date format
     * 
     * @return string
     */
    public function getDateFormat()
    {   
        return 'Y-m-d H:i:s';
    }

    /**
     * Get the timezone the plugin chose in config/app.php ('timezone'),
     * or the site's timezone when it chose none.
     *
     * @return \DateTimeZone
     * @throws \Exception When the configured name is not a timezone.
     */
    protected static function configuredTimezone()
    {
        $app = App::getInstance();

        $timezone = $app ? $app->config->get('app.timezone') : null;

        return $timezone ? new DateTimeZone($timezone) : wp_timezone();
    }

    /**
     * Add or sub intervals given as a count and a unit, for example
     * add(3, 'day') calls addDays(3); the singular methods take no count.
     *
     * @param string $action add/sub
     * @param array $args
     * @throws \InvalidArgumentException
     */
    protected function addOrSub($action, $args)
    {
        if (count($args) !== 2 || !is_string($args[1])) {
            throw new InvalidArgumentException('Invalid interval string.');
        }

        [$value, $unit] = $args;

        $method = $action . ucfirst(rtrim(strtolower($unit), 's')) . 's';

        return $this->{$method}($value);
    }

    /**
     * Subtract a relative string such as '2 days 3 hours' by negating
     * every part of it; modify('-2 days 3 hours') would add the hours.
     *
     * @param string $interval
     * @throws \InvalidArgumentException
     */
    protected function subRelative(string $interval)
    {
        $parsed = date_parse($interval);
        $relative = $parsed['relative'] ?? null;

        if ($parsed['error_count'] || !$relative) {
            throw new InvalidArgumentException('Invalid interval string.');
        }

        // Like PHP's sub(), which rejects these in a DateInterval.
        foreach (['weekday', 'first_day_of_month', 'last_day_of_month'] as $special) {
            if (isset($relative[$special])) {
                throw new InvalidArgumentException(
                    'Only non-special relative time specifications are supported for subtraction.'
                );
            }
        }

        $parts = [];

        foreach (['year', 'month', 'day', 'hour', 'minute', 'second', 'weekdays'] as $unit) {
            if (!empty($relative[$unit])) {
                $parts[] = sprintf('%+d %s', -$relative[$unit], $unit);
            }
        }

        return $parts ? $this->modify(implode(' ', $parts)) : $this;
    }

    /**
     * Check if the current instance is a weekend: Saturday or Sunday,
     * by the ISO-8601 day number PHP's format('N') gives (6 and 7).
     * The WordPress week start doesn't change which days are weekends.
     *
     * For backward compatibility, passing 'saturday' still selects a
     * Friday and Saturday weekend; the parameter keeps its old name
     * because PHP 8 callers may pass it as a named argument.
     *
     * @param string|null $startOfWeek
     * @return bool
     *
     * @phpstan-ignore-next-line
     */
    public function isWeekend($startOfWeek = null): bool
    {
        $day = (int) $this->format('N');

        if ($startOfWeek === 'saturday') {
            return $day === 5 || $day === 6;
        }

        return $day >= 6;
    }

    /**
     * Check if the current instance is a weekday.
     *
     * @return bool
     */
    public function isWeekday()
    {
        return !$this->isWeekend();
    }

    /**
     * Check if the current instance is in the past.
     *
     * @return bool
     */
    public function isPast()
    {
        // Compare with current date and time
        return $this < new static();
    }

    /**
     * Check if the current instance is in the future.
     *
     * @return bool
     */
    public function isFuture()
    {
        return $this > new static();
    }

    /**
     * Check if the year is a leap year.
     *
     * @return boolean
     *
     * @phpstan-ignore-next-line
     */
    public function isLeapYear(): bool
    {
        return $this->format('L') === '1';
    }

    /**
     * Checks if the current time is midnight (00:00:00).
     *
     * @return bool
     */
    public function isMidnight()
    {
        return $this->format('H:i:s') === '00:00:00';
    }

    /**
     * Get the difference in years
     * 
     * @param  \DateTimeInterface $date
     * @return int
     */
    public function diffInYears($date)
    {
        return $this->diff($date)->y;
    }

    /**
     * Get the difference in months
     * 
     * @param  \DateTimeInterface $date
     * @return int
     */
    public function diffInMonths($date)
    {
        $diff = $this->diff($date);

        return $diff->y  * 12 + $diff->m;
    }

    /**
     * Get the difference in days
     * 
     * @param  \DateTimeInterface $date
     * @return int
     */
    public function diffInDays($date)
    {
        $diff = $this->diff($date);

        return $diff->days;
    }

    /**
     * Get the difference in hours
     * 
     * @param  \DateTimeInterface $date
     * @return int
     */
    public function diffInHours($date)
    {
        return intdiv($this->diffInSeconds($date), HOUR_IN_SECONDS);
    }

    /**
     * Get the difference in minutes
     * 
     * @param  \DateTimeInterface $date
     * @return int
     */
    public function diffInMinutes($date)
    {
        return intdiv($this->diffInSeconds($date), MINUTE_IN_SECONDS);
    }

    /**
     * Get the difference in seconds
     * 
     * @param  \DateTimeInterface $date
     * @return int
     */
    public function diffInSeconds($date)
    {
        // Elapsed time from the timestamps, so a DST change counts;
        // adding up diff()'s parts makes a 23-hour DST day 24 hours.
        $microseconds = ($this->getTimestamp() - $date->getTimestamp()) * 1000000
            + (int) $this->format('u') - (int) $date->format('u');

        return intdiv(abs($microseconds), 1000000);
    }

    /**
     * Get human friendly time difference (2 hours ago/ 2 hours from now)
     * 
     * @param  \DateTimeInterface|string|int $from The datetime to compare from
     * @param  \DateTimeInterface|string|int $to The datetime to compare to

     * @return string Human readable string, ie. 5 days ago/from now
     */
    public function diffForHumans($from = null, $to = null)
    {
        // Use the current object's timestamp if $from (and $to) is null
        // This is because ORM's datetime field can call it without params.
        if (is_null($from)) {
            $from = $this->getTimestamp();
        } elseif ($from instanceof DateTimeInterface) {
            $from = $from->getTimestamp();
        } elseif (!is_numeric($from)) {
            // A date string is read in the default timezone, like
            // every other date string these classes take.
            $from = (new PHPDateTime($from, $this->getDefaultTimezone()))
                ->getTimestamp();
        }

        // Use the current time as $to if not provided
        if (is_null($to)) {
            $to = time();
        } elseif ($to instanceof DateTimeInterface) {
            $to = $to->getTimestamp();
        } elseif (!is_numeric($to)) {
            $to = (new PHPDateTime($to, $this->getDefaultTimezone()))
                ->getTimestamp();
        }

        // Calculate the difference in seconds
        $diffInSeconds = abs($to - $from);
        $dateTimeDiff = human_time_diff($from, $to);

        // Determine if the difference is in the past or future
        if ($from > $to) {
            // The "from" time is earlier than "to" (future)
            return sprintf(__('%s from now'), $dateTimeDiff);
        } else {
            // The "from" time is later than "to" (older)
            if ($diffInSeconds > MINUTE_IN_SECONDS) {
                return sprintf(__('%s ago'), $dateTimeDiff);
            }

            // If difference is less than 1 minute, return just now
            return __('just now');
        }
    }

    /**
     * Given a date in the timezone of the site, returns that date in UTC.
     *
     * Requires and returns a date in the Y-m-d H:i:s format.
     * 
     * Return format can be overridden using the $format parameter.
     *
     * @param string $dateString The date to be converted, in the timezone of the site.
     * @param string $format The format string for the returned date. Default 'Y-m-d H:i:s'.
     * @see https://developer.wordpress.org/reference/functions/get_gmt_from_date/
     * 
     * @return string Formatted version of the date, in UTC.
     */
    public function toUTC($dateString, $format = 'Y-m-d H:i:s')
    {
        return get_gmt_from_date($dateString, $format);
    }

    /**
     * Return the ISO-8601 string
     *
     * @see https://stackoverflow.com/a/11173072/741747
     *
     * @return mixed
     */
    public function toJSON()
    {
        return gmdate('c', $this->getTimestamp());
    }

    /**
     * Returns the formatted string
     * 
     * @return string
     */
    public function toString()
    {
        return (string) $this;
    }

    /**
     * Return only the date part as string
     * 
     * @return string
     */
    public function toDateString()
    {
        return (string) $this->format('Y-m-d');
    }

    /**
     * Return only the time part as string
     * 
     * @return string
     */
    public function toTimeString()
    {
        return (string) $this->format('H:i:s');
    }

    /**
     * Getter to get an unit of DateTime
     * @param  string $key
     * @return string|null
     */
    public function __get($key)
    {
        $formats = [
            'year' => 'Y',
            'month' => 'm',
            'day' => 'd',
            'hour' => 'H',
            'minute' => 'i',
            'second' => 's',
        ];

        return isset($formats[$key]) ? $this->format($formats[$key]) : null;
    }

    /**
     * Get the quarter of the year (1 - 4).
     *
     * @return int
     */
    public function getQuarter()
    {
        return (int) ceil((int) $this->format('n') / 3);
    }

    /**
     * Get the first year of the decade, e.g. 2020 for 2026.
     *
     * @return int
     */
    public function getDecade()
    {
        return (int) floor((int) $this->format('Y') / 10) * 10;
    }

    /**
     * Set the date to the start of its week, following WordPress's
     * start_of_week option (0 = Sunday ... 6 = Saturday) the way
     * get_weekstartend() does.
     *
     * @return static
     */
    public function startOfWeek()
    {
        $diff = ((int) $this->format('w') - $this->weekStartsOn() + 7) % 7;

        return $this->sub(new DateInterval("P{$diff}D"))->startOfDay();
    }

    /**
     * Set the date to the end of its week (see startOfWeek()).
     *
     * @return static
     */
    public function endOfWeek()
    {
        $lastDay = ($this->weekStartsOn() + 6) % 7;

        $diff = ($lastDay - (int) $this->format('w') + 7) % 7;

        return $this->add(new DateInterval("P{$diff}D"))->endOfDay();
    }

    /**
     * The day WordPress's weeks start on: 0 (Sunday) to 6 (Saturday).
     *
     * @return int
     */
    protected function weekStartsOn()
    {
        return max(0, min(6, (int) get_option('start_of_week')));
    }

    /**
     * Set the time to the last microsecond of the day.
     *
     * @return static
     */
    public function endOfDay()
    {
        return $this->setTime(23, 59, 59, 999999);
    }

    /**
     * Set the date to the last day of its quarter, at the end of the day.
     *
     * @return static
     */
    public function endOfQuarter()
    {
        $year = (int) $this->format('Y');
        $month = $this->getQuarter() * 3;

        $first = $this->setDate($year, $month, 1);

        return $first->setDate($year, $month, (int) $first->format('t'))
            ->endOfDay();
    }

    /**
     * Create an instance from date and time parts, using PHP's own
     * setDate() and setTime(). The seconds may have a fraction, down to
     * the microsecond; a part out of range throws.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param int|float $second
     * @param string|\DateTimeZone|null $tz
     * @return static
     * @throws \InvalidArgumentException
     */
    public static function createFromDate(
        $year, $month, $day, $hour = 0, $minute = 0, $second = 0, $tz = null
    ) {
        if (
            !checkdate((int) $month, (int) $day, (int) $year)
            || $hour < 0 || $hour > 23
            || $minute < 0 || $minute > 59
            || $second < 0 || $second >= 60
        ) {
            throw new InvalidArgumentException(sprintf(
                "Invalid date '%04d-%02d-%02d %02d:%02d:%02d'",
                $year, $month, $day, $hour, $minute, $second
            ));
        }

        $seconds = (int) $second;
        $microseconds = min(999999, (int) round(($second - $seconds) * 1000000));

        return (new static('now', $tz))
            ->setDate((int) $year, (int) $month, (int) $day)
            ->setTime((int) $hour, (int) $minute, $seconds, $microseconds);
    }

    /**
     * The moment of a date as a UTC clock time with microseconds. UTC
     * has no DST or odd historical offsets, so reading it back gives
     * the same moment.
     *
     * @param DateTimeInterface $datetime
     * @return string
     */
    protected static function utcTime(DateTimeInterface $datetime)
    {
        return PHPDateTime::createFromFormat('U.u', $datetime->format('U.u'))
            ->format('Y-m-d H:i:s.u');
    }
}
