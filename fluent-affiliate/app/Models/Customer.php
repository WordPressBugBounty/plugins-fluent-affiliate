<?php

namespace FluentAffiliate\App\Models;

use FluentAffiliate\App\Helper\Utility;

class Customer extends Model
{
    protected $table = 'fa_customers';

    protected $appends = ['full_name', 'photo'];

    protected $fillable = [
        'user_id',
        'by_affiliate_id',
        'email',
        'first_name',
        'last_name',
        'ip',
        'settings'
    ];

    public function setSettingsAttribute($value)
    {
        $this->attributes['settings'] = maybe_serialize($value);
    }

    public function getSettingsAttribute($value)
    {
        return Utility::safeUnserialize($value);
    }

    /**
     * Accessor to get dynamic full_name attribute
     * @return string
     */
    public function getFullNameAttribute()
    {
        $fname = isset($this->attributes['first_name']) ? $this->attributes['first_name'] : '';
        $lname = isset($this->attributes['last_name']) ? $this->attributes['last_name'] : '';
        return trim("{$fname} {$lname}");
    }

    /**
     * Accessor to get dynamic photo attribute
     * @return string
     */
    public function getPhotoAttribute()
    {
        $hash = md5(strtolower(trim($this->email)));
        return apply_filters(
            'fluent_affiliate/affiliate_avatar',
            "https://www.gravatar.com/avatar/{$hash}?s=128",
            $this->email
        );
    }

	public function affiliate()
	{
		return $this->belongsTo(Affiliate::class, 'by_affiliate_id');
	}

	public function referrals()
	{
		return $this->hasMany(Referral::class, 'customer_id');
	}

	public function scopeSearchBy($query, $search)
	{
		if (!$search) {
			return $query;
		}

		return $query->where(function ($q) use ($search) {
			$q->where('email', 'LIKE', "%{$search}%")
				->orWhere('first_name', 'LIKE', "%{$search}%")
				->orWhere('last_name', 'LIKE', "%{$search}%");
		});
	}

}
