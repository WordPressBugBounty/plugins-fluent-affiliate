<?php

namespace FluentAffiliate\App\Models;

use FluentAffiliate\App\App;
use FluentAffiliate\Framework\Database\Orm\Model as BaseModel;

class Model extends BaseModel
{
    protected $guarded = ['id', 'ID'];

    public function getPerPage()
    {
        $request = App::make('request')->all();
        return (isset($request['per_page'])) ? (int)$request['per_page'] : 15;
    }

    /**
     * Get a fresh timestamp for the model.
     *
     * @return \DateTime
     */
    public function freshTimestamp()
    {
        return new \DateTime(current_time('mysql'));
    }

    public function getTimezone()
    {
        return '';
    }
}
