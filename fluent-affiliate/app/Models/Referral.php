<?php

namespace FluentAffiliate\App\Models;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\Framework\Support\Arr;

class Referral extends Model
{
    protected $table = 'fa_referrals';

    protected $fillable = [
        'affiliate_id',
        'parent_id',
        'customer_id',
        'visit_id',
        'description',
        'amount',
        'order_total',
        'currency',
        'utm_campaign',
        'provider',
        'provider_id',
        'provider_sub_id',
        'products',
        'payout_id',
        'type',
        'status',
        'settings',
    ];

    protected $searchable = [
        'description',
        'amount',
        'utm_campaign',
        'provider',
        'id'
    ];

    public function setSettingsAttribute($value)
    {
        $this->attributes['settings'] = maybe_serialize($value);
    }

    public function getSettingsAttribute($value)
    {
        return Utility::safeUnserialize($value);
    }

    public function getProviderUrl()
    {
        return apply_filters('fluent_affiliate/provider_reference_' . $this->provider . '_url', '', $this);
    }

    public function setProductsAttribute($value)
    {
        $this->attributes['products'] = maybe_serialize($value);
    }

    public function getProductsAttribute($value)
    {
        return Utility::safeUnserialize($value);
    }

    /**
     * Local scope to filter subscribers by search/query string
     * @param \FluentCart\Framework\Database\Query\Builder $query
     * @param string $search
     * @return \FluentCart\Framework\Database\Query\Builder $query
     */
    public function scopeSearchBy($query, $search)
    {
        if (!$search) {
            return $query;
        }

        $fields = $this->searchable;

        $maybeColumnSearch = explode(':', $search);

        if (count($maybeColumnSearch) >= 2) {
            $column = $maybeColumnSearch[0];
            $validColumns = $this->fillable;
            $validColumns[] = 'id';
            if (in_array($column, $validColumns)) {
                return $query->where($column, 'LIKE', '%%' . trim($maybeColumnSearch[1]) . '%%');
            }
        }

        $maybeExactSearch = explode('=', $search);
        if (count($maybeExactSearch) >= 2) {
            $column = $maybeExactSearch[0];
            $validColumns = $this->fillable;
            if (in_array($column, $validColumns)) {
                return $query->where($column, trim($maybeExactSearch[1]));
            }
        }


        $query->where(function ($query) use ($fields, $search) {
            $query->where(array_shift($fields), 'LIKE', "%$search%");
            foreach ($fields as $field) {
                $query->orWhere($field, 'LIKE', "%%$search%%");
            }
        });

        return $query;
    }

    public function scopeApplyCustomFilters($query, $filters)
    {
        if (!$filters) {
            return $query;
        }

        $acceptedKeys = $this->fillable;

        foreach ($filters as $filterKey => $filter) {

            if (!in_array($filterKey, $acceptedKeys)) {
                continue;
            }

            $value = Arr::get($filter, 'value', '');
            $operator = Arr::get($filter, 'operator', '');
            if (!$value || !$operator) {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($filterKey, $value);
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
        if(!$status || $status == 'all') {
            return $query;
        }

        $validStatuses = ['paid', 'unpaid', 'pending', 'rejected', 'cancelled'];

        if (!in_array($status, $validStatuses)) {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * One2One: Referral belongs to one Affiliate
     * @return \FluentAffiliate\Framework\Database\Orm\Relations\BelongsTo
     */
    public function affiliate()
    {
        return $this->belongsTo(
            __NAMESPACE__ . '\Affiliate', 'affiliate_id', 'id'
        );
    }

    /**
     * One2One: Referral belongs to one Visit
     * @return \FluentAffiliate\Framework\Database\Orm\Relations\BelongsTo
     */
    public function visit()
    {
        return $this->belongsTo(
            __NAMESPACE__ . '\Visit', 'visit_id', 'id'
        );
    }

    public function payout()
    {
        return $this->belongsTo(Payout::class, 'payout_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'payout_transaction_id');
    }

    public function parent()
    {
        return $this->belongsTo(Referral::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Referral::class, 'parent_id');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeUnPaid($query)
    {
        return $query->where('status', 'unpaid');
    }

    protected static function booted()
    {
        static::deleted(function ($referral) {
            if ($referral->affiliate()->exists()) {
                $referral->affiliate->recountEarnings();
            }
        });
    }

    public function getProviderReferenceUrl()
    {
        return apply_filters(
            'fluent_affiliate/provider_reference_' . $this->provider . '_url',
            '',
            $this
        );
    }

    public function getAdminViewUrl()
    {
        return Utility::getAdminPageUrl('referrals/'.$this->id.'/view');
    }

}
