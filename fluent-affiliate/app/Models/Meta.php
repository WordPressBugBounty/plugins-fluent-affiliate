<?php

namespace FluentAffiliate\App\Models;

use FluentAffiliate\App\Helper\Utility;

/**
 *  Meta Model - DB Model for Meta table
 *
 *  Database Model
 *
 * @package FluentAffiliate\App\Models
 *
 * @version 1.0.0
 * @method static referralSetting()
 */

class Meta extends Model
{
    protected $table = 'fa_meta';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $fillable = ['object_type', 'object_id', 'meta_key', 'value'];

    public function scopeRef($query, $objectId)
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed meta_key for performance
        return $query->where([ 'object_type' => 'ref', 'meta_key' => '_faff_referral_id']) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            ->when(function ($q) use ($objectId) {
                $q->where('object_id', $objectId);
            });
    }

    public function meta()
    {
        return $this->morphTo('meta', 'object_type', 'object_id');
    }

    public function setValueAttribute($value)
    {
        $this->attributes['value'] = \maybe_serialize($value);
    }

    public function getValueAttribute($value)
    {
        return Utility::safeUnserialize($value);
    }

    public function scopeReferralSetting($query)
    {
        return $query
            ->where('object_type', 'settings')
            ->where('object_id', null)
            ->where('meta_key', 'referral_setting');
    }
}
