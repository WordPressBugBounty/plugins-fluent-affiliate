<?php
namespace FluentAffiliate\App\Models;

use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\Framework\Support\Arr;

/**
 *  Affiliate Model - DB Model for Affiliate table
 *
 *  Database Model
 *
 * @package FluentAffiliate\App\Models
 *
 * @version 1.0.0
 */
class Affiliate extends Model
{
    protected $table = 'fa_affiliates';

    protected $appends = ['user_details'];

    protected $fillable = [
        'user_id',
        'group_id',
        'custom_param',
        'rate',
        'rate_type',
        'payment_email',
        'status',
        'settings',
        'note',
        'custom_fields',
    ];

    public function setSettingsAttribute($value)
    {
        $defaults = [
            'disable_new_ref_email' => 'no',
            'bank_details'          => '',
        ];

        if (is_array($value)) {
            $value = wp_parse_args($value, $defaults);
        } else {
            $value = $defaults;
        }

        $this->attributes['settings'] = maybe_serialize($value);
    }

    public function getSettingsAttribute($value)
    {
        $defaults = [
            'disable_new_ref_email' => 'no',
            'bank_details'          => '',
        ];

        if (! $value) {
            return $defaults;
        }

        $value = Utility::safeUnserialize($value);

        if (! $value || ! is_array($value)) {
            $value = [];
        }

        return wp_parse_args($value, $defaults);
    }

    public function getBankDetailsAttribute()
    {
        return Arr::get($this->settings, 'bank_details', '');
    }

    public function getCustomFieldsAttribute($value)
    {
        if (!$value) {
            return [];
        }

        $data = json_decode($value, true);

        return is_array($data) ? $data : [];
    }

    public function setCustomFieldsAttribute($value)
    {
        $this->attributes['custom_fields'] = is_array($value) ? json_encode($value) : $value;
    }

    public function increase($column)
    {
        if ($column == 'referrals' || $column == 'visits') {
            $this->{$column} = $this->{$column}+1;
            $this->save();
        }
        return $this;
    }

    public function decrease($column)
    {
        if ($column == 'referrals' || $column == 'visits') {
            $this->{$column} = ($this->{$column} > 1) ? $this->{$column}-1 : 0;
            $this->save();
        }

        return $this;
    }

    public function scopeOfStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Accessor to get dynamic photo attribute
     * @return array
     */
    public function getUserDetailsAttribute()
    {

        $userModel = $this->user;

        if (! $userModel) {
            return [
                'user_id'      => $this->user_id,
                'affiliate_id' => $this->id,
            ];
        }

        return [
            'full_name'    => $userModel->full_name,
            'edit_url'     => admin_url('user-edit.php?user_id=' . $this->user_id),
            'email'        => $userModel->user_email,
            'avatar'       => $userModel->photo,
            'affiliate_id' => $this->id,
            'website'      => $userModel->user_url,
            'user_name'    => $userModel->user_login,
        ];
    }

    /**
     * One2One: Affiliate belongs to one User
     * @return \FluentAffiliate\Framework\Database\Orm\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'ID');
    }

    public function group()
    {
        return $this->belongsTo(AffiliateGroup::class, 'group_id', 'id');
    }

    public function website()
    {
        return $this
            ->morphOne(Meta::class, 'meta', __CLASS__, $this->id);
    }

    public function customers()
    {
        return $this
            ->hasMany(Customer::class, 'by_affiliate_id');
    }

    /**
     * Local scope to filter subscribers by search/query string
     * @param \FluentAffiliate\Framework\Database\Query\Builder $query
     * @param string $search
     * @return \FluentAffiliate\Framework\Database\Query\Builder $query
     */
    public function scopeSearchBy($query, $search)
    {
        if (! $search) {
            return $query;
        }

        $query->whereHas('user', function ($q) use ($search) {
            $q->where('ID', 'LIKE', '%%' . $search . '%%')
                ->orWhere('user_email', 'LIKE', '%%' . $search . '%%')
                ->orWhere('display_name', 'LIKE', '%%' . $search . '%%');
        })
            ->orWhere('id', 'LIKE', '%%' . $search . '%%')
            ->orWhere('payment_email', 'LIKE', '%%' . $search . '%%');

        return $query;
    }

    public function scopeApplyCustomFilters($query, $filters)
    {
        if (! $filters) {
            return $query;
        }

        $acceptedKeys = [
            'total_earnings',
            'unpaid_earnings',
            'referrals',
            'visits',
            'payment_email',
            'status',
            'group',
        ];
        foreach ($filters as $filterKey => $filter) {

            if (! in_array($filterKey, $acceptedKeys) || ! is_array($filter)) {
                continue;
            }

            if ($filterKey === 'group') {
                $groupIds = array_filter(array_map('intval', (array) Arr::get($filter, 'value', [])));

                if (! $groupIds) {
                    continue;
                }

                $query->whereHas('group', function ($q) use ($groupIds) {
                    return $q->whereIn('id', $groupIds);
                });

                continue;
            }

            $value    = Arr::get($filter, 'value', '');
            $operator = Arr::get($filter, 'operator', '');
            if (! $value || ! $operator) {
                continue;
            }

            if ($operator == 'IN' && is_array($value)) {
                $query->whereIn($filterKey, $value);
                continue;
            }

            if (is_array($value)) {
                continue;
            }

            $value = trim($value);

            if ($operator == 'includes') {
                $query->where($filterKey, 'LIKE', '%%' . $value . '%%');
            } else if ($operator == 'not_includes') {
                $query->where($filterKey, 'NOT LIKE', '%%' . $value . '%%');
            } else if ($operator == 'gt') {
                $query->where($filterKey, '>', $value);
            } else if ($operator == 'lt') {
                $query->where($filterKey, '<', $value);
            } else if ($operator == '=') {
                $query->where($filterKey, $value);
            } else if ($operator == '!=') {
                $query->where($filterKey, '!=', $value);
            }
        }

        return $query;
    }

    public function scopeByStatus($query, $status)
    {

        if (! $status || $status == 'all') {
            return $query;
        }

        if (is_array($status)) {
            $query->whereIn('status', $status);
        } else {
            $query->where('status', $status);
        }

        return $query;
    }

    public function visits()
    {
        return $this->hasMany(Visit::class, 'affiliate_id');
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'affiliate_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'affiliate_id');
    }

    public function payouts()
    {
        return $this->belongsTo(Payout::class, 'payout_id');
    }

    public function getMeta($key, $default = '')
    {
        $meta = Meta::where('object_type', 'affiliate')
            ->where('object_id', $this->id)
            ->where('meta_key', $key)
            ->first();

        return $meta ? $meta->value : $default;
    }

    public function updateMeta($key, $value)
    {
        $exist = Meta::where('object_type', 'affiliate')
            ->where('object_id', $this->id)
            ->where('meta_key', $key)
            ->first();

        if ($exist) {
            $exist->value = $value;
            $exist->save();
            return $exist;
        }

        return Meta::create([
            'object_id'   => $this->id,
            'meta_key'    => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed meta_key for performance
            'value'       => $value,
            'object_type' => 'affiliate',
        ]);
    }

    public function deleteMeta($key)
    {
        $meta = Meta::where('object_type', 'affiliate')
            ->where('object_id', $this->id)
            ->where('meta_key', $key)
            ->first();

        if ($meta) {
            $meta->delete();
        }
    }

    public function recountEarnings()
    {
        $paidEarnings = Referral::where('affiliate_id', $this->id)
            ->where('status', 'paid')
            ->sum('amount');

        $unpaidEarnings = Referral::where('affiliate_id', $this->id)
            ->where('status', 'unpaid')
            ->sum('amount');

        $this->total_earnings  = $paidEarnings + $unpaidEarnings;
        $this->unpaid_earnings = $unpaidEarnings;
        $this->visits          = Visit::where('affiliate_id', $this->id)->count();

        $this->referrals = Referral::query()->where('affiliate_id', $this->id)
            ->count();

        $this->visits = Visit::query()->where('affiliate_id', $this->id)
            ->count();

        $this->save();

        return $this;
    }

    /**
     * Resolves the rate of the affiliate's group, for both the commission
     * calculation and the rate shown in the admin. Returns null when the group
     * rate does not apply, so the caller falls back to the site default. That
     * covers an inactive group and the `default` rate type, which means the
     * group defers to the site rate.
     *
     * @return array|null
     */
    protected function resolveGroupRate()
    {
        if ($this->rate_type != 'group' || !$this->group) {
            return null;
        }

        $groupDetails = $this->group->value;

        if (Arr::get($groupDetails, 'status') != 'active') {
            return null;
        }

        $rateType = Arr::get($groupDetails, 'rate_type');

        if (!in_array($rateType, ['percentage', 'flat', 'fixed'])) {
            return null;
        }

        return [
            'type' => $rateType == 'percentage' ? 'percentage' : 'flat',
            'rate' => (float)Arr::get($groupDetails, 'rate', 0),
        ];
    }

    public function getCommission($amount, $scope = 'sale')
    {
        if ($this->rate_type == 'percentage') {

            return ($amount * $this->rate) / 100;
        }

        if (in_array($this->rate_type, ['flat', 'fixed'])) {
            return $this->rate;
        }

        $groupRate = $this->resolveGroupRate();

        if ($groupRate) {
            if ($groupRate['type'] == 'percentage') {
                return ($amount * $groupRate['rate']) / 100;
            }

            return $groupRate['rate'];
        }

        $globalSettings = Utility::getReferralSettings();

        if (Arr::get($globalSettings, 'rate_type') == 'percentage') {
            return ($amount * Arr::get($globalSettings, 'rate')) / 100;
        }

        return Arr::get($globalSettings, 'rate');
    }

    public function getRateDetails()
    {
        $rateType = $this->rate_type;

        if ($rateType == 'percentage') {
            return [
                'type'           => 'percentage',
                'rate'           => $this->rate,
                'is_custom'      => 'yes',
                'human_readable' => $this->rate . '% (' . __('custom', 'fluent-affiliate') . ')',
            ];
        }

        if (in_array($rateType, ['flat', 'fixed'])) {
            return [
                'type'           => 'flat',
                'rate'           => $this->rate,
                'is_custom'      => 'yes',
                'human_readable' => Helper::formatMoney($this->rate) . ' (' . __('custom', 'fluent-affiliate') . ')',
            ];
        }

        $groupRate = $this->resolveGroupRate();

        if ($groupRate) {
            $group = $this->group;

            return [
                'group'          => $group,
                'type'           => $groupRate['type'],
                'rate'           => $groupRate['rate'],
                'is_custom'      => 'no',
                'human_readable' => $groupRate['type'] == 'percentage'
                    ? $groupRate['rate'] . '% (' . $group->meta_key . ')'
                    : Helper::formatMoney($groupRate['rate']) . ' (' . $group->meta_key . ')',
            ];
        }

        $defaultSettings = Utility::getReferralSettings();

        $humanReadable = Arr::get($defaultSettings, 'rate', 0) . '% (' . __('default', 'fluent-affiliate') . ')';
        $rateType      = Arr::get($defaultSettings, 'rate_type', 'percentage');
        if (in_array($rateType, ['flat', 'fixed'])) {
            $humanReadable = Helper::formatMoney(Arr::get($defaultSettings, 'rate', 0)) . ' (' . __('default', 'fluent-affiliate') . ')';
        }

        return [
            'type'           => $rateType,
            'rate'           => Arr::get($defaultSettings, 'rate', 0),
            'is_custom'      => 'no',
            'is_default'     => 'yes',
            'human_readable' => $humanReadable,
        ];
    }

    public function isNewRefEmailEnabled()
    {
        $isDisabled = Arr::get($this->settings, 'disable_new_ref_email', 'no');
        return $isDisabled !== 'yes';
    }

    public function getAttachedCoupons($context = 'view')
    {
        return apply_filters('fluent_affiliate/affiliate_attached_coupons', [], $this, $context);
    }

    public function getShareUrl($url = '')
    {
        $affVar   = Utility::getReferralSetting('referral_variable', 'ref');
        $affValue = Utility::getAffiliateParam($this);

        if (!$url) {
            $url = apply_filters('fluent_affiliate/default_share_url', home_url('/'), $this);
        }

        return add_query_arg($affVar, $affValue, $url);
    }

    public function getAffPropValue($prop, $defaultValue = '')
    {
        $affProps = [
            'id',
            'status',
            'total_earnings',
            'unpaid_earnings',
            'referrals',
            'visits',
            'payment_email',
            'custom_param',
        ];

        if (in_array($prop, $affProps)) {
            return $this->{$prop};
        }

        if ($prop == 'share_url') {
            return $this->getShareUrl();
        }

        if ($prop === 'registered_at' || $prop === 'created_at') {
            return gmdate('Y-m-d H:i:s', strtotime($this->created_at));
        }

        if ($prop === 'paid_earnings') {
            return Referral::where('affiliate_id', $this->id)
                ->where('status', 'paid')
                ->sum('amount');
        }

        $payoutProps = [
            'last_payout_amount',
            'last_payout_date',
        ];

        if (! in_array($prop, $payoutProps)) {
            return $defaultValue;
        }

        $lastTransaction = Transaction::where('affiliate_id', $this->id)
            ->orderBy('id', 'DESC')
            ->first();

        if (! $lastTransaction) {
            return $defaultValue;
        }

        if ($prop == 'last_payout_amount') {
            return $lastTransaction->total_amount;
        }

        if ($prop == 'last_payout_date') {
            return gmdate('Y-m-d H:i:s', strtotime($lastTransaction->created_at));
        }

        return $defaultValue;
    }

}
