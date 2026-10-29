<?php

namespace FluentAffiliate\Framework\Database\Concerns;

use FluentAffiliate\Framework\Support\Str;
use FluentAffiliate\Framework\Support\Helper;

trait CompilesJsonPaths
{
    /**
     * Split the given JSON selector into the field and the optional path and wrap them separately.
     *
     * @param  string  $column
     * @return array
     */
    protected function wrapJsonFieldAndPath($column)
    {
        $parts = explode('->', $column, 2);

        $field = $this->wrap($parts[0]);

        $path = count($parts) > 1 ? ', '.$this->wrapJsonPath($parts[1], '->') : '';

        return [$field, $path];
    }

    /**
     * Wrap the given JSON path.
     *
     * @param  string  $value
     * @param  string  $delimiter
     * @return string
     */
    protected function wrapJsonPath($value, $delimiter = '->')
    {
        // Escape single quotes for SQL: a ' and any backslashes right before
        // it become two quotes, so "o'neil" and "o\'neil" both give "o''neil".
        $value = preg_replace("/([\\\\]+)?\\'/", "''", $value);

        $jsonPath = Helper::collect(explode($delimiter, $value))
            ->map(fn ($segment) => $this->wrapJsonPathSegment($segment))
            ->join('.');

        return "'$".(str_starts_with($jsonPath, '[') ? '' : '.').$jsonPath."'";
    }

    /**
     * Wrap the given JSON path segment.
     *
     * @param  string  $segment
     * @return string
     */
    protected function wrapJsonPathSegment($segment)
    {
        // Find array indexes such as [0] or [1][2] at the end of the
        // segment: 'tags[0]' gives '[0]', leaving the key 'tags'.
        if (preg_match('/(\[[^\]]+\])+$/', $segment, $parts)) {
            $key = Str::beforeLast($segment, $parts[0]);

            if (! empty($key)) {
                return '"'.$key.'"'.$parts[0];
            }

            return $parts[0];
        }

        return '"'.$segment.'"';
    }
}
