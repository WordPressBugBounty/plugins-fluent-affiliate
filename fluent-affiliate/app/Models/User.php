<?php

namespace FluentAffiliate\App\Models;

use FluentAffiliate\App\Modules\Auth\AuthHelper;

class User extends Model
{
    protected $table = 'users';
    protected $hidden = [
        'user_pass'
    ];

    protected $appends = ['full_name', 'photo'];

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'ID';

    protected $guarded = ['password'];

    /**
     * Accessor to get dynamic photo attribute
     * @return string
     */
    public function getFullNameAttribute()
    {
        $fullName = trim(get_user_meta($this->ID, 'first_name', true) . ' ' . get_user_meta($this->ID, 'last_name', true));

        if ($fullName) {
            return $fullName;
        }

        return $this->display_name;
    }

    public function affiliate()
    {
        return $this->hasOne(Affiliate::class, 'user_id', 'ID');
    }

    /**
     * Accessor to get dynamic photo attribute
     * @return string
     */
    public function getPhotoAttribute()
    {

        if ($contact = $this->getContact()) {
            return $contact->photo;
        }

        $hash = md5(strtolower(trim($this->attributes['user_email'])));

        /**
         * Gravatar URL by Email
         *
         * @return string $gravatar url of the gravatar image
         */
        $name = $this->display_name;

        $fallback = '';
        if ($name) {
            $fallback = '&d=https%3A%2F%2Fui-avatars.com%2Fapi%2F' . urlencode($name) . '/128';
        }

        return apply_filters('fluent_crm/get_avatar',
            "https://www.gravatar.com/avatar/{$hash}?s=128" . $fallback,
            $this->attributes['user_email']
        );
    }


    public function getContact()
    {
        if (!defined('FLUENTCRM')) {
            return null;
        }

        if ($this->user_email) {
            return \FluentCrm\App\Models\Subscriber::where('user_id', $this->ID)
                ->orWhere('email', $this->user_email)
                ->first();
        }

        return \FluentCrm\App\Models\Subscriber::where('user_id', $this->ID)
            ->first();
    }

    public function scopeSearchBy($query, $search)
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('user_email', 'like', "%{$search}%")
                ->orWhere('display_name', 'like', "%{$search}%")
                ->orWhere('user_nicename', 'like', "%{$search}%")
                ->orWhere('user_login', 'like', "%{$search}%");
        });
    }

    public function syncAffiliateProfile($extraParams = [])
    {
        $affiliate = Affiliate::where('user_id', $this->ID)->first();

        if ($affiliate) {
            if (isset($extraParams['settings'])) {
                $prevSettings = $affiliate->settings;
                $extraParams['settings'] = wp_parse_args($extraParams['settings'], $prevSettings);
            }

            $prevStatus = $affiliate->status;

            $affiliate->fill($extraParams);
            $affiliate->save();

            if ($prevStatus != $affiliate->status) {
                do_action('fluent_affiliate/affiliate_status_to_' . $affiliate->status, $affiliate, $prevStatus);
            }

            return $affiliate;
        }

        $defaultData = [
            'user_id'   => $this->ID,
            'rate_type' => 'default',
            'status'    => AuthHelper::getInitialAffiliateStatus()
        ];

        $defaultData = wp_parse_args($extraParams, $defaultData);

        $affiliate = Affiliate::create($defaultData);

        $this->load('affiliate');

        do_action('fluent_affiliate/affiliate_created', $affiliate, $this);

        if ($affiliate->status === 'active') {
            do_action('fluent_affiliate/affiliate_status_to_active', $affiliate, '');
        }

        return $affiliate;
    }

    public function getWpUser()
    {
        return get_user_by('ID', $this->ID);
    }
}
