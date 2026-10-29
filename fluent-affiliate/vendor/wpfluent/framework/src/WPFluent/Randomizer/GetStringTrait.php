<?php

namespace FluentAffiliate\Framework\Randomizer;

use FluentAffiliate\Framework\Support\InvalidArgumentException;

trait GetStringTrait
{
    /**
     * A random string of $length characters picked from $charlist. A
     * UTF-8 list gives whole characters; any other list is used as bytes.
     *
     * @param  int  $length
     * @param  string  $charlist
     * @return string
     * @throws \FluentAffiliate\Framework\Support\InvalidArgumentException
     */
    public function getString(
        int $length = 16,
        string $charlist = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()-_=+[]{}|;:,.<>?/~'
    ) {
        if ($length < 1) {
            throw new InvalidArgumentException(
                'Length must be greater than zero.'
            );
        }

        // Split the list into characters: an empty pattern matches between
        // every character and /u keeps UTF-8 characters whole: 'aé' ->
        // ['a', 'é']. A list that is not valid UTF-8 gives false.
        $chars = preg_split('//u', $charlist, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            $chars = str_split($charlist);
        }

        if (count($chars) < 2) {
            throw new InvalidArgumentException(
                'Character list must contain at least two chars.'
            );
        }

        // One byte per character: PHP's own byte picker does the job.
        if (count($chars) === strlen($charlist)) {
            return $this->getBytesFromString($charlist, $length);
        }

        $maxOffset = count($chars) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[$this->getInt(0, $maxOffset)];
        }

        return $result;
    }
}
