<?php

namespace FluentAffiliate\App\Models;

class AffiliateGroup extends Meta
{
    protected $table = 'fa_meta';

    protected $fillable = [
        'meta_key',
        'value',
    ];

    public static function boot()
    {
        static::creating(function ($model) {
            $model->object_type = 'affiliate_group';
        });

        static::addGlobalScope('object_type', function ($builder) {
            $builder->where('object_type', '=', 'affiliate_group');
        });

        parent::boot();
    }

    public static function truncate()
    {
        static::query()->where('object_type', 'affiliate_group')->delete();
    }

    public function affiliates()
    {
        return $this->hasMany(Affiliate::class, 'group_id');
    }

    public function scopeSearch($query, $search)
    {
        return $query->when($search, function($query) use($search){
            return $query->where('meta_key', 'LIKE', "%{$search}%")
                ->orWhere('value->name', 'LIKE', "%{$search}%");
        });
    }
}
