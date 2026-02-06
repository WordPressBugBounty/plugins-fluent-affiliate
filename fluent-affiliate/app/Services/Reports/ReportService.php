<?php

namespace FluentAffiliate\App\Services\Reports;

use FluentAffiliate\App\App;
use FluentAffiliate\Framework\Container\Contracts\BindingResolutionException;
use FluentAffiliate\Framework\Support\Arr;

class ReportService
{
    /**
     * Get chart statistics for the specified date range.
     *
     * @param string $startDate The start date for the data retrieval in 'Y-m-d' format.
     * @param string $endDate The end date for the data retrieval in 'Y-m-d' format.
     * @param string $title The title for the chart data.
     * @param mixed $model The model class (string), instance, or query builder.
     * @param string $field The date field to query (e.g., 'created_at').
     * @return array An array containing 'title' and 'data' with date and count.
     * @throws \InvalidArgumentException If inputs are invalid.
     * @throws BindingResolutionException
     */
    public function getChartStatistics($startDate, $endDate, $title, $model, $field = 'created_at')
    {
        if (!is_string($title) || empty($title)) {
            throw new \InvalidArgumentException('Title must be a non-empty string.');
        }

        // Validate field
        if (!is_string($field) || empty($field)) {
            throw new \InvalidArgumentException('Field must be a non-empty string.');
        }

        $dates = $this->getStartAndEndDate($startDate, $endDate);
        $startDate = $dates['start'];
        $endDate = $dates['end'];

        // Prepare model
        $modelInstance = $this->getModelInstance($model);

        // Get data from ReportGenerator
        $data = $this
            ->reports($startDate, $endDate)
            ->getModelDataBySequence($modelInstance, $field);

        $datePeriods = array_keys($data);

        $statistics = ['title' => $title];

        $statisticsData = [];

        foreach ($datePeriods as $date) {
            $statisticsData[] = [
                'date' => $date,
                'data' => Arr::get($data, $date, 0),
            ];
        }

        // Sort by date
        usort($statisticsData, function ($a, $b) {
            return strtotime($a['date']) <=> strtotime($b['date']);
        });

        $statistics['data'] = $statisticsData;

        return DataTransformer::transformChartData($statistics);
    }

    /**
     * Generate report generator instance.
     *
     * @param string $startDate
     * @param string $endDate
     * @return ReportGenerator
     * @throws BindingResolutionException
     */
    protected function reports($startDate, $endDate): ReportGenerator
    {
        return new ReportGenerator($startDate, $endDate, App::getInstance('db'));
    }

    /**
     * Prepare model instance from input.
     *
     * @param mixed $model The model class, instance, or query builder.
     * @return mixed Query builder instance.
     * @throws \InvalidArgumentException
     */
    public function getModelInstance($model)
    {
        if (is_string($model)) {

            if (!class_exists($model)) {
                throw new \InvalidArgumentException('Model class does not exist.');
            }

            return (new $model())->query();
        }

        if (method_exists($model, 'query')) {
            return $model->query();
        }

        if (is_object($model) && method_exists($model, 'getQuery')) {
            return $model;
        }

        throw new \InvalidArgumentException('Model must be a class name, model instance, or query builder.');
    }

    /**
     * Validate and format start and end dates.
     *
     * @param string $startDate
     * @param string $endDate
     * @return array Array with 'start' and 'end' dates.
     * @throws \InvalidArgumentException
     */
    public function getStartAndEndDate($startDate, $endDate)
    {
        try {
            $start = new \DateTime($startDate);
            $end = new \DateTime($endDate);
            // Add one day to the end date to include all data for the end date
            $end->modify('+1 day');

            if ($start > $end) {
                throw new \InvalidArgumentException('Start date must be before end date.');
            }
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid date format provided.');
        }

        return [
            'start' => gmdate('Y-m-d', strtotime($startDate)),
            'end'   => gmdate('Y-m-d', strtotime($end->format('Y-m-d')))
        ];
    }

    /**
     * Create a new instance of the class.
     *
     * @return static
     */
    public static function create()
    {
        return new static();
    }


    /**
     * Get chart statistics for multiple models (multi-series).
     *
     * @param array $seriesConfigs Array of series config: [
     *   ['model' => Model::class, 'label' => 'Label', 'color' => '#hex'],
     *   ...
     * ]
     * @param string $startDate
     * @param string $endDate
     * @param string $field
     * @return array
     * @throws BindingResolutionException
     */
    public function getMultiChartStatistics(array $seriesConfigs, $startDate, $endDate, $field = 'created_at')
    {
        $dates = $this->getStartAndEndDate($startDate, $endDate);
        $startDate = $dates['start'];
        $endDate = $dates['end'];

        // Gather all dates from all series
        $allDates = [];

        // Series results
        $seriesData = [];

        foreach ($seriesConfigs as $config) {
            if (!isset($config['model']) || !isset($config['label'])) {
                continue;
            }

            $modelInstance = $this->getModelInstance($config['model']);

            $rawData = $this
                ->reports($startDate, $endDate)
                ->getModelDataBySequence($modelInstance, $field);

            // Collect all dates (for xAxis union)
            $allDates = array_merge($allDates, array_keys($rawData));

            $seriesData[] = [
                'name' => $config['label'],
                'data' => $rawData,
                // optional: color => $config['color'] ?? null
            ];
        }

        // Unique, sorted xAxis dates
        $xAxisData = array_unique($allDates);
        sort($xAxisData);

        // Prepare series arrays (fill missing dates with 0)
        $finalSeries = [];

        foreach ($seriesData as $series) {
            $points = [];
            foreach ($xAxisData as $date) {
                $points[] = $series['data'][$date] ?? 0;
            }
            $finalSeries[] = [
                'name' => $series['name'],
                'data' => $points
            ];
        }

        return [
            'xAxisData' => $xAxisData,
            'series'    => $finalSeries
        ];
    }

}
