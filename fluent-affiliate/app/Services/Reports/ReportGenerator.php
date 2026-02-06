<?php

namespace FluentAffiliate\App\Services\Reports;

class ReportGenerator
{
    protected $from;
    protected $to;
    protected $frequency;
    protected $dbInstance;

    /**
     * ReportGenerator constructor.
     *
     * @param string $startDate Start date in 'Y-m-d' format.
     * @param string $endDate End date in 'Y-m-d' format.
     * @param mixed $dbInstance Database connection/query builder.
     * @throws \InvalidArgumentException If dates are invalid.
     */
    public function __construct($startDate, $endDate, $dbInstance)
    {
        try {
            $this->from = new \DateTime($startDate);
            $this->to = new \DateTime($endDate);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid date format provided.');
        }

        $this->dbInstance = $dbInstance;
        $this->frequency = $this->getFrequency($this->from, $this->to);
    }

    /**
     * Get model data grouped by date intervals.
     *
     * @param mixed $modelInstance Query builder for the model.
     * @param string $field Date field to query (e.g., 'created_at').
     * @return array Associative array of dates (Y-m-d) and counts.
     * @throws \InvalidArgumentException If field is invalid.
     */
    public function getModelDataBySequence($modelInstance, $field)
    {
        if (!is_string($field) || empty(trim($field))) {
            throw new \InvalidArgumentException('Field must be a non-empty string.');
        }

        if (!is_object($modelInstance) || !method_exists($modelInstance, 'getQuery')) {
            throw new \InvalidArgumentException('Model instance must be a valid query builder.');
        }

        $period = $this->generateDatePeriods($this->from, $this->to, $this->frequency);
        list($groupBy, $orderBy) = $this->getGroupAndOrder($this->frequency);

        // Query data
        $items = $modelInstance
            ->select($this->prepareSelect($this->dbInstance, $this->frequency, $field))
            ->whereBetween($field, [$this->from->format('Y-m-d'), $this->to->format('Y-m-d')])
            ->groupBy($groupBy)
            ->orderBy($orderBy, 'ASC')
            ->get();

        // Initialize result with zeros
        $result = [];
        foreach ($period as $date) {
            $result[$date->format('Y-m-d')] = 0;
        }

        // Map query results
        foreach ($items as $item) {
            $date = $this->formatDate($item->date, $this->frequency);
            $result[$date] = (int)$item->count;
        }

        return $result;
    }

    /**
     * Determine the frequency for grouping data.
     *
     * @param \DateTime $from Start date.
     * @param \DateTime $to End date.
     * @return string Frequency interval ('P1D', 'P1W', 'P1M').
     */
    protected function getFrequency(\DateTime $from, \DateTime $to)
    {
        $days = $to->diff($from)->days;

        if ($days > 92) {
            return 'P1M';
        }

        if ($days > 62) {
            return 'P1W';
        }

        return 'P1D';
    }

    /**
     * Generate date periods for the given range and frequency.
     *
     * @param \DateTime $from Start date.
     * @param \DateTime $to End date.
     * @param string $frequency Interval ('P1D', 'P1W', 'P1M').
     * @return \DatePeriod
     * @throws \DateMalformedIntervalStringException
     * @throws \DateMalformedPeriodStringException
     */
    protected function generateDatePeriods(\DateTime $from, \DateTime $to, $frequency)
    {
        return new \DatePeriod($from, new \DateInterval($frequency), $to);
    }

    /**
     * Prepare SQL select clause for the query.
     *
     * @param mixed $dbInstance Database connection.
     * @param string $frequency Grouping frequency.
     * @param string $field Date field.
     * @return array Select expressions.
     */
    protected function prepareSelect($dbInstance, $frequency, $field)
    {
        $select = [
            $dbInstance->raw('COUNT(id) AS count'),
            $dbInstance->raw('DATE(' . $field . ') AS date')
        ];

        if ($frequency === 'P1W') {
            $select[] = $dbInstance->raw('WEEK(' . $field . ') AS week');
        } elseif ($frequency === 'P1M') {
            $select[] = $dbInstance->raw('MONTH(' . $field . ') AS month');
        }

        return $select;
    }

    /**
     * Get group by and order by clauses.
     *
     * @param string $frequency Grouping frequency.
     * @return array [groupBy, orderBy]
     */
    protected function getGroupAndOrder($frequency)
    {
        $groupBy = $orderBy = 'date';

        if ($frequency === 'P1W') {
            $groupBy = $orderBy = 'week';
        } elseif ($frequency === 'P1M') {
            $groupBy = $orderBy = 'month';
        }
        return [$groupBy, $orderBy];
    }

    /**
     * Format date based on frequency.
     *
     * @param string $date Date string.
     * @param string $frequency Grouping frequency.
     * @return string Formatted date (Y-m-d).
     * @throws \DateMalformedStringException
     */
    protected function formatDate($date, $frequency)
    {
        $dateTime = new \DateTime($date);

        if ($frequency === 'P1M') {
            return $dateTime->format('Y-m-01');
        }

        if ($frequency === 'P1W') {
            return $dateTime->modify('monday this week')->format('Y-m-d');
        }

        return $dateTime->format('Y-m-d');
    }
}
