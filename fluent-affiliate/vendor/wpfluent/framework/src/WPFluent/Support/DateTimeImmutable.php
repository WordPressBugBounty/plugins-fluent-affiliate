<?php

namespace FluentAffiliate\Framework\Support;

use Exception;
use DateTimeZone;
use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use DateTimeImmutable as PHPDateTimeImmutable;

/**
 * @mixin \DateTimeImmutable
 * @method self addYear(int $value = 1)
 * @method self addYears(int $value = 1)
 * @method self addMonth(int $value = 1)
 * @method self addMonths(int $value = 1)
 * @method self addWeek(int $value = 1)
 * @method self addWeeks(int $value = 1)
 * @method self addDay(int $value = 1)
 * @method self addDays(int $value = 1)
 * @method self addHour(int $value = 1)
 * @method self addHours(int $value = 1)
 * @method self addMinute(int $value = 1)
 * @method self addMinutes(int $value = 1)
 * @method self addSecond(int $value = 1)
 * @method self addSeconds(int $value = 1)
 * @method self addQuarter(int $value = 1)
 * @method self addQuarters(int $value = 1)
 * @method self addDecade(int $value = 1)
 * @method self addDecades(int $value = 1)
 *
 * @method self subYear(int $value = 1)
 * @method self subYears(int $value = 1)
 * @method self subMonth(int $value = 1)
 * @method self subMonths(int $value = 1)
 * @method self subWeek(int $value = 1)
 * @method self subWeeks(int $value = 1)
 * @method self subDay(int $value = 1)
 * @method self subDays(int $value = 1)
 * @method self subHour(int $value = 1)
 * @method self subHours(int $value = 1)
 * @method self subMinute(int $value = 1)
 * @method self subMinutes(int $value = 1)
 * @method self subSecond(int $value = 1)
 * @method self subSeconds(int $value = 1)
 * @method self subQuarter(int $value = 1)
 * @method self subQuarters(int $value = 1)
 * @method self subDecade(int $value = 1)
 * @method self subDecades(int $value = 1)
 *
 * @method string getYear()
 * @method string getMonth()
 * @method string getDay()
 * @method string getHour()
 * @method string getMinute()
 * @method string getSecond()
 *
 * @method self setYear(int $value)
 * @method self setMonth(int $value)
 * @method self setDay(int $value)
 * @method self setHour(int $value)
 * @method self setMinute(int $value)
 * @method self setSecond(int $value)
 */
class DateTimeImmutable extends PHPDateTimeImmutable
{
    use DateTimeHelpersTrait;

    /** @var string[] Singular time units */
    protected const SINGULAR_UNITS = [
        'year', 'month', 'week', 'day', 'hour',
        'minute', 'second', 'quarter', 'decade'
    ];

    /** @var string[] Plural time units */
    protected const PLURAL_UNITS = [
        'years', 'months', 'weeks', 'days', 'hours',
        'minutes', 'seconds', 'quarters', 'decades'
    ];

    /**
     * Construct the object.
     *
     * @param string|int|DateTimeInterface $datetime
     * @param DateTimeZone|string|null $timezone
     */
    public function __construct($datetime = 'now', $timezone = null)
    {
        if (is_string($timezone)) {
            $timezone = new DateTimeZone($timezone);
        }

        // A date object keeps its moment and timezone, as PHP's
        // createFromInterface() does; a given timezone converts it.
        if ($datetime instanceof DateTimeInterface) {
            $timezone ??= $datetime->getTimezone();

            $moment = (new \DateTime(
                static::utcTime($datetime), new DateTimeZone('UTC')
            ))->setTimezone($timezone);

            parent::__construct($moment->format('Y-m-d H:i:s.u'), $timezone);

            // In the hour a DST change repeats, the clock time names two
            // moments and PHP takes the first; the offset names the other.
            if ($this->getTimestamp() !== $moment->getTimestamp()) {
                parent::__construct($moment->format('Y-m-d H:i:s.uP'));
            }

            return;
        }

        if (
        	is_numeric($datetime)
        	|| str_starts_with((string) $datetime, '@')
        ) {
            $datetime = '@' . ltrim((string) $datetime, '@');
        }

        parent::__construct(
            $datetime, $timezone ?? static::getDefaultTimezone()
        );
    }

    /**
     * Get the default timezone: the plugin's configured one, else the
     * site's.
     *
     * @return DateTimeZone
     */
    public static function getDefaultTimezone()
    {
        return static::configuredTimezone();
    }

    /**
     * Creates a DateTime object from various inputs.
     *
     * @param string|int|DateTimeInterface $time
     * @param string|DateTimeZone|null $tz
     *
     * @return static
     * @throws \InvalidArgumentException
     */
    public static function create($time = null, $tz = null)
    {
        if (func_num_args() > 2) {
            return static::createFromDate(...func_get_args());
        }

        if (is_null($tz)) {
            $timezone = static::getDefaultTimezone();
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
     * Creates a new instance for the current time.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function now($tz = null)
    {
        return static::create('now', $tz);
    }

    /**
     * Creates a new instance for the beginning of today.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function today($tz = null)
    {
        return static::create('today', $tz)->startOfDay();
    }

    /**
     * Creates a new instance for the beginning of yesterday.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function yesterday($tz = null)
    {
        return static::now($tz)->modify('-1 day')->startOfDay();
    }

    /**
     * Creates a new instance for the beginning of tomorrow.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function tomorrow($tz = null)
    {
        return static::now($tz)->modify('+1 day')->startOfDay();
    }

    /**
     * Creates a new instance for the beginning of the current week.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function currentWeek($tz = null)
    {
        return static::now($tz)->startOfWeek();
    }

    /**
     * Creates a new instance for the beginning of last week.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function lastWeek($tz = null)
    {
        return static::now($tz)->subWeek()->startOfWeek();
    }

    /**
     * Creates a new instance for the beginning of next week.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function nextWeek($tz = null)
    {
        return static::now($tz)->addWeek()->startOfWeek();
    }

    /**
     * Creates a new instance for the beginning of the current month.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function currentMonth($tz = null)
    {
        return static::now($tz)->startOfMonth();
    }

    /**
     * Creates a new instance for the beginning of last month.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function lastMonth($tz = null)
    {
        return static::now($tz)->subMonth()->startOfMonth();
    }

    /**
     * Creates a new instance for the beginning of next month.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function nextMonth($tz = null)
    {
        return static::now($tz)->addMonth()->startOfMonth();
    }

    /**
     * Creates a new instance for the beginning of the current year.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function currentYear($tz = null)
    {
        return static::now($tz)->startOfYear();
    }

    /**
     * Creates a new instance for the beginning of last year.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function lastYear($tz = null)
    {
        return static::now($tz)->subYear()->startOfYear();
    }

    /**
     * Creates a new instance for the beginning of next year.
     *
     * @param string|DateTimeZone|null $tz
     * @return static
     */
    public static function nextYear($tz = null)
    {
        return static::now($tz)->addYear()->startOfYear();
    }

    /**
     * Sets the timezone for the instance.
     *
     * @param string|DateTimeZone $tz
     * @return self
     */
    public function timezone($tz)
    {
        if (is_string($tz)) {
            $tz = new DateTimeZone($tz);
        }
        return $this->setTimezone($tz);
    }

    /**
     * Checks if the current instance is between two dates.
     *
     * @param string|DateTimeInterface $date1
     * @param string|DateTimeInterface $date2
     * @return bool
     */
    public function between($date1, $date2)
    {
        $date1 = $date1 instanceof DateTimeInterface ? $date1 : new static($date1);
        $date2 = $date2 instanceof DateTimeInterface ? $date2 : new static($date2);

        if ($date1 > $date2) {
            [$date1, $date2] = [$date2, $date1];
        }

        return ($this >= $date1 && $this <= $date2);
    }

    /**
     * Creates a new instance from a formatted string. Unlike PHP's, it
     * throws instead of returning false, and the parts the format leaves
     * out below its smallest unit start at their lowest value instead of
     * the current time: Y-m-d is midnight, Y-m the 1st, Y January 1st.
     *
     * @param string $format
     * @param string $datetimeString
     * @param string|DateTimeZone|null $timezone
     *
     * @return static
     * @throws \InvalidArgumentException
     */
    #[\ReturnTypeWillChange]
    public static function createFromFormat(
    	$format,
    	$datetimeString,
    	$timezone = null
    )
    {
        if (is_null($timezone)) {
            $timezone = static::getDefaultTimezone();
        } else {
            $timezone = is_string($timezone)
            	? new DateTimeZone($timezone)
            	: $timezone;
        }

        if (!$timezone instanceof DateTimeZone) {
            throw new InvalidArgumentException('Invalid timezone.');
        }

        // PHP 8 throws on a NUL byte and PHP 7.4 cuts the string at
        // it, so it is rejected like any other string that isn't a date.
        $dateTime = str_contains($datetimeString, "\0")
            ? false
            : PHPDateTimeImmutable::createFromFormat(
                $format, $datetimeString, $timezone
            );

        if ($dateTime === false) {
            throw new InvalidArgumentException(
                "Unable to create datetime from: {$datetimeString}."
            );
        }

        $parsed = date_parse_from_format($format, $datetimeString);

        // PHP fills a missing day with today's, which can roll the month
        // over (Feb 30 becomes Mar 2), so the month comes from $parsed.
        if ($parsed['day'] === false && $parsed['month'] !== false) {
            $dateTime = $dateTime->setDate(
                (int) $dateTime->format('Y'), $parsed['month'], 1
            );
        } elseif ($parsed['day'] === false && $parsed['year'] !== false) {
            $dateTime = $dateTime->setDate($parsed['year'], 1, 1);
        }

        if ($parsed['hour'] === false) {
            $dateTime = $dateTime->setTime(0, 0);
        }

        return new static(
            $dateTime->format('Y-m-d H:i:s.u'), $dateTime->getTimezone()
        );
    }

    /**
     * Creates a new instance from a UTC date string.
     *
     * @param string $dateString
     * @param string $format
     * @return static
     */
    public static function createFromUTC(
    	string $dateString,
    	string $format = 'Y-m-d H:i:s'
    )
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
     * Parses a datetime string and returns a new instance.
     *
     * @param string|\DateTimeInterface $datetimeString
     * @param string|DateTimeZone|null $timezone
     *
     * @return static
     * @throws \InvalidArgumentException
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
     * Adds an interval to the instance.
     *
     * @param DateInterval|string $interval
     * @return static
     * @throws \InvalidArgumentException
     */
    #[\ReturnTypeWillChange]
    public function add($interval)
    {
        if ($interval instanceof DateInterval) {
            return parent::add($interval);
        }

        if (func_num_args() === 2) {
            return $this->addOrSub('add', func_get_args());
        }

        if (!is_string($interval) || trim($interval) === '') {
            throw new InvalidArgumentException('Invalid interval string.');
        }

        return $this->modify('+' . $interval);
    }

    /**
     * Subtracts an interval from the instance.
     *
     * @param DateInterval|string $interval
     * @return static
     * @throws \InvalidArgumentException
     */
    #[\ReturnTypeWillChange]
    public function sub($interval)
    {
        if ($interval instanceof DateInterval) {
            return parent::sub($interval);
        }

        if (func_num_args() === 2) {
            return $this->addOrSub('sub', func_get_args());
        }

        if (!is_string($interval) || trim($interval) === '') {
            throw new InvalidArgumentException('Invalid interval string.');
        }

        return $this->subRelative($interval);
    }

    /**
     * Creates a copy of the instance.
     *
     * @return self
     */
    public function copy()
    {
        return clone $this;
    }

    /**
     * Converts the instance to a string.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->format($this->getDateFormat());
    }

    /**
     * Checks if the instance has a timezone.
     *
     * @return bool
     */
    public function hasTimezone()
    {
        return $this->getTimezone() instanceof DateTimeZone;
    }

    /**
     * Sets the time to the beginning of the day.
     *
     * @return static
     */
    public function startOfDay()
    {
    	return $this->setTime(0, 0, 0, 0);
    }

    /**
     * Sets the time to the beginning of the hour.
     *
     * @return self
     */
    public function startOfHour()
    {
    	return $this->setTime((int)$this->format('H'), 0, 0, 0);
    }

    /**
     * Sets the time to the end of the hour.
     *
     * @return self
     */
    public function endOfHour()
    {
    	return $this->setTime((int)$this->format('H'), 59, 59, 999999);
    }

    /**
     * Sets the time to the beginning of the minute.
     *
     * @return self
     */
    public function startOfMinute()
    {
    	return $this->setTime(
    		(int)$this->format('H'), (int)$this->format('i'), 0, 0
    	);
    }

    /**
     * Sets the time to the end of the minute.
     *
     * @return self
     */
    public function endOfMinute()
    {
    	return $this->setTime(
    		(int)$this->format('H'),
    		(int)$this->format('i'), 59, 999999
    	);
    }

    /**
     * Sets the date to the beginning of the month.
     *
     * @return self
     */
    public function startOfMonth()
    {
        return $this->setDate(
        	(int)$this->format('Y'), (int)$this->format('m'), 1
        )->startOfDay();
    }

    /**
     * Sets the date to the end of the month.
     *
     * @return self
     */
    public function endOfMonth()
    {
        return $this->modify('last day of this month')->endOfDay();
    }

    /**
     * Sets the date to the beginning of the quarter.
     *
     * @return self
     */
    public function startOfQuarter()
    {
        $month = (int)$this->format('m');

        $startMonth = $month <= 3 ? 1 : (
        	$month <= 6 ? 4 : ($month <= 9 ? 7 : 10)
        );
        
        return $this->setDate(
        	(int)$this->format('Y'), $startMonth, 1
        )->startOfDay();
    }

    /**
     * Sets the date to the beginning of the year.
     *
     * @return self
     */
    public function startOfYear()
    {
    	return $this->setDate(
    		(int)$this->format('Y'), 1, 1
    	)->startOfDay();
    }

    /**
     * Sets the date to the end of the year.
     *
     * @return self
     */
    public function endOfYear()
    {
    	return $this->setDate(
    		(int)$this->format('Y'), 12, 31
    	)->endOfDay();
    }

    /**
     * Sets the date to the beginning of the decade.
     *
     * @return self
     */
    public function startOfDecade()
    {
        $year = (int)$this->format('Y');
        $start = $year - ($year % 10);
        return $this->setDate($start, 1, 1)->startOfDay();
    }

    /**
     * Sets the date to the end of the decade.
     *
     * @return self
     */
    public function endOfDecade()
    {
        $year = (int)$this->format('Y');
        $end = $year + (9 - ($year % 10));
        return $this->setDate($end, 12, 31)->endOfDay();
    }

    /**
     * Check if the instance is the same day as another date.
     *
     * @param DateTimeInterface $other
     * @return bool
     */
    public function isSameDay(DateTimeInterface $other)
    {
        return $this->format('Y-m-d') === $other->format('Y-m-d');
    }

    /**
     * The instance is immutable, so its units can't be assigned; like
     * a readonly property, this throws. setYear() and friends return
     * a changed copy instead.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     * @throws \Error
     */
    public function __set($key, $value)
    {
        $property = static::class . '::$' . $key;

        if (in_array($key, ['year', 'month', 'day', 'hour', 'minute', 'second'], true)) {
            throw new \Error("Cannot modify readonly property {$property}");
        }

        throw new \Error("Cannot create dynamic property {$property}");
    }

    /**
     * Return a copy with one unit changed.
     *
     * @param string $unit
     * @param mixed $value
     * @return static
     */
    private function withUnit($unit, $value)
    {
        $y = (int) $this->format('Y');
        $m = (int) $this->format('m');
        $d = (int) $this->format('d');
        $h = (int) $this->format('H');
        $i = (int) $this->format('i');
        $sec = (int) $this->format('s');

        switch ($unit) {
            case 'year': return $this->setDate((int) $value, $m, $d);
            case 'month': return $this->setDate($y, (int) $value, $d);
            case 'day': return $this->setDate($y, $m, (int) $value);
            case 'hour': return $this->setTime((int) $value, $i, $sec);
            case 'minute': return $this->setTime($h, (int) $value, $sec);
            default: return $this->setTime($h, $i, (int) $value);
        }
    }

    /**
     * Magic method for dynamic calls (e.g., `addDay`, `getYear`).
     *
     * @param string $method
     * @param array $params
     * @return mixed|self
     * @throws \InvalidArgumentException
     */
    public function __call($method, $params)
    {
        $units = ['year', 'month', 'day', 'hour', 'minute', 'second'];

        // set* methods
        if (strpos($method, 'set') === 0) {
            $unit = lcfirst(substr($method, 3));
            if ($params && in_array($unit, $units, true)) {
                return $this->withUnit($unit, reset($params));
            }
        }

        // get* methods, the same strings DateTime returns
        if (strpos($method, 'get') === 0) {
            $unit = lcfirst(substr($method, 3));
            if (in_array($unit, $units, true)) {
                return $this->__get($unit);
            }

            throw new InvalidArgumentException(
                "Call to undefined method {$method}."
            );
        }

        // add*/sub* methods
        $action = null;
        if (strpos($method, 'add') === 0) $action = '+';
        elseif (strpos($method, 'sub') === 0) $action = '-';

        if ($action) {
            $duration = $params[0] ?? 1;
            $unit = lcfirst(substr($method, 3));

            if (
            	!in_array(
            		$unit,
            		array_merge(
            			static::SINGULAR_UNITS,
            			static::PLURAL_UNITS
            		), true
            	)
            ) {
                throw new InvalidArgumentException(
                	"Call to undefined method {$method}."
                );
            }

            // Special handling for quarter (3 months)
            if ($unit === 'quarter' || $unit === 'quarters') $unit = 'month';
            // Special handling for decade (10 years)
            if ($unit === 'decade' || $unit === 'decades') $unit = 'year';

            $multiplier = 1;
            if (stripos($method, 'quarter') !== false) $multiplier = 3;
            if (stripos($method, 'decade') !== false) $multiplier = 10;

            return $this->modify(
            	"{$action}" . ($duration * $multiplier) . " {$unit}"
            );
        }

        throw new InvalidArgumentException("Call to undefined method {$method}.");
    }
}
