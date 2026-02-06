<?php

namespace FluentAffiliate\App\Models;

class Post extends Model
{
    protected $primaryKey = 'ID';
    protected $guarded = ['ID'];

	public $timestamps = false;


    public function scopePages($query)
    {
        return $query->where('post_type', 'page');
    }

    public function scopePublished($query)
    {
        return $query->where('post_status', 'publish');
    }
}
