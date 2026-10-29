<?php

namespace FluentAffiliate\Framework\Randomizer;

use FluentAffiliate\Framework\Support\InvalidArgumentException;

/**
 * The Randomizer methods that Random\Randomizer only gained in PHP 8.3,
 * used by the fallback class and by the native wrapper on PHP 8.2. They
 * follow PHP's own implementation, including its errors.
 */
trait Php83MethodsTrait
{
    public function getBytesFromString(string $string, int $length)
    {
        if ($string === '') {
            $this->throwValueError(
                'getBytesFromString', 'Argument #1 ($string) must not be empty'
            );
        }

        if ($length < 1) {
            $this->throwValueError(
                'getBytesFromString', 'Argument #2 ($length) must be greater than 0'
            );
        }

        $maxOffset = strlen($string) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $string[$this->getInt(0, $maxOffset)];
        }

        return $result;
    }

    /**
     * A float in [$min, $max), drawn with the γ-section algorithm by
     * Frédéric Goualard, which PHP's getFloat() uses for this interval.
     */
    public function getFloat(float $min, float $max)
    {
        if (!is_finite($min)) {
            $this->throwValueError('getFloat', 'Argument #1 ($min) must be finite');
        }

        if (!is_finite($max)) {
            $this->throwValueError('getFloat', 'Argument #2 ($max) must be finite');
        }

        if ($max <= $min) {
            $this->throwValueError(
                'getFloat', 'Argument #2 ($max) must be greater than argument #1 ($min)'
            );
        }

        $g = abs($min) > abs($max)
            ? $this->adjacentFloat($min, true) - $min
            : $max - $this->adjacentFloat($max, false);

        $hi = $this->ceilInt($min, $max, $g);

        if ($hi < 1) {
            return NAN;
        }

        $k = random_int(1, $hi);

        if (abs($min) <= abs($max)) {
            if ($k === $hi) {
                return $min;
            }

            return 4.0 * ($max * 0.25 - ($k >> 2) * $g) - ($k & 3) * $g;
        }

        $k--;

        return 4.0 * ($min * 0.25 + ($k >> 2) * $g) + ($k & 3) * $g;
    }

    /**
     * A float in [0, 1): 53 random bits scaled down, as PHP does.
     */
    public function nextFloat()
    {
        return random_int(0, (1 << 53) - 1) / (1 << 53);
    }

    /**
     * The number of $g steps from $a to $b, rounded up (ceilint() in
     * PHP's gammasection.c).
     */
    private function ceilInt(float $a, float $b, float $g)
    {
        $s = $b / $g - $a / $g;

        if (abs($a) <= abs($b)) {
            $e = -$a / $g - ($s - $b / $g);
        } else {
            $e = $b / $g - ($s + $a / $g);
        }

        $si = ceil($s);

        return $s != $si ? (int) $si : (int) $si + (int) ($e > 0);
    }

    /**
     * The float next to $x going up or down, like C's nextafter().
     */
    private function adjacentFloat(float $x, bool $up)
    {
        if ($x == 0.0) {
            $bits = $up ? 1 : PHP_INT_MIN | 1;
        } else {
            $bits = unpack('q', pack('d', $x))[1] + (($x > 0) === $up ? 1 : -1);
        }

        return unpack('d', pack('q', $bits))[1];
    }

    /**
     * Throw the error PHP's Random\Randomizer throws. \ValueError landed
     * in PHP 8.0; on older runtimes it is an InvalidArgumentException.
     */
    private function throwValueError(string $method, string $message)
    {
        $message = "Random\\Randomizer::{$method}(): {$message}";

        if (class_exists('ValueError', false)) {
            throw new \ValueError($message);
        }

        throw new InvalidArgumentException($message);
    }
}
