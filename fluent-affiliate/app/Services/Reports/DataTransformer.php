<?php

namespace FluentAffiliate\App\Services\Reports;

class DataTransformer
{
    /**
     * Validate chart data and background color inputs.
     *
     * @param array $chartData Array with 'title' and 'data'.
     * @param string $backgroundColor Hex color code for the dataset.
     * @throws \InvalidArgumentException If input data is invalid.
     */
    protected static function validateInput($chartData, $backgroundColor): void
    {
        if (!isset($chartData['title']) || !is_string($chartData['title']) || empty(trim($chartData['title']))) {
            throw new \InvalidArgumentException('Chart data must have a valid title.');
        }

        if (!isset($chartData['data']) || !is_array($chartData['data'])) {
            throw new \InvalidArgumentException('Chart data must have a valid data array.');
        }

        if (empty($backgroundColor) || !preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $backgroundColor)) {
            throw new \InvalidArgumentException('Background color must be a valid hex code.');
        }
    }

    /**
     * Normalize chart data to date => count format.
     *
     * @param array $data Input data (either date => count or [{date, data}, ...]).
     * @return array Normalized date => count array.
     * @throws \InvalidArgumentException If data format is invalid.
     */
    protected static function normalizeData(array $data): array
    {
        $normalized = [];

        // Check if data is already in date => count format
        $isAssociative = array_keys($data) !== range(0, count($data) - 1);
        if ($isAssociative) {
            foreach ($data as $date => $count) {
                if (!is_numeric($count) || $count < 0) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Exception message for debugging
                    throw new \InvalidArgumentException("Data count for date '$date' must be a non-negative number, got: " . var_export($count, true));
                }
                $normalized[$date] = (int)$count;
            }
            return $normalized;
        }

        // Handle [{date, data}, ...] format
        foreach ($data as $entry) {
            if (!is_array($entry) || !isset($entry['date']) || !isset($entry['data'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Exception message for debugging
                throw new \InvalidArgumentException('Invalid data entry: ' . var_export($entry, true));
            }
            if (!is_numeric($entry['data']) || $entry['data'] < 0) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Exception message for debugging
                throw new \InvalidArgumentException("Data count for date '{$entry['date']}' must be a non-negative number, got: " . var_export($entry['data'], true));
            }
            $normalized[$entry['date']] = (int)$entry['data'];
        }

        return $normalized;
    }

    /**
     * Format chart data from ReportService output into a JSON-compatible structure.
     *
     * @param array $chartData Array with 'title' and 'data' (associative array of date => count or [{date, data}, ...]).
     * @param string $backgroundColor Hex color code for the dataset (e.g., '#4e79e6').
     * @return array Formatted array with 'labels' and 'datasets'.
     * @throws \InvalidArgumentException If input data is invalid.
     */
    public static function transformChartData($chartData, $backgroundColor = '#4e79e6'): array
    {
        // Validate inputs
        static::validateInput($chartData, $backgroundColor);

        // Normalize data to date => count
        $normalizedData = static::normalizeData($chartData['data']);

        // Prepare data for sorting
        $entries = [];
        foreach ($normalizedData as $date => $count) {
            try {
                $dateObj = new \DateTime($date);
                $entries[] = [
                    'date' => $date,
                    'count' => $count
                ];
            } catch (\Exception $e) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for debugging
                throw new \InvalidArgumentException('Invalid date format in data: ' . $date);
            }
        }

        // Sort by date
        usort($entries, function ($a, $b) {
            return strtotime($a['date']) <=> strtotime($b['date']);
        });

        // Build labels and data
        $labels = [];
        $data = [];
        foreach ($entries as $entry) {
            $date = new \DateTime($entry['date']);
            $labels[] = $date->format('d-m-Y');
            $data[] = $entry['count'];
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $chartData['title'],
                    'data' => $data,
                    'backgroundColor' => $backgroundColor
                ]
            ]
        ];
    }
}
