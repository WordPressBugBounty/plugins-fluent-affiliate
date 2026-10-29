<?php

namespace FluentAffiliate\Framework\Support;

use Exception;
use DateTimeZone;
use DateInterval;
use DateTimeInterface;
use DateTime as PHPDateTime;
use InvalidArgumentException;

class DateTime extends PHPDateTime
{
    use DateTimeHelpersTrait;

    /**
     * $singularUnits for checking during dynamic calls
     * @var array
     */
    protected static $singularUnits = [
        'year', 'month', 'week', 'day', 'hour', 'minute', 'second',
    ];

    /**
     * $pluralUnits for checking during dynamic calls
     * @var array
     */
    protected static $pluralUnits = [
        'years','months', 'weeks', 'days', 'hours', 'minutes', 'seconds'
    ];

    /**
     * Construct the DateTime Object
     * 
     * @param string $datetime
     * @param \DateTimeZone $timezone|null
     */
    public function __construct($datetime = 'now', $timezone = null)
    {
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }

        // A date object keeps its moment and timezone, as PHP's
        // createFromInterface() does; a given timezone converts it.
        if ($datetime instanceof DateTimeInterface) {
            parent::__construct(
                static::utcTime($datetime), new DateTimeZone('UTC')
            );

            $this->setTimezone($timezone ?? $datetime->getTimezone());

            return;
        }

        $timezone ??= static::getDefaultTimezone();

        if (
            is_numeric($datetime)
            || str_starts_with((string) $datetime, '@')
        ) {
            $datetime = '@' . ltrim((string) $datetime, '@');
        }

        parent::__construct($datetime, $timezone);
    }

    /**
     * Create a new DateTime Object with current time
     * 
     * @param string|null $tz
     * @return static
     */
    public static function now($tz = null)
    {
        return static::create('now', $tz);
    }

    /**
     * Create a new DateTime Object with today's time
     * 
     * @param string|null $tz
     * @return static
     */
    public static function today($tz = null)
    {
        return static::create('today', $tz)->startOfDay();
    }

    /**
     * Create a new DateTime Object with yesterday's time
     * 
     * @param string|null $tz
     * @return static
     */
    public static function yesterday($tz = null)
    {
        return static::create('now', $tz)->modify('-1 day')->startOfDay();
    }

    /**
     * Create a new DateTime Object with tomorrow's time
     * 
     * @param string|null $tz
     * @return static
     */
    public static function tomorrow($tz = null)
    {
        return static::create('now', $tz)->modify('+1 day')->startOfDay();
    }

    /**
     * Create a new DateTime Object with the current week's starting time.
     * 
     * @param string|null $tz
     * @return static
     */
    public static function currentWeek($tz = null)
    {
        return static::create('now', $tz)->startOfWeek();
    }

    /**
     * Create a new DateTime Object with last week's starting time
     * 
     * @param string|null $tz
     * @return static
     */
    public static function lastWeek($tz = null)
    {
        return static::create('now', $tz)->subWeek()->startOfWeek();
    }

    /**
     * Create a new DateTime Object with next week's starting time
     * 
     * @param string|null $tz
     * @return static
     */
    public static function nextWeek($tz = null)
    {
        return static::create('now', $tz)->addWeek()->startOfWeek();
    }

    /**
     * Create a new DateTime Object with current month's starting time
     * 
     * @param string|null $tz
     * @return static
     */
    public static function currentMonth($tz = null)
    {
        return static::create('now', $tz)->startOfMonth();
    }

    /**
     * Create a new DateTime Object with last month's starting time
     *
     * @param string|null $tz
     * @return static
     */
    public static function lastMonth($tz = null)
    {
        return static::create('now', $tz)->subMonth()->startOfMonth();
    }

    /**
     * Create a new DateTime Object with next month's starting time
     *
     * @param string|null $tz
     * @return static
     */
    public static function nextMonth($tz = null)
    {
        return static::create('now', $tz)->addMonth()->startOfMonth();
    }

    /**
     * Create a new DateTime Object with current year's starting time
     * 
     * @param string|null $tz
     * @return static
     */
    public static function currentYear($tz = null)
    {
        return static::create('now', $tz)->startOfYear();
    }

    /**
     * Create a new DateTime Object with last year's starting time
     * 
     * @param string|null $tz
     * @return static
     */
    public static function lastYear($tz = null)
    {
        return static::create('now', $tz)->subYear()->startOfYear();
    }

    /**
     * Create a new DateTime Object with next year's starting time
     * 
     * @param string|null $tz
     * @return static
     */
    public static function nextYear($tz = null)
    {
        return static::create('now', $tz)->addYear()->startOfYear();
    }

    /**
     * Get the default timezone: the plugin's configured one, else the
     * site's.
     *
     * @return \DateTimeZone
     */
    public function getDefaultTimezone()
    {
        return static::configuredTimezone();
    }

    /**
     * Set the timezone
     *
     * @return $this
     */
    public function timezone($tz)
    {
        if (is_string($tz)) {
            $tz = new DateTimeZone($tz);
        }

        return $this->setTimezone($tz);
    }

    /**
     * Check if the current instance is between two dates
     *
     * @param string|DateTimeInterface $date1
     * @param string|DateTimeInterface $date2,
     * @return bool
     *
     * @phpstan-ignore-next-line
     */
    public function between($date1, $date2): bool
    {
        if (!$date1 instanceof DateTimeInterface) {
            $date1 = new DateTime($date1);
        }

        if (!$date2 instanceof DateTimeInterface) {
            $date2 = new DateTime($date2);
        }

        $current = $this->getTimestamp();
        $start   = min($date1->getTimestamp(), $date2->getTimestamp());
        $end     = max($date1->getTimestamp(), $date2->getTimestamp());

        return $current >= $start && $current <= $end;
    }

    /**
     * Create a DateTime object from a string, UNIX timestamp,
     * or other DateTimeInterface object.
     * 
     * @param  string|int|\DateTimeInterface  $time
     * @return static
     * @throws \Exception
     */
    public static function create($time = null, $tz = null)
    {
        if (func_num_args() > 2) {
            return static::createFromDate(...func_get_args());
        }

        if (is_null($tz)) {
            $timezone = (new static)->getDefaultTimezone();
        } else {
            $timezone = is_string($tz) ? new DateTimeZone($tz) : $tz;
        }

        if (!$timezone instanceof DateTimeZone) {
            throw new InvalidArgumentException('Invalid timezone.');
        }

        // A date object keeps its own timezone unless one is given.
        if ($time instanceof DateTimeInterface) {
            return new static($time, is_null($tz) ? null : $timezone);
        }

        // A number is a Unix timestamp, as in PHP.
        if (is_numeric($time)) {
            return (new static('@' . $time))->setTimezone($timezone);
        }

        // A string without its own timezone is read in $timezone.
        return new static($time ?? 'now', $timezone);
    }

    /**
     * Check if the given datetime string has the timezone information
     * attached, such as Z, +05:00, +0500, UTC, EST or Asia/Dhaka, as
     * PHP's own parser reads it.
     *
     * @param  string $datetimeString
     * @return boolean
     */
    public function hasTimezone($datetimeString)
    {
        return !empty(date_parse((string) $datetimeString)['is_localtime']);
    }

    /**
     * Create a DateTime from a formatted string. Unlike PHP's, it throws
     * instead of returning false, and the parts the format leaves out
     * below its smallest unit start at their lowest value instead of
     * the current time: Y-m-d is midnight, Y-m the 1st, Y January 1st.
     *
     * @param  string $format
     * @param  string $datetimeString
     * @param  string|\DateTimeZone|null $timezone
     * @return static
     * @throws InvalidArgumentException
     */
    #[\ReturnTypeWillChange]
    public static function createFromFormat($format, $datetimeString, $timezone = null)
    {
        if (is_null($timezone)) {
            $timezone = (new static)->getDefaultTimezone();
        } else {
            $timezone = is_string($timezone) ? new DateTimeZone($timezone) : $timezone;
        }

        if (!$timezone instanceof DateTimeZone) {
            throw new InvalidArgumentException('Invalid timezone.');
        }

        // PHP 8 throws on a NUL byte and PHP 7.4 cuts the string at
        // it, so it is rejected like any other string that isn't a date.
        $dateTime = str_contains($datetimeString, "\0")
            ? false
            : PHPDateTime::createFromFormat($format, $datetimeString, $timezone);

        if ($dateTime === false) {
            throw new InvalidArgumentException(
                "Unable to create datetime from: {$datetimeString}."
            );
        }

        $parsed = date_parse_from_format($format, $datetimeString);

        // PHP fills a missing day with today's, which can roll the month
        // over (Feb 30 becomes Mar 2), so the month comes from $parsed.
        if ($parsed['day'] === false && $parsed['month'] !== false) {
            $dateTime->setDate((int) $dateTime->format('Y'), $parsed['month'], 1);
        } elseif ($parsed['day'] === false && $parsed['year'] !== false) {
            $dateTime->setDate($parsed['year'], 1, 1);
        }

        if ($parsed['hour'] === false) {
            $dateTime->setTime(0, 0);
        }

        return new static(
            $dateTime->format('Y-m-d H:i:s.u'), $dateTime->getTimezone()
        );
    }

    /**
     * Given a date in UTC or GMT timezone, returns
     * that date in the timezone of the site.
     *
     * Requires a date in the Y-m-d H:i:s format.
     * 
     * Default return format of 'Y-m-d H:i:s' can be
     * overridden using the `$format` parameter.
     *
     * @param string $dateString The date to be converted, in UTC or GMT timezone.
     * @param string $format      The format string for the returned date. Default 'Y-m-d H:i:s'.
     * @see https://developer.wordpress.org/reference/functions/get_date_from_gmt/
     * 
     * @return string Formatted version of the date, in the site's timezone.
     */
    public static function createFromUTC($dateString, $format = 'Y-m-d H:i:s')
    {
        $localString = get_date_from_gmt($dateString, $format);

        // WordPress gives the site's clock time, so it is read in the
        // site's timezone whatever the plugin's default is. Read it back
        // with the format that wrote it; the constructor takes formats
        // createFromFormat() can't read, such as 'c'.
        try {
            return static::createFromFormat($format, $localString, wp_timezone());
        } catch (InvalidArgumentException $e) {
            return new static($localString, wp_timezone());
        }
    }

    /**
     * Parse a datetime string
     * @param  string|\DateTimeInterface $datetimeString
     * @param  string $timezone
     * @return static
     * @throws InvalidArgumentException
     */
    public static function parse($datetimeString, $timezone = null)
    {
        try {
            return new static($datetimeString, $timezone);
        } catch (Exception $e) {
            throw new InvalidArgumentException(
                'Unable to handle datetime.', 0, $e
            );
        }
    }

    /**
     * Add inetrvals, for example:
     * 
     * add(1, day)
     * add('2 day 8 hours 22 minutes')
     * 
     * @param \DateInterval|string $interval
     * @return $this
     */
    #[\ReturnTypeWillChange]
    public function add($interval)
    {
        if ($interval instanceof DateInterval) {
            return parent::add($interval);
        } elseif (func_num_args() === 1 && is_string($interval)) {
            return $this->modify('+'.$interval);
        }

        return $this->addOrSub('add', func_get_args());
    }

    /**
     * Substruct inetrvals, for example:
     * 
     * sub(1, day)
     * sub('2 day 8 hours 22 minutes')
     * 
     * @param \DateInterval $interval (optional)
     * @return $this
     */
    #[\ReturnTypeWillChange]
    public function sub($interval)
    {
        if ($interval instanceof DateInterval) {
            return parent::sub($interval);
        } elseif (func_num_args() === 1 && is_string($interval)) {
            return $this->subRelative($interval);
        }

        return $this->addOrSub('sub', func_get_args());
    }

    /**
     * Adds the given number of seconds to the current date and time.
     *
     * @param int $seconds The number of seconds to add.
     * @return $this The current instance for method chaining.
     */
    public function addSeconds(int $seconds)
    {
        return $this->add("{$seconds} seconds");
    }

    /**
     * Adds exactly one second to the current date and time.
     * 
     * @return $this
     */
    public function addSecond()
    {
        return $this->add("1 second");
    }

    /**
     * Adds the given number of minutes to the current date and time.
     *
     * @param int $minutes The number of minutes to add.
     * @return $this The current instance for method chaining.
     */
    public function addMinutes(int $minutes)
    {
        return $this->add("{$minutes} minutes");
    }

    /**
     * Adds exactly one minute to the current date and time.
     * 
     * @return $this
     */
    public function addMinute()
    {
        return $this->add("1 minute");
    }

    /**
     * Adds the given number of hours to the current date and time.
     *
     * @param int $hours The number of hours to add.
     * @return $this The current instance for method chaining.
     */
    public function addHours(int $hours)
    {
        return $this->add("{$hours} hours");
    }

    /**
     * Adds exactly one hour to the current date and time.
     *
     * @return $this
     */
    public function addHour()
    {
        return $this->add("1 hour");
    }

    /**
     * Adds the given number of days to the current date and time.
     *
     * @param int $days The number of days to add.
     * @return $this The current instance for method chaining.
     */
    public function addDays(int $days)
    {
        return $this->add("{$days} days");
    }


    /**
     * Adds exactly one day to the current date and time.
     *
     * @return $this
     */
    public function addDay()
    {
        return $this->add("1 day");
    }

    /**
     * Adds the given number of weeks to the current date and time.
     *
     * @param int $weeks The number of weeks to add.
     * @return $this The current instance for method chaining.
     */
    public function addWeeks(int $weeks)
    {
        return $this->add("{$weeks} weeks");
    }

    /**
     * Adds exactly one week to the current date and time.
     *
     * @return $this
     */
    public function addWeek()
    {
        return $this->add("1 week");
    }

    /**
     * Adds the given number of months to the current date and time.
     *
     * @param int $months The number of months to add.
     * @return $this The current instance for method chaining.
     */
    public function addMonths(int $months)
    {
        return $this->add("{$months} months");
    }

    /**
     * Adds exactly one month to the current date and time.
     *
     * @return $this
     */
    public function addMonth()
    {
        return $this->add("1 month");
    }

    /**
     * Adds the given number of years to the current date and time.
     *
     * @param int $years The number of years to add.
     * @return $this The current instance for method chaining.
     */
    public function addYears(int $years)
    {
        return $this->add("{$years} years");
    }

    /**
     * Adds exactly one year to the current date and time.
     * 
     * @return $this
     */
    public function addYear()
    {
        return $this->add("1 year");
    }

    /**
     * Add a quarter (3 months) to the current date.
     *
     * @return $this
     */
    public function addQuarter()
    {
        return $this->add(new DateInterval('P3M'));
    }

    /**
     * Add a decade (10 years) to the current date.
     *
     * @return $this
     */
    public function addDecade()
    {
        return $this->add(new DateInterval('P10Y'));
    }

    /**
     * Add the given number of quarters (3 months each).
     *
     * @param int $quarters
     * @return $this
     */
    public function addQuarters(int $quarters)
    {
        return $this->addMonths($quarters * 3);
    }

    /**
     * Add the given number of decades (10 years each).
     *
     * @param int $decades
     * @return $this
     */
    public function addDecades(int $decades)
    {
        return $this->addYears($decades * 10);
    }

     /**
     * Subtracts the given number of seconds from the current date and time.
     *
     * @param int $seconds The number of seconds to subtract.
     * @return $this The current instance for method chaining.
     */
    public function subSeconds(int $seconds)
    {
        return $this->sub("{$seconds} seconds");
    }

    /**
     * Subtracts one second from the current date and time.
     *
     * @return $this The current instance for method chaining.
     */
    public function subSecond()
    {
        return $this->sub("1 second");
    }

    /**
     * Subtracts the given number of minutes from the current date and time.
     *
     * @param int $minutes The number of minutes to subtract.
     * @return $this The current instance for method chaining.
     */
    public function subMinutes(int $minutes)
    {
        return $this->sub("{$minutes} minutes");
    }

    /**
     * Subtracts one minute from the current date and time.
     *
     * @return $this The current instance for method chaining.
     */
    public function subMinute()
    {
        return $this->sub("1 minute");
    }

    /**
     * Subtracts the given number of hours from the current date and time.
     *
     * @param int $hours The number of hours to subtract.
     * @return $this The current instance for method chaining.
     */
    public function subHours(int $hours)
    {
        return $this->sub("{$hours} hours");
    }

    /**
     * Subtracts one hour from the current date and time.
     *
     * @return $this The current instance for method chaining.
     */
    public function subHour()
    {
        return $this->sub("1 hour");
    }

    /**
     * Subtracts the given number of days from the current date and time.
     *
     * @param int $days The number of days to subtract.
     * @return $this The current instance for method chaining.
     */
    public function subDays(int $days)
    {
        return $this->sub("{$days} days");
    }

    /**
     * Subtracts one day from the current date and time.
     *
     * @return $this The current instance for method chaining.
     */
    public function subDay()
    {
        return $this->sub("1 day");
    }

    /**
     * Subtracts the given number of weeks from the current date and time.
     *
     * @param int $weeks The number of weeks to subtract.
     * @return $this The current instance for method chaining.
     */
    public function subWeeks(int $weeks)
    {
        return $this->sub("{$weeks} weeks");
    }

    /**
     * Subtracts one week from the current date and time.
     *
     * @return $this The current instance for method chaining.
     */
    public function subWeek()
    {
        return $this->sub("1 week");
    }

    /**
     * Subtracts the given number of months from the current date and time.
     *
     * @param int $months The number of months to subtract.
     * @return $this The current instance for method chaining.
     */
    public function subMonths(int $months)
    {
        return $this->sub("{$months} months");
    }

    /**
     * Subtracts one month from the current date and time.
     *
     * @return $this The current instance for method chaining.
     */
    public function subMonth()
    {
        return $this->sub("1 month");
    }

    /**
     * Subtracts the given number of years from the current date and time.
     *
     * @param int $years The number of years to subtract.
     * @return $this The current instance for method chaining.
     */
    public function subYears(int $years)
    {
        return $this->sub("{$years} years");
    }

    /**
     * Subtracts one year from the current date and time.
     *
     * @return $this The current instance for method chaining.
     */
    public function subYear()
    {
        return $this->sub("1 year");
    }

    /**
     * Subtract a quarter (3 months) from the current date.
     *
     * @return $this
     */
    public function subQuarter()
    {
        return $this->sub(new DateInterval('P3M'));
    }

    /**
     * Subtract a decade (10 years) from the current date.
     *
     * @return $this
     */
    public function subDecade()
    {
        return $this->sub(new DateInterval('P10Y'));
    }

    /**
     * Subtract the given number of quarters (3 months each).
     *
     * @param int $quarters
     * @return $this
     */
    public function subQuarters(int $quarters)
    {
        return $this->subMonths($quarters * 3);
    }

    /**
     * Subtract the given number of decades (10 years each).
     *
     * @param int $decades
     * @return $this
     */
    public function subDecades(int $decades)
    {
        return $this->subYears($decades * 10);
    }

    /**
     * Set the date to start of the decade.
     * @return $this
     */
    public function startOfDecade()
    {
        $year = (int) $this->format('Y');
        // Find the start of the decade by subtracting the remainder
        // of the division by 10 from the current year.
        $startOfDecadeYear = $year - ($year % 10);
        
        // Set the date to the start of the decade (January 1st)
        return $this->setDate($startOfDecadeYear, 1, 1)->setTime(0, 0);
    }

    /**
     * Set the date to end of the decade.
     * @return $this
     */
    public function endOfDecade()
    {
        $year = (int) $this->format('Y');
        // Find the last year of the decade by adding 9 to the current
        // year and subtracting the remainder of the division by 10.
        $endOfDecadeYear = $year + (9 - ($year % 10));

        // Set the date to December 31st of that year at 23:59:59
        return $this->setDate($endOfDecadeYear, 12, 31)->setTime(23, 59, 59);
    }

    /**
     * Sets start of the year in the current dateTime
     * 
     * @return $this
     */
    public function startOfYear()
    {
        return $this->modify('first day of January')->startOfDay();
    }

    /**
     * Sets end of the year in the current dateTime
     * 
     * @return $this
     */
    public function endOfYear()
    {
        return $this->modify('last day of December')->endOfDay();
    }

    /**
     * Sets the date to the first day of the current quarter at 00:00:00.
     *
     * @return $this
     */
    public function startOfQuarter()
    {
        $month = (int) $this->format('m');
        // Determine the start month of the current quarter
        if ($month <= 3) {
            $startMonth = 1; // Q1 starts in January
        } elseif ($month <= 6) {
            $startMonth = 4; // Q2 starts in April
        } elseif ($month <= 9) {
            $startMonth = 7; // Q3 starts in July
        } else {
            $startMonth = 10; // Q4 starts in October
        }

        // Set the date to the first day of the quarter at 00:00:00
        return $this->setDate((int) $this->format('Y'), $startMonth, 1)->setTime(0, 0, 0);
    }

    /**
     * Sets start of the month in the current dateTime
     * 
     * @return $this
     */
    public function startOfMonth()
    {
        return $this->modify('first day of this month')->startOfDay();
    }

    /**
     * Sets end of the month in the current dateTime
     * 
     * @return $this
     */
    public function endOfMonth()
    {
        return $this->modify('last day of this month')->endOfDay();
    }

    /**
     * Sets start of the day in the current dateTime
     * 
     * @return $this
     */
    public function startOfDay()
    {
        return $this->setTime(0, 0, 0, 0);
    }

    /**
     * Sets start of the hour in the current DateTime object
     * 
     * @return $this
     */
    public function startOfHour()
    {
        return $this->setTime($this->format('H'), 0, 0, 0);
    }

    /**
     * Sets end of the hour in the current DateTime object
     * 
     * @return $this
     */
    public function endOfHour()
    {
        return $this->setTime($this->format('H'), 59, 59, 999999);
    }

    /**
     * Sets start of the minute in the current DateTime object
     * 
     * @return $this
     */
    public function startOfMinute()
    {
        $hour = $this->format('H');
        $minute = $this->format('i');
        return $this->setTime($hour, $minute, 0, 0);
    }

    /**
     * Sets end of the minute in the current DateTime object
     * 
     * @return $this
     */
    public function endOfMinute()
    {
        $hour = $this->format('H');
        $minute = $this->format('i');
        return $this->setTime($hour, $minute, 59, 999999);
    }

    /**
     * Check if the current instance is the same day as another DateTime instance.
     *
     * @param DateTime $other
     * @return bool
     */
    public function isSameDay(DateTime $other)
    {
        return $this->format('Y-m-d') === $other->format('Y-m-d');
    }

    /**
     * Clone the current Object
     * 
     * @return \FluentAffiliate\Framework\Support\DateTime
     */
    public function copy()
    {
        return clone $this;
    }

    /**
     * Returns the formatted string
     * 
     * @return string
     */
    public function __toString()
    {
        return $this->format($this->getDateFormat());
    }

    /**
     * Setter to set an unit of DateTime
     * @param  string $key
     * @param  string|int $value
     * @return $this
     */
    public function __set($key, $value)
    {
        if ($key == 'year') {
            return $this->setDate($value, $this->format('m'), $this->format('d'));
        } elseif ($key == 'month') {
            return $this->setDate($this->format('Y'), $value, $this->format('d'));
        } elseif ($key == 'day') {
            return $this->setDate($this->format('Y'), $this->format('m'), $value);
        } elseif ($key == 'hour') {
            return $this->setTime($value, $this->format('i'), $this->format('s'));
        } elseif ($key == 'minute') {
            return $this->setTime($this->format('H'), $value, $this->format('s'));
        } elseif ($key == 'second') {
           return $this->setTime($this->format('H'), $this->format('i'), $value);
        }
    }

    /**
     * Handle Dynamic calls (add/sub)
     * 
     * @param  string $method
     * @param  array $params
     * @return $this
     */
    public function __call($method, $params)
    {
        // Dynamic Setter/Getter
        if (strpos($method, 'set') === 0) {
            $unit = strtolower(substr($method, 3));
            if ($params && in_array($unit, static::$singularUnits)) {
                $this->{$unit} = reset($params);
                return $this;
            }
        } elseif (strpos($method, 'get') === 0) {
            $unit = strtolower(substr($method, 3));
            if (in_array($unit, static::$singularUnits)) {
                return $this->{$unit};
            }
        }

        // Dynamic adder/subtractor
        if (strpos($method, 'add') === 0) {
            $action = '+';
        } elseif (strpos($method, 'sub') === 0) {
            $action = '-';
        }

        if (isset($action) && in_array($action, ['+', '-'])) {

            if (!$params) {
                $duration = 1;
            } else {
                $duration = reset($params);
            }


            $unit = strtolower(substr($method, 3));

            $units = array_merge(static::$singularUnits, static::$pluralUnits);

            if (in_array($unit, $units)) {
                return $this->modify("{$action}{$duration}{$unit}");
            }
        }

        throw new InvalidArgumentException("Call to undefined method {$method}.");
    }
}
