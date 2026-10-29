<?php

namespace FluentAffiliate\Framework\Randomizer;

/**
 * An implementation of the Randomizer class.
 * @see https://www.php.net/manual/en/class.random-randomizer.php
 */
if (class_exists('Random\Randomizer')) {
    /**
     * Wrapper around the native PHP 8.2+ Random\Randomizer. PHP 8.2's
     * lacks getBytesFromString(), getFloat() and nextFloat(), which
     * came in 8.3, so there those three use Php83MethodsTrait.
     *
     * @method string getBytes(int $length)
     * @method int    getInt(int $min, int $max)
     * @method int    nextInt()
     * @method array  pickArrayKeys(array $array, int $num)
     * @method array  shuffleArray(array $array)
     * @method string shuffleBytes(string $string)
     */
    final class Randomizer
    {
        use GetStringTrait;
        use Php83MethodsTrait {
            getBytesFromString as private fallbackGetBytesFromString;
            getFloat as private fallbackGetFloat;
            nextFloat as private fallbackNextFloat;
        }

        private $randomizer;

        public function __construct()
        {
            // @phpstan-ignore-next-line
            $this->randomizer = new \Random\Randomizer();
        }

        public function getBytesFromString(string $string, int $length): string
        {
            if (!method_exists($this->randomizer, 'getBytesFromString')) {
                return $this->fallbackGetBytesFromString($string, $length);
            }

            return $this->randomizer->getBytesFromString($string, $length);
        }

        public function getFloat(float $min, float $max, ...$boundary)
        {
            if (!method_exists($this->randomizer, 'getFloat')) {
                return $this->fallbackGetFloat($min, $max);
            }

            return $this->randomizer->getFloat($min, $max, ...$boundary);
        }

        public function nextFloat()
        {
            if (!method_exists($this->randomizer, 'nextFloat')) {
                return $this->fallbackNextFloat();
            }

            return $this->randomizer->nextFloat();
        }

        public function __call($method, $args = [])
        {
            return $this->randomizer->{$method}(...$args);
        }
    }
} else {
    final class Randomizer
    {
        use GetStringTrait;
        use Php83MethodsTrait;

        public function getBytes(int $length)
        {
            if ($length < 1) {
                $this->throwValueError(
                    'getBytes', 'Argument #1 ($length) must be greater than 0'
                );
            }

            // The source PHP's Random\Engine\Secure uses, with no fallback
            // either: when it fails, random_bytes() throws, as PHP does.
            return random_bytes($length);
        }

        public function getInt(int $min, int $max)
        {
            // Match native Random\Randomizer::getInt — throw on $min > $max
            // and delegate to random_int (which already handles the range
            // correctly, rejection-sampled, no manual offset arithmetic).
            if ($max < $min) {
                $this->throwValueError(
                    'getInt', 'Argument #2 ($max) must be greater than or equal to argument #1 ($min)'
                );
            }

            return random_int($min, $max);
        }

        public function nextInt()
        {
            return random_int(0, PHP_INT_MAX);
        }

        public function pickArrayKeys(array $array, int $num)
        {
            $count = count($array);

            if ($count === 0) {
                $this->throwValueError(
                    'pickArrayKeys', 'Argument #1 ($array) must not be empty'
                );
            }

            if ($num < 1 || $num > $count) {
                $this->throwValueError(
                    'pickArrayKeys', 'Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)'
                );
            }

            // Match native Random\Randomizer::pickArrayKeys — picked keys
            // are returned in their ORIGINAL position order in the array,
            // not in pick order. Strategy: build an index list, partial
            // Fisher-Yates to select $num indices, then sort those indices
            // and map back to keys. Runs in O(n) time.
            $keys = array_keys($array);
            $indices = range(0, $count - 1);

            // Partial Fisher-Yates: only shuffle the first $num positions.
            for ($i = 0; $i < $num; $i++) {
                $j = random_int($i, $count - 1);
                [$indices[$i], $indices[$j]] = [$indices[$j], $indices[$i]];
            }

            // Take the picked indices, sort to restore original order.
            $picked = array_slice($indices, 0, $num);
            sort($picked);

            return array_map(static function ($i) use ($keys) {
                return $keys[$i];
            }, $picked);
        }

        public function shuffleArray(array $array)
        {
            // Native returns a list; the keys are not kept.
            $array = array_values($array);
            $count = count($array);

            if ($count < 2) {
                return $array;
            }

            // Match native Random\Randomizer::shuffleArray — a single
            // unbiased Fisher-Yates pass. Do NOT loop until the result
            // differs from the input; that biases the distribution and
            // can infinite-loop on tiny arrays. A fair shuffle MUST be
            // allowed to occasionally produce the original order.
            for ($i = $count - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$array[$i], $array[$j]] = [$array[$j], $array[$i]];
            }

            return $array;
        }

        public function shuffleBytes(string $bytes)
        {
            $array = str_split($bytes);
            $shuffled = $this->shuffleArray($array);
            return implode('', $shuffled);
        }
    }
}
