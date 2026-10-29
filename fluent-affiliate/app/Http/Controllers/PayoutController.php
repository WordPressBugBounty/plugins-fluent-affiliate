<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\Framework\Validator\ValidationException;
use FluentAffiliate\Framework\Http\Request\Request;
use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Sanitizer;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\User;

class PayoutController extends Controller
{
    /**
     * @param Request $request
     * @return array|\WP_REST_Response
     */
    public function index(Request $request)
    {
        $payouts = Payout::query()
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy(
                $request->getSafe('order_by', 'sanitize_sql_orderby', 'id'),
                $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC')
            )
            ->paginate(Helper::clampPerPage($request->getSafe('per_page', 'intval', 10)));

        foreach ($payouts as $payout) {
            $payout->referrals_count = $payout->referrals()->count();
            $payout->transactions_count = $payout->transactions()->count();
            $payout->affiliates_count = $payout->affiliates()->count();
            $payout->failed_count = $payout->transactions()->where('status', 'failed')->count();
        }

        return [
            'payouts' => $payouts,
        ];
    }

    /**
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function show(Request $request, $id)
    {
        // payout readers see who created the payout, not that user's account
        $payout = Payout::query()->with(['creator' => User::nameOnly()])->findOrFail($id);
        $payout->referrals_count = $payout->referrals()->count();
        $payout->transactions_count = $payout->transactions()->count();
        // a gateway sends only the open rows, so its confirmation counts those
        $payout->processing_count = $payout->transactions()->where('status', 'processing')->count();
        $payout->processing_amount = (float) $payout->transactions()->where('status', 'processing')->sum('total_amount');

        // a gateway adds the state only its server can decide, such as what the admin may do next
        $payout = apply_filters('fluent_affiliate/payout/details', $payout);

        return [
            'payout' => $payout,
        ];
    }

    /**
     * @param Request $request
     * @param $payout
     * @return array|\WP_REST_Response
     * @throws ValidationException
     */
    public function updatePayout(Request $request, $payout)
    {
        $request->validate([
            'title'       => 'required|string|min:3|max:255',
            'description' => 'required|string|min:3|max:500',
        ]);

        $payout = Payout::query()->findOrFail($payout);

        $payout->title = $request->getSafe('title', Sanitizer::SANITIZE_TEXT_FIELD);
        $payout->description = $request->getSafe('description', Sanitizer::SANITIZE_TEXTAREA_FIELD);

        $payout->save();

        return $this->sendSuccess([
            'payout'  => $payout,
            'message' => __('Payout successfully updated', 'fluent-affiliate'),
        ]);
    }

    /**
     * @param Request $request
     * @return array[]|\WP_REST_Response
     */
    public function validatePayoutConfig(Request $request)
    {
        $config = $request->get('config', []);

        $this->validate($config, [
            'title'                 => 'required|string|max:255',
            'description'           => 'required|string',
            'affiliate_ids'         => 'nullable|array',
            'start_date'            => 'nullable|string',
            'end_date'              => 'nullable|string',
            'min_payout'            => 'nullable|numeric|min:0',
            'ignore_holding_period' => 'nullable|in:yes,no',
        ]);

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
            return $this->sendError([
                'message' => __('Start date cannot be greater than end date.', 'fluent-affiliate'),
            ]);
        }

        $affiliateIds = (array) Arr::get($config, 'affiliate_ids', []);
        $affiliateIds = array_map('intval', $affiliateIds);

        global $wpdb;

        // referrals inside the holding period are not payable yet. Moving the end date
        // back covers the preview and the claim in processPayout(), which reads these dates
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
                return $this->sendError([
                    'message' => __('All referrals in this date range are still in the holding period.', 'fluent-affiliate'),
                ]);
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

        // one filter serves both the review preview and processPayout(), so a
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

    protected static function resolvePayoutMethod($config)
    {
        // an unknown method falls back to manual, as every payout did before
        $method = sanitize_key((string) Arr::get($config, 'payout_method', 'manual'));

        return array_key_exists($method, Helper::getPayoutMethods()) ? $method : 'manual';
    }

    public function processPayout(Request $request)
    {
        $validated = $this->validatePayoutConfig($request);
        // a validation error is a response object, which cannot be read as an array
        if ($validated instanceof \WP_REST_Response) {
            return $validated;
        }

        if (empty($validated['payable_affiliates'])) {
            return $this->sendError([
                'message' => __('No affiliates found for the given criteria.', 'fluent-affiliate'),
            ]);
        }

        $affiliates = $validated['payable_affiliates'];
        $startDate = Arr::get($validated, 'config.start_date');
        $endDate = Arr::get($validated, 'config.end_date');
        $minPayout = Arr::get($validated, 'config.min_payout', 0);
        $dataConfig = $request->get('config', []);

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

            return $this->sendError([
                'message' => $preProcessCheck->get_error_message(),
            ]);
        }

        $currency = Utility::getCurrency();
        $payoutMethod = $payout->payout_method ?: 'manual';

        // a manual payout is settled by the admin outside the plugin, so its
        // referrals are paid now. A gateway payout keeps them processing (and
        // linked to their transaction) until the gateway reports the money sent.
        $referralStatus = $isGateway ? 'processing' : 'paid';

        foreach ($affiliates as $affiliate) {
            // claim and settle together, so a failure cannot leave referrals
            // stranded in the intermediate processing status
            $createdTransaction = Helper::dbTransaction(function () use ($affiliate, $payout, $startDate, $endDate, $currency, $payoutMethod, $referralStatus, $isGateway) {
                // Atomically claim unpaid referrals by setting a temporary lock status.
                // Rows linked to a transaction (imported payouts) are not claimed.
                $affectedRows = Referral::query()
                    ->where('affiliate_id', $affiliate->id)
                    ->where('status', 'unpaid')
                    ->whereNull('payout_transaction_id')
                    ->whereBetween('created_at', [$startDate, $endDate])
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
                    ->get();

                $transactionData = [
                    'referral_ids' => [],
                    'total_amount' => 0
                ];
                foreach ($referrals as $referral) {
                    $transactionData['referral_ids'][] = $referral->id;
                    $transactionData['total_amount'] += $referral->amount;
                }

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

            return $this->sendError([
                'message' => __('No unpaid referrals were left to pay. They may already be in another payout.', 'fluent-affiliate'),
            ]);
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
            'payout'  => $payout,
            'message' => $isGateway
                ? __('The payout is created. No money moves yet. To pay the affiliates, send the payout on this page.', 'fluent-affiliate')
                : __('Payout successfully created and marked as processing. Please complete the transactions now', 'fluent-affiliate'),
        ];
    }

    public function getTransactions(Request $request, $payoutId)
    {
        $payout = Payout::query()->findOrFail($payoutId);

        // the payment email is payout data; the WordPress account is not
        $transactions = Transaction::query()->where('payout_id', $payout->id)
            ->with(['affiliate.user' => User::nameOnly()])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy(
                $request->getSafe('order_by', 'sanitize_sql_orderby', 'id'),
                $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC')
            )
            ->paginate(Helper::clampPerPage($request->getSafe('per_page', 'intval', 10)));

        return [
            'transactions'     => $transactions,
            'processing_count' => Transaction::query()
                ->where('payout_id', $payout->id)
                ->where('status', 'processing')
                ->count()
        ];
    }

    public function patchTransaction(Request $request, $payoutId, $transactionId)
    {
        $payout = Payout::findOrFail($payoutId);
        $transaction = Transaction::query()->where('payout_id', $payout->id)->findOrFail($transactionId);

        $this->validate($request->all(), [
            'status' => 'required|string|in:processing,paid',
        ]);

        $newStatus = $request->get('status');

        if ($transaction->status == $newStatus) {
            return [
                'transaction' => $transaction,
                'message'     => __('Transaction status is already set to this value.', 'fluent-affiliate'),
            ];
        }

        // the gateway pays its own transactions; the admin steps in only after
        // the gateway reports one failed, and then only to settle it by hand
        if (!$transaction->isManual() && ($newStatus !== 'paid' || $transaction->status !== 'failed')) {
            return $this->sendError([
                'message' => __('This transaction is paid through a payout gateway. You can mark it as paid by hand only after the gateway reports it failed.', 'fluent-affiliate'),
            ]);
        }

        if (!$transaction->isManual()) {
            // settle on freshly locked rows (payout, then transaction), so a send
            // cannot start or report back while the admin settles by hand. The
            // recounts and the paid hook run after the commit, off the locks.
            $result = Helper::dbTransaction(function () use ($transaction, $payout) {
                $lockedPayout = Payout::query()->where('id', $payout->id)->lockForUpdate()->first();
                $lockedTransaction = Transaction::query()->where('id', $transaction->id)->lockForUpdate()->first();

                if (!$lockedPayout || !$lockedTransaction || $lockedTransaction->status !== 'failed') {
                    return __('This transaction changed meanwhile. Reload the page and try again.', 'fluent-affiliate');
                }

                if ($lockedPayout->status === 'sending') {
                    return __('The payout gateway is sending this payout. You can mark this transaction as paid after the send ends.', 'fluent-affiliate');
                }

                return $lockedTransaction->settle();
            });

            if (is_string($result)) {
                return $this->sendError([
                    'message' => $result
                ]);
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
            'processing_count' => $processingCount,
            'message'          => __('Transaction status successfully updated', 'fluent-affiliate'),
        ];
    }

    public function bulkPatchTransactions(Request $request, $payoutId)
    {
        $payout = Payout::findOrFail($payoutId);

        if (Arr::get((array) $payout->settings, 'gateway')) {
            return $this->sendError([
                'message' => __('The transactions of this payout are paid through a payout gateway, so they cannot be marked as paid in bulk.', 'fluent-affiliate'),
            ]);
        }

        $processingTransactions = Transaction::query()
            ->where('payout_id', $payout->id)
            ->where('status', 'processing')
            ->get();

        if ($processingTransactions->isEmpty()) {
            return [
                'processing_count' => 0,
                'message'          => __('No processing transactions found for this payout.', 'fluent-affiliate'),
            ];
        }

        $startTime = time();

        foreach ($processingTransactions as $transaction) {
            // Check if the request is still valid
            if (time() - $startTime > 30) {
                return [
                    'processing_count' => Transaction::query()
                        ->where('payout_id', $payout->id)
                        ->where('status', 'processing')
                        ->count()
                ];
            }

            if ($transaction->status !== 'processing' || !$transaction->isManual()) {
                continue;
            }

            $transaction->markAsPaid($payout);
        }

        $newCount = Transaction::query()
            ->where('payout_id', $payout->id)
            ->where('status', 'processing')
            ->count();

        return [
            'processing_count' => $newCount,
            'message'          => __('All processing transactions have been marked as paid.', 'fluent-affiliate'),
        ];

    }

    public function deleteTransaction(Request $request, $payoutId, $transactionId)
    {
        $payout = Payout::query()->findOrFail($payoutId);
        $transaction = Transaction::query()
            ->where('payout_id', $payout->id)
            ->findOrFail($transactionId);

        if ($transaction->status === 'paid') {
            return $this->sendError([
                'message' => __('A paid transaction cannot be deleted. Mark it as processing first.', 'fluent-affiliate')
            ]);
        }

        // removing a row the gateway may still pay would let its referrals be
        // paid a second time by the next payout
        if ($transaction->isLockedByGateway($payout)) {
            return $this->sendError([
                'message' => __('The payout gateway may still pay this transaction. You can remove it only after the gateway reports it failed.', 'fluent-affiliate')
            ]);
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
                return __('This transaction was paid meanwhile, so it cannot be deleted. Mark it as processing first.', 'fluent-affiliate');
            }

            if ($lockedTransaction->isLockedByGateway($lockedPayout)) {
                return __('The payout gateway may still pay this transaction. You can remove it only after the gateway reports it failed.', 'fluent-affiliate');
            }

            Referral::query()
                ->where('payout_transaction_id', $lockedTransaction->id)
                ->update([
                    'payout_transaction_id' => null,
                    'payout_id'             => null,
                    'status'                => 'unpaid',
                ]);

            $lockedTransaction->delete();

            return '';
        });

        if ($blocked) {
            return $this->sendError([
                'message' => $blocked
            ]);
        }
        // recount the affiliates
        $affiliate && $affiliate->recountEarnings();
        $payout = $payout->recountPaymentTotal();
        do_action('fluent_affiliate/payout/transaction/deleted', $transactionId, $payout);

        return [
            'payout'  => $payout,
            'message' => __('Transaction successfully deleted', 'fluent-affiliate')
        ];
    }

    public function getReferrals(Request $request, $payoutId)
    {
        $affiliateId = $request->getSafe('affiliate_id', 'intval', 0);

        Payout::query()->findOrFail($payoutId);

        $referrals = Referral::query()->where('payout_id', $payoutId)
            ->with(['affiliate' => Affiliate::identityOnly()])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->orderBy(
                $request->getSafe('order_by', 'sanitize_sql_orderby', 'id'),
                $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC')
            )
            ->when($affiliateId, function ($query) use ($affiliateId) {
                return $query->where('affiliate_id', $affiliateId);
            })
            ->paginate(Helper::clampPerPage($request->getSafe('per_page', 'intval', 10)));

        foreach ($referrals as $referral) {
            $referral->provider_url = $referral->getProviderUrl();
        }


        $data = [
            'referrals' => $referrals,
        ];

        if ($request->get('with_affiliate_lists') === 'yes') {
            // this route is open to referral readers, so the filter list only names
            // the affiliates
            $affiliates = Affiliate::query()
                ->tap(Affiliate::identityOnly())
                ->whereHas('referrals', function ($query) use ($payoutId) {
                    $query->where('payout_id', $payoutId);
                })
                ->get();

            $data['affiliate_lists'] = $affiliates;
        }

        return $data;
    }

    public function getExportableTransactions(Request $request, $payoutId)
    {
        $payout = Payout::findOrFail($payoutId);

        if ($request->getSafe('format', 'sanitize_key') === 'paypal') {
            return $this->getPayPalPayoutsFile($payout);
        }

        $limit = apply_filters('fluent_affiliate/data_export_limit', 5000);

        $transactionQuery = Transaction::query()->where('payout_id', $payout->id)
            ->with(['affiliate.user'])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy(
                $request->getSafe('order_by', 'sanitize_sql_orderby', 'id'),
                $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC')
            );

        $total = $transactionQuery->count();
        $limited = $total > $limit;

        $transactions = $transactionQuery->take($limit)->get();

        $transactions = $transactions->map(function ($transaction) {
            $affiliate = $transaction->affiliate;
            $user = $affiliate ? $affiliate->user : null;

            return [
                'affiliate_id'   => (int)$transaction->affiliate_id,
                'affiliate_name' => Sanitizer::forCsv($user ? $user->full_name : ''),
                'email'          => Sanitizer::forCsv($user ? $user->user_email : ''),
                'payout_email'   => Sanitizer::forCsv($affiliate ? $affiliate->payment_email : ''),
                'amount'         => Sanitizer::amountForCsv($transaction->total_amount),
                'currency'       => Sanitizer::forCsv($transaction->currency),
            ];
        });

        return [
            'transactions' => $transactions,
            'limited'      => $limited,
            'total'        => $total,
        ];
    }

    /**
     * The processing transactions in PayPal's Payouts Web upload format:
     * email, amount and currency, one row per transaction and no header.
     */
    protected function getPayPalPayoutsFile($payout)
    {
        // the gateway already sends this money, so a file would pay it a second time
        if (Arr::get((array) $payout->settings, 'gateway')) {
            return $this->sendError([
                'message' => __('This payout is sent through a payment gateway. You cannot export it as a PayPal Payouts file.', 'fluent-affiliate')
            ]);
        }

        // PayPal accepts at most 5,000 rows in one file
        $limit = 5000;

        $transactionQuery = Transaction::query()->where('payout_id', $payout->id)
            ->where('status', 'processing')
            ->with(['affiliate.user'])
            ->orderBy('id', 'ASC');

        $total = $transactionQuery->count();
        $transactions = $transactionQuery->take($limit)->get();

        $rows = [];
        $skipped = [];

        foreach ($transactions as $transaction) {
            $affiliate = $transaction->affiliate;
            $email = $affiliate ? trim((string) $affiliate->payment_email) : '';

            // a leading formula character would need a CSV escape, which PayPal reads as part of the email
            if (!is_email($email) || strpbrk($email[0], '=+-@') !== false) {
                $user = $affiliate ? $affiliate->user : null;
                $skipped[] = sanitize_text_field($user && $user->full_name ? $user->full_name : '#' . $transaction->affiliate_id);
                continue;
            }

            $currency = strtoupper((string) $transaction->currency);
            // PayPal takes whole units for these currencies
            $decimals = in_array($currency, ['HUF', 'JPY', 'TWD'], true) ? 0 : 2;
            // PayPal's web upload reads EUR and BRL amounts with a decimal comma
            $decimalSeparator = in_array($currency, ['EUR', 'BRL'], true) ? ',' : '.';

            $rows[] = [
                'email'    => $email,
                // quotes a comma amount, so it stays one CSV column
                'amount'   => Sanitizer::forCsv(Sanitizer::amountForCsv($transaction->total_amount, $decimals, $decimalSeparator)),
                'currency' => $currency,
            ];
        }

        return [
            'rows'    => $rows,
            'skipped' => $skipped,
            'limited' => $total > $limit,
            'total'   => $total,
        ];
    }

}
