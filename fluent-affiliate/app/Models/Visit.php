<?php

namespace FluentAffiliate\App\Models;

class Visit extends Model
{
    protected $table = 'fa_visits';

    protected $fillable = [
        'affiliate_id',
        'url',
        'referrer',
        'utm_campaign',
        'referral_id',
        'utm_medium',
        'utm_source',
        'ip',
        'user_id'
    ];

    protected $searchable = [
        'url',
        'referrer',
        'utm_campaign',
        'utm_medium',
        'utm_source'
    ];

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
            $query->orWhere(function ($q) use ($search) {
                $q->whereHas('affiliate', function ($affiliateQuery) use ($search) {
                    $affiliateQuery->whereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('display_name', 'LIKE', "%%{$search}%%")
                            ->orWhere('user_email', 'LIKE', "%%{$search}%%")
                            ->orWhere('user_login', 'LIKE', "%%{$search}%%");
                    });
                });
            });
        });

        return $query;
    }

    public function scopeByConvertedStatus($query, $convertedStatus)
    {
        if ($convertedStatus === 'converted') {
            return $query->whereHas('referrals');
        }

        if ($convertedStatus === 'not_converted') {
            return $query->whereDoesntHave('referrals');
        }

        return $query;
    }

    /**
     * One2One: affiliate belongs to one Visit
     * @return \FluentAffiliate\Framework\Database\Orm\Relations\BelongsTo
     */
    public function affiliate()
    {
        return $this->belongsTo(
            __NAMESPACE__ . '\Affiliate', 'affiliate_id', 'id'
        );
    }

    public function referrals()
    {
        return $this->hasMany(
            __NAMESPACE__ . '\Referral', 'visit_id', 'id'
        );
    }

    /**
     * Custom apply filter method
     */

    public function scopeApplyCustomFilters($query, $filters)
    {
        if (!$filters) {
            return $query;
        }

        foreach ($filters as $filter) {

            if ($filter['operator'] === 'YES') {
                $query->whereNotNull('referral_id');
            }

            if ($filter['operator'] === 'NO') {
                $query->whereNull('referral_id');
            }
        }

        return $query;
    }
}
