<?php

namespace FluentAffiliate\App\Models;

use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\App\Helper\Utility;

class Payout extends Model
{
    protected $table = 'fa_payouts';

    protected $fillable = [
        'created_by',
        'total_amount',
        'payout_method',
        'status',
        'title',
        'description',
        'currency',
        'settings'
    ];

    protected $searchable = [
        'title',
        'total_amount',
        'description',
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

    /**
     * Local scope to filter subscribers by search/query string
     * @param \FluentAffiliate\Framework\Database\Query\Builder $query
     * @param string $search
     * @return \FluentAffiliate\Framework\Database\Query\Builder $query
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
        if (!$status || $status === 'all') {
            return $query;
        }

        return $query->where('status', $status);
    }

    public function getCounts()
    {
        return [
            'affiliates' => $this->referrals()->groupBy('affiliate_id')->count(),//Referral::where('payout_id', $this->id)->groupBy('affiliate_id')->count(),
            'referrals'  => $this->referrals()->count(),//Referral::where('payout_id', $this->id)->count()
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'payout_id');
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'payout_id');
    }

    public function affiliates()
    {
        // use transactions to get affiliates
        return $this->hasManyThrough(
            Affiliate::class,
            Transaction::class,
            'payout_id', // Foreign key on transactions table
            'id', // Foreign key on affiliates table
            'id', // Local key on payouts table
            'affiliate_id' // Local key on transactions table
        );
    }

    public function recountPaymentTotal()
    {
        $this->total_amount = Transaction::query()->where('payout_id', $this->id)->sum('total_amount');

        // check if there has any processing transactions
        $processingTransactions = Transaction::query()
            ->where('payout_id', $this->id)
            ->where('status', 'processing')
            ->exists();

        if ($processingTransactions) {
            $this->status = 'processing';
        } else {
            $this->status = 'paid';
        }

        $this->save();

        return $this;
    }

}
