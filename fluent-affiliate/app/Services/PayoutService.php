<?php

namespace FluentAffiliate\App\Services;

use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\Framework\Support\Arr;

/**
 * Payout runs and their transactions. PayoutController and the MCP tools call
 * these, so both paths claim referrals, settle transactions and fire hooks the
 * same way.
 *
 * Every refusal is a WP_Error. Its message is the translated text the admin
 * screen shows; its code names the case, so a caller that needs other wording
 * (the MCP tools answer in plain English) can map it.
 */
class PayoutService
{
    /**
     * The payable affiliates and amounts for a payout config. The config must be
     * validated by the caller.
     *
     * @param array $config title, description, affiliate_ids, start_date, end_date, min_payout,
     *                      ignore_holding_period, payout_method
     * @return array|\WP_Error
     */
    public static function preview($config)
    {
        $startDate = Arr::get($config, 'start_date');
        $endDate = Arr::get($config, 'end_date');
        $minAmount = (float) Arr::get($config, 'min_payout', 0);

        if (!$startDate) {
            $startDate = '1970-01-01 00:00:00';
        } else {
            $startDate = gmdate('Y-m-d 00:00:00', strtotime($startDate));
        }

        if (!$endDate) {
            $endDate = current_time('mysql');
        } else {
            $endDate = gmdate('Y-m-d 23:59:59', strtotime($endDate));
        }

        if (strtotime($startDate) > strtotime($endDate)) {
            return new \WP_Error('date_range_reversed', __('Start date cannot be greater than end date.', 'fluent-affiliate'));
        }

        $affiliateIds = (array) Arr::get($config, 'affiliate_ids', []);
        $affiliateIds = array_map('intval', $affiliateIds);

        global $wpdb;

        // referrals inside the holding period are not payable yet. Moving the end date
        // back covers the preview and the claim in create(), which reads these dates
        $held = [
            'count'        => 0,
            'total_amount' => 0,
        ];

        $holdingDays = (int) Utility::getReferralSetting('holding_days', 0);
        // the admin can pay the held referrals too; with no holding period there is nothing to ignore
        $ignoreHold = $holdingDays > 0 && Arr::get($config, 'ignore_holding_period') === 'yes';
        if ($holdingDays > 0 && !$ignoreHold) {
            // created_at is written in site time, so the cutoff is built the same way
            $holdCutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - $holdingDays * DAY_IN_SECONDS);

            if (strtotime($endDate) > strtotime($holdCutoff)) {
                $heldTotals = Affiliate::query()
                    ->select(
                        FluentAffiliate('db')->raw('COUNT(' . $wpdb->prefix . 'fa_referrals.id) as held_count'),
                        FluentAffiliate('db')->raw('SUM(' . $wpdb->prefix . 'fa_referrals.amount) as held_amount')
                    )
                    ->join('fa_referrals', 'fa_affiliates.id', '=', 'fa_referrals.affiliate_id')
                    ->where('fa_referrals.status', 'unpaid')
                    ->whereNull('fa_referrals.payout_transaction_id')
                    ->where('fa_referrals.created_at', '>', max($startDate, $holdCutoff))
                    ->where('fa_referrals.created_at', '<=', $endDate)
                    ->when($affiliateIds, function ($query) use ($affiliateIds) {
                        return $query->whereIn('fa_affiliates.id', $affiliateIds);
                    })
                    ->when(!$affiliateIds, function ($query) {
                        return $query->where('fa_affiliates.status', 'active');
                    })
                    ->whereHas('user')
                    ->first();

                $held = [
                    'count'        => $heldTotals ? (int) $heldTotals->held_count : 0,
                    'total_amount' => $heldTotals ? (float) $heldTotals->held_amount : 0,
                ];

                $endDate = $holdCutoff;
            }

            if (strtotime($startDate) > strtotime($endDate)) {
                return new \WP_Error('all_held', __('All referrals in this date range are still in the holding period.', 'fluent-affiliate'));
            }
        }

        $dateRange = [
            $startDate,
            $endDate
        ];

        $affiliates = Affiliate::query()
            ->select(
                'fa_affiliates.*',
                FluentAffiliate('db')->raw('COUNT(' . $wpdb->prefix . 'fa_referrals.id) as payable_referral_count'),
                FluentAffiliate('db')->raw('SUM(' . $wpdb->prefix . 'fa_referrals.amount) as total_payable_amount')
            )
            ->join('fa_referrals', 'fa_affiliates.id', '=', 'fa_referrals.affiliate_id')
            ->where('fa_referrals.status', 'unpaid')
            ->whereNull('fa_referrals.payout_transaction_id')
            ->whereBetween('fa_referrals.created_at', $dateRange)
            ->when($affiliateIds, function ($query) use ($affiliateIds) {
                return $query->whereIn('fa_affiliates.id', $affiliateIds)
                    ->whereIn('fa_referrals.affiliate_id', $affiliateIds);
            })
            // a payout to all affiliates leaves out inactive and pending ones;
            // the admin can still pay them by selecting them
            ->when(!$affiliateIds, function ($query) {
                return $query->where('fa_affiliates.status', 'active');
            })
            ->whereHas('user')
            // the email stays for the avatar
            ->with(['user' => function ($query) {
                $query->select(['ID', 'display_name', 'user_email']);
            }])
            ->groupBy('fa_affiliates.id')
            ->havingRaw('SUM(' . $wpdb->prefix . 'fa_referrals.amount) > ?', [$minAmount])
            ->orderBy('total_payable_amount', 'DESC')
            ->get();

        $totalAmount = $affiliates->sum('total_payable_amount');

        // one filter serves both the review preview and create(), so a
        // gateway can drop affiliates it cannot pay from both at once
        return apply_filters('fluent_affiliate/payout/validated_config', [
            'payable_affiliates'   => $affiliates,
            'payable_total_amount' => $totalAmount,
            'held'                 => $held,
            'config'               => [
                'start_date'     => $startDate,
                'end_date'       => $endDate,
                'min_payout'     => $minAmount,
                'affiliate_ids'  => $affiliateIds,
                'payout_method'  => static::resolvePayoutMethod($config),
                'ignore_holding_period' => $ignoreHold ? 'yes' : 'no',
            ]
        ], $config);
    }

    /**
     * The payable referrals behind a preview, by affiliate: what create() claims.
     *
     * @param array $preview The result of preview().
     * @return array<int, array<int, array{id: int, amount: float, currency: string}>> Keyed by affiliate id, ordered by referral id.
     */
    public static function payableReferrals($preview)
    {
        $affiliateIds = [];
        foreach (Arr::get($preview, 'payable_affiliates', []) as $affiliate) {
            $affiliateIds[] = (int) $affiliate->id;
        }

        if (!$affiliateIds) {
            return [];
        }

        $rows = Referral::query()
            ->select(['id', 'affiliate_id', 'amount', 'currency'])
            ->whereIn('affiliate_id', $affiliateIds)
            ->where('status', 'unpaid')
            ->whereNull('payout_transaction_id')
            ->whereBetween('created_at', [Arr::get($preview, 'config.start_date'), Arr::get($preview, 'config.end_date')])
            ->orderBy('id', 'ASC')
            ->get();

        $byAffiliate = [];
        foreach ($rows as $row) {
            $byAffiliate[(int) $row->affiliate_id][] = [
                'id'       => (int) $row->id,
                'amount'   => (float) $row->amount,
                // a row's own currency; manual referrals leave it empty
                'currency' => strtoupper((string) $row->currency),
            ];
        }

        ksort($byAffiliate);

        return $byAffiliate;
    }

    public static function resolvePayoutMethod($config)
    {
        // an unknown method falls back to manual, as every payout did before
        $method = sanitize_key((string) Arr::get($config, 'payout_method', 'manual'));

        return array_key_exists($method, Helper::getPayoutMethods()) ? $method : 'manual';
    }

    /**
     * Create a payout run: one transaction per payable affiliate.
     *
     * @param array      $config       The validated config, as preview() takes it.
     * @param array|null $referralIds     Null claims every payable referral in the range. A list claims
     *                                    only those referrals, so a caller that showed a preview pays
     *                                    exactly what it showed.
     * @param array|null $expectedAmounts Referral id => the amount the caller showed. A claimed referral
     *                                    whose amount differs now is released, not paid.
     * @return array|\WP_Error payout, message, is_gateway
     */
    public static function create($config, $referralIds = null, $expectedAmounts = null)
    {
        $validated = static::preview($config);

        if (is_wp_error($validated)) {
            return $validated;
        }

        if (empty($validated['payable_affiliates'])) {
            return new \WP_Error('nothing_payable', __('No affiliates found for the given criteria.', 'fluent-affiliate'));
        }

        $affiliates = $validated['payable_affiliates'];
        $startDate = Arr::get($validated, 'config.start_date');
        $endDate = Arr::get($validated, 'config.end_date');
        $minPayout = Arr::get($validated, 'config.min_payout', 0);
        $dataConfig = $config;

        $payoutData = [
            'title'         => sanitize_text_field(Arr::get($dataConfig, 'title')),
            'description'   => sanitize_textarea_field(Arr::get($dataConfig, 'description')),
            'payout_method' => Arr::get($validated, 'config.payout_method', 'manual'),
            'status'        => 'draft',
            'total_amount'  => 0,
            'settings'      => [
                'start_date'    => $startDate,
                'end_date'      => $endDate,
                'min_payout'    => $minPayout,
                'affiliate_ids' => Arr::get($validated, 'config.affiliate_ids', []),
            ],
            'created_by'    => get_current_user_id(),
        ];

        if (Arr::get($validated, 'config.ignore_holding_period') === 'yes') {
            $payoutData['settings']['ignore_holding_period'] = 'yes';
        }

        $payoutData = apply_filters('fluent_affiliate/payout/before_create', $payoutData, $dataConfig, $affiliates);

        // ownership is stored, so it survives the gateway add-on being turned
        // off and never claims imported rows that share a method slug
        $isGateway = Helper::isGatewayPayoutMethod(Arr::get($payoutData, 'payout_method'));
        if ($isGateway) {
            $payoutData['settings']['gateway'] = true;
        }

        // create an empty payout
        $payout = Payout::create($payoutData);

        $preProcessCheck = apply_filters('fluent_affiliate/payout/before_processing', $payout, $affiliates, $dataConfig);

        if (is_wp_error($preProcessCheck)) {
            $payout->delete();

            return new \WP_Error('refused_by_filter', $preProcessCheck->get_error_message());
        }

        $currency = Utility::getCurrency();
        $payoutMethod = $payout->payout_method ?: 'manual';

        // a manual payout is settled by the admin outside the plugin, so its
        // referrals are paid now. A gateway payout keeps them processing (and
        // linked to their transaction) until the gateway reports the money sent.
        $referralStatus = $isGateway ? 'processing' : 'paid';

        $onlyIds = $referralIds === null ? null : array_values(array_unique(array_map('intval', (array) $referralIds)));

        foreach ($affiliates as $affiliate) {
            // claim and settle together, so a failure cannot leave referrals
            // stranded in the intermediate processing status
            $createdTransaction = Helper::dbTransaction(function () use ($affiliate, $payout, $startDate, $endDate, $currency, $payoutMethod, $referralStatus, $isGateway, $onlyIds, $expectedAmounts) {
                if ($onlyIds === []) {
                    return null;
                }

                // Atomically claim unpaid referrals by setting a temporary lock status.
                // Rows linked to a transaction (imported payouts) are not claimed.
                $affectedRows = Referral::query()
                    ->where('affiliate_id', $affiliate->id)
                    ->where('status', 'unpaid')
                    ->whereNull('payout_transaction_id')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->when($onlyIds !== null, function ($query) use ($onlyIds) {
                        return $query->whereIn('id', $onlyIds);
                    })
                    ->update(['status' => 'processing']);

                if ($affectedRows === 0) {
                    return null;
                }

                // read back this claim only; in-flight gateway rows already have a transaction
                $referrals = Referral::query()
                    ->where('affiliate_id', $affiliate->id)
                    ->where('status', 'processing')
                    ->whereNull('payout_transaction_id')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->when($onlyIds !== null, function ($query) use ($onlyIds) {
                        return $query->whereIn('id', $onlyIds);
                    })
                    ->get();

                // a referral edited after the caller's preview goes back to unpaid, unpaid by this run
                if ($expectedAmounts !== null) {
                    $changed = [];
                    foreach ($referrals as $referral) {
                        $id = (int) $referral->id;
                        if (!array_key_exists($id, $expectedAmounts) || abs((float) $referral->amount - (float) $expectedAmounts[$id]) > 0.000001) {
                            $changed[] = $id;
                        }
                    }

                    if ($changed) {
                        Referral::query()->whereIn('id', $changed)
                            ->where('status', 'processing')
                            ->whereNull('payout_transaction_id')
                            ->update(['status' => 'unpaid']);

                        $referrals = $referrals->filter(function ($referral) use ($changed) {
                            return !in_array((int) $referral->id, $changed, true);
                        });
                    }
                }

                $transactionData = [
                    'referral_ids' => [],
                    'total_amount' => 0
                ];
                foreach ($referrals as $referral) {
                    $transactionData['referral_ids'][] = $referral->id;
                    $transactionData['total_amount'] += $referral->amount;
                }

                // float addition leaves residue, and referrals saved before amounts
                // were rounded can still carry a third decimal
                $transactionData['total_amount'] = round($transactionData['total_amount'], 2);

                if (empty($transactionData['referral_ids'])) {
                    return null;
                }

                $eachTransaction = [
                    'affiliate_id'  => $affiliate->id,
                    'payout_id'     => $payout->id,
                    'payout_method' => $payoutMethod,
                    'status'        => 'processing',
                    'currency'      => $currency,
                    'total_amount'  => $transactionData['total_amount'],
                    'created_by'    => get_current_user_id(),
                    'settings'      => $isGateway ? ['gateway' => true] : null,
                ];

                $createdTransaction = Transaction::create($eachTransaction);

                Referral::query()->whereIn('id', $transactionData['referral_ids'])
                    ->update([
                        'payout_transaction_id' => $createdTransaction->id,
                        'payout_id'             => $payout->id,
                        'status'                => $referralStatus
                    ]);

                return $createdTransaction;
            });

            if ($createdTransaction) {
                $affiliate->recountEarnings();
            }
        }

        // another payout claimed every referral first, e.g. a double submit
        if (!Transaction::query()->where('payout_id', $payout->id)->exists()) {
            $payout->delete();

            return new \WP_Error('already_claimed', __('No unpaid referrals were left to pay. They may already be in another payout.', 'fluent-affiliate'));
        }

        $totalAmount = Transaction::query()
            ->where('payout_id', $payout->id)
            ->sum('total_amount');

        $payout->update([
            'total_amount' => $totalAmount,
            'status'       => 'processing',
        ]);

        do_action('fluent_affiliate/payout/processed', $payout, $affiliates);

        return [
            'payout'     => $payout,
            'is_gateway' => $isGateway,
            'message'    => $isGateway
                ? __('The payout is created. No money moves yet. To pay the affiliates, send the payout on this page.', 'fluent-affiliate')
                : __('Payout successfully created and marked as processing. Please complete the transactions now', 'fluent-affiliate'),
        ];
    }

    /**
     * Set one transaction to paid or processing.
     *
     * @param Payout      $payout
     * @param Transaction $transaction A transaction of $payout.
     * @param string      $newStatus   processing or paid.
     * @return array|\WP_Error transaction, processing_count, message, changed (false when it had that status already)
     */
    public static function setTransactionStatus(Payout $payout, Transaction $transaction, $newStatus)
    {
        if ($transaction->status == $newStatus) {
            return [
                'transaction' => $transaction,
                'changed'     => false,
                'message'     => __('Transaction status is already set to this value.', 'fluent-affiliate'),
            ];
        }

        // the gateway pays its own transactions; the admin steps in only after
        // the gateway reports one failed, and then only to settle it by hand
        if (!$transaction->isManual() && ($newStatus !== 'paid' || $transaction->status !== 'failed')) {
            return new \WP_Error('gateway_owned', __('This transaction is paid through a payout gateway. You can mark it as paid by hand only after the gateway reports it failed.', 'fluent-affiliate'));
        }

        if (!$transaction->isManual()) {
            // settle on freshly locked rows (payout, then transaction), so a send
            // cannot start or report back while the admin settles by hand. The
            // recounts and the paid hook run after the commit, off the locks.
            $result = Helper::dbTransaction(function () use ($transaction, $payout) {
                $lockedPayout = Payout::query()->where('id', $payout->id)->lockForUpdate()->first();
                $lockedTransaction = Transaction::query()->where('id', $transaction->id)->lockForUpdate()->first();

                if (!$lockedPayout || !$lockedTransaction || $lockedTransaction->status !== 'failed') {
                    return new \WP_Error('changed_meanwhile', __('This transaction changed meanwhile. Reload the page and try again.', 'fluent-affiliate'));
                }

                if ($lockedPayout->status === 'sending') {
                    return new \WP_Error('gateway_sending', __('The payout gateway is sending this payout. You can mark this transaction as paid after the send ends.', 'fluent-affiliate'));
                }

                return $lockedTransaction->settle();
            });

            if (is_wp_error($result)) {
                return $result;
            }

            $transaction->afterSettle($result[0], $result[1]);
        } else if ($newStatus === 'paid') {
            $transaction->markAsPaid($payout);
        } else {
            $transaction->status = $newStatus;
            $transaction->save();
            $payout = $payout->recountPaymentTotal();
            do_action('fluent_affiliate/payout/transaction/transaction_updated_to_' . $transaction->status, $transaction, $payout);

            // the affiliate can be gone (deleted before payouts were protected),
            // and its transaction still has to be settable
            $affiliate = Affiliate::query()->find($transaction->affiliate_id);
            $affiliate && $affiliate->recountEarnings();
        }

        $processingCount = $payout->transactions()->where('status', 'processing')->count();

        // the paid hook and the recount can load the full affiliate
        $transaction->unsetRelation('affiliate');

        return [
            'transaction'      => $transaction,
            'changed'          => true,
            'processing_count' => $processingCount,
            'message'          => __('Transaction status successfully updated', 'fluent-affiliate'),
        ];
    }

    /**
     * Mark every processing transaction of a manual payout as paid. Stops after
     * 30 seconds; processing_count tells the caller what is left.
     *
     * @param Payout $payout
     * @return array|\WP_Error processing_count, message (absent when the time ran out), marked (ids)
     */
    public static function markAllPaid(Payout $payout)
    {
        if (Arr::get((array) $payout->settings, 'gateway')) {
            return new \WP_Error('gateway_owned', __('The transactions of this payout are paid through a payout gateway, so they cannot be marked as paid in bulk.', 'fluent-affiliate'));
        }

        $processingTransactions = Transaction::query()
            ->where('payout_id', $payout->id)
            ->where('status', 'processing')
            ->get();

        if ($processingTransactions->isEmpty()) {
            return [
                'processing_count' => 0,
                'marked'           => [],
                'message'          => __('No processing transactions found for this payout.', 'fluent-affiliate'),
            ];
        }

        $startTime = time();
        $marked = [];

        foreach ($processingTransactions as $transaction) {
            // Check if the request is still valid
            if (time() - $startTime > 30) {
                return [
                    'processing_count' => Transaction::query()
                        ->where('payout_id', $payout->id)
                        ->where('status', 'processing')
                        ->count(),
                    'marked'           => $marked,
                ];
            }

            if ($transaction->status !== 'processing' || !$transaction->isManual()) {
                continue;
            }

            $transaction->markAsPaid($payout);
            $marked[] = (int) $transaction->id;
        }

        $newCount = Transaction::query()
            ->where('payout_id', $payout->id)
            ->where('status', 'processing')
            ->count();

        return [
            'processing_count' => $newCount,
            'marked'           => $marked,
            'message'          => __('All processing transactions have been marked as paid.', 'fluent-affiliate'),
        ];
    }

    /**
     * Delete a transaction that is not paid and release its referrals to unpaid.
     *
     * @param Payout      $payout
     * @param Transaction    $transaction   A transaction of $payout.
     * @param int|string|null $transactionId The id the deleted hook receives; the caller's own value
     *                                       (the admin route passes its route parameter).
     * @return Payout|\WP_Error The payout, with its total recounted.
     */
    public static function removeTransaction(Payout $payout, Transaction $transaction, $transactionId = null)
    {
        if ($transaction->status === 'paid') {
            return new \WP_Error('transaction_paid', __('A paid transaction cannot be deleted. Mark it as processing first.', 'fluent-affiliate'));
        }

        // removing a row the gateway may still pay would let its referrals be
        // paid a second time by the next payout
        if ($transaction->isLockedByGateway($payout)) {
            return new \WP_Error('gateway_locked', __('The payout gateway may still pay this transaction. You can remove it only after the gateway reports it failed.', 'fluent-affiliate'));
        }

        if ($transactionId === null) {
            $transactionId = $transaction->id;
        }

        do_action('fluent_affiliate/payout/transaction/deleting', $transaction, $payout);
        // the affiliate can be gone (deleted before payouts were protected),
        // and its transaction still has to be removable
        $affiliate = Affiliate::query()->find($transaction->affiliate_id);

        // re-check on freshly locked rows (payout, then transaction), so a gateway
        // send or a settlement that lands meanwhile cannot have its referrals released
        $blocked = Helper::dbTransaction(function () use ($transaction, $payout) {
            $lockedPayout = Payout::query()->where('id', $payout->id)->lockForUpdate()->first();
            $lockedTransaction = Transaction::query()->where('id', $transaction->id)->lockForUpdate()->first();

            if (!$lockedPayout || !$lockedTransaction || $lockedTransaction->status === 'paid') {
                return new \WP_Error('paid_meanwhile', __('This transaction was paid meanwhile, so it cannot be deleted. Mark it as processing first.', 'fluent-affiliate'));
            }

            if ($lockedTransaction->isLockedByGateway($lockedPayout)) {
                return new \WP_Error('gateway_locked', __('The payout gateway may still pay this transaction. You can remove it only after the gateway reports it failed.', 'fluent-affiliate'));
            }

            Referral::query()
                ->where('payout_transaction_id', $lockedTransaction->id)
                ->update([
                    'payout_transaction_id' => null,
                    'payout_id'             => null,
                    'status'                => 'unpaid',
                ]);

            $lockedTransaction->delete();

            return null;
        });

        if (is_wp_error($blocked)) {
            return $blocked;
        }
        // recount the affiliates
        $affiliate && $affiliate->recountEarnings();
        $payout = $payout->recountPaymentTotal();
        do_action('fluent_affiliate/payout/transaction/deleted', $transactionId, $payout);

        return $payout;
    }
}
