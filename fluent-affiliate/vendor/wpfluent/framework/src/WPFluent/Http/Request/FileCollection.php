<?php

namespace FluentAffiliate\Framework\Http\Request;

use FluentAffiliate\Framework\Support\Collection;

/**
 * The uploaded files of a request, as a collection that can save them.
 */
class FileCollection extends Collection
{
    /**
     * Save every file in the collection.
     *
     * Without a key, fileCollection() holds one entry per input, and an
     * input with several files holds an array of them.
     *
     * @param  string|null $path
     * @return \FluentAffiliate\Framework\Support\Collection
     */
    public function save($path = null)
    {
        return $this->map(function ($file) use ($path) {
            if (is_array($file)) {
                return array_map(fn ($each) => $each->save($path), $file);
            }

            return $file->save($path);
        });
    }
}
