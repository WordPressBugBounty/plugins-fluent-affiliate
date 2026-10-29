<?php

namespace FluentAffiliate\App\Models;

use FluentAffiliate\App\App;
use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\Framework\Database\Orm\Model as BaseModel;

class Model extends BaseModel
{
    protected $guarded = ['id', 'ID'];

    public function getPerPage()
    {
        $request = App::make('request')->all();
        return (isset($request['per_page'])) ? Helper::clampPerPage($request['per_page']) : 15;
    }

    public function getTimezone()
    {
        return '';
    }
}
