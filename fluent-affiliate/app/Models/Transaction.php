<?php

namespace FluentAffiliate\App\Models;

use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;

class Transaction extends Model
{
    protected $table = 'fa_payout_transactions';

    protected $fillable = [
        'created_by',
        'affiliate_id',
        'payout_id',
        'total_amount',
        'payout_method',
        'status',
        'currency',
        'settings',
    ];

    protected $searchable = [
        'total_amount',
        'created_at',
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
            return  $query;
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

    public function scopeByStatus($query, $status)
    {
        if (!$status || $status == 'all') {
            return $query;
        }

        $query->where('status', $status);

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
            } else {
                if ($operator == 'not_includes') {
                    $query->where($filterKey, 'NOT LIKE', '%%' . $value . '%%');
                } else {
                    if ($operator == 'gt') {
                        $query->where($filterKey, '>', $value);
                    } else {
                        if ($operator == 'lt') {
                            $query->where($filterKey, '<', $value);
                        } else {
                            if ($operator == '=') {
                                $query->where($filterKey, $value);
                            } else {
                                if ($operator == '!=') {
                                    $query->where($filterKey, '!=', $value);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $query;
    }

    public function getCounts()
    {
        return [
            'affiliates' => Referral::where('payout_id', $this->id)->groupBy('affiliate_id')->count(),
            'referrals'  => Referral::where('payout_id', $this->id)->count()
        ];
    }

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class, 'affiliate_id');
    }

    public function payout()
    {
        return $this->belongsTo(Payout::class, 'payout_id');
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'payout_transaction_id');
    }

    public function isManual()
    {
        // stored at creation; legacy and imported rows carry no marker
        return !Arr::get((array) $this->settings, 'gateway');
    }

    /**
     * A transaction sent through a gateway stays locked while the gateway may
     * still pay it, so its referrals can't become payable again. It unlocks
     * when the gateway reports it failed. Before the send it is free to remove.
     * A batch the gateway took but whose ID was lost (batch_lost) locks it too.
     */
    public function isLockedByGateway(Payout $payout)
    {
        if ($this->isManual()) {
            return false;
        }

        if ($payout->status === 'sending') {
            return true;
        }

        if ($this->status === 'failed') {
            return false;
        }

        $settings = (array) $payout->settings;

        return !empty(Arr::get($settings, 'payout_batch_id')) || !empty(Arr::get($settings, 'batch_lost'));
    }

    /**
     * Marks the transaction and its referrals paid, in one DB transaction. The
     * status flips through a conditional update, so two callers racing on one
     * row (a double click, a webhook and a sync) settle it once and fire the
     * paid hook once. A retry finishes referrals an earlier call left behind.
     *
     * @return bool true when this call changed the row
     */
    public function markAsPaid(?Payout $payout = null)
    {
        list($changed, $settled) = Helper::dbTransaction([$this, 'settle']);

        return $this->afterSettle($changed, $settled, $payout);
    }

    /**
     * The DB half of markAsPaid(). A caller that holds its own locks runs this
     * inside its DB transaction and afterSettle() once it has committed, so the
     * recounts and the paid hook never run while the rows are locked.
     *
     * @return array [changed transaction rows, settled referral rows]
     */
    public function settle()
    {
        $changed = static::query()
            ->where('id', $this->id)
            ->where('status', '!=', 'paid')
            ->update(['status' => 'paid']);

        // a manual payout settles its referrals when it is created, a
        // gateway payout leaves them processing until the money arrives.
        // Imported manual rows can also link referrals left unpaid.
        $settled = Referral::query()
            ->where('payout_transaction_id', $this->id)
            ->whereIn('status', $this->isManual() ? ['processing', 'unpaid'] : ['processing'])
            ->update(['status' => 'paid']);

        return [$changed, $settled];
    }

    public function afterSettle($changed, $settled, ?Payout $payout = null)
    {
        if (!$changed && !$settled) {
            return false;
        }

        $this->status = 'paid';

        $payout = $payout ?: Payout::query()->find($this->payout_id);

        $affiliate = Affiliate::query()->find($this->affiliate_id);
        if ($affiliate) {
            $affiliate->recountEarnings();
        }

        if ($payout) {
            $payout = $payout->recountPaymentTotal();

            // a repair only finishes the referrals; the call that flipped the
            // status already fired the hook
            if ($changed) {
                do_action('fluent_affiliate/payout/transaction/transaction_updated_to_paid', $this, $payout);
            }
        }

        return (bool) $changed;
    }

}
