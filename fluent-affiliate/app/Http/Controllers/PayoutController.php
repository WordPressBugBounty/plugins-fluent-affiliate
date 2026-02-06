<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\Framework\Validator\ValidationException;
use FluentAffiliate\Framework\Http\Request\Request;
use FluentAffiliate\App\Helper\Sanitizer;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Payout;

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
            ->paginate($request->getSafe('per_page', 'intval', 10));

        foreach ($payouts as $payout) {
            $payout->referrals_count = $payout->referrals()->count();
            $payout->transactions_count = $payout->transactions()->count();
            $payout->affiliates_count = $payout->affiliates()->count();
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
        $payout = Payout::query()->with('creator')->findOrFail($id);
        $payout->referrals_count = $payout->referrals()->count();
        $payout->transactions_count = $payout->transactions()->count();

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
            'title'         => 'required|string|max:255',
            'description'   => 'required|string',
            'affiliate_ids' => 'nullable|array',
            'start_date'    => 'nullable|string',
            'end_date'      => 'nullable|string',
            'min_payout'    => 'nullable|min:0',
        ]);

        $startDate = Arr::get($config, 'start_date');
        $endDate = Arr::get($config, 'end_date');
        $minAmount = Arr::get($config, 'min_payout', 0);

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

        $dateRange = [
            $startDate,
            $endDate
        ];

        $affiliateIds = Arr::get($config, 'affiliate_ids', []);
        $affiliateIds = array_map('intval', $affiliateIds);

        global $wpdb;

        $affiliates = Affiliate::query()
            ->select(
                'fa_affiliates.*',
                FluentAffiliate('db')->raw('COUNT(' . $wpdb->prefix . 'fa_referrals.id) as payable_referral_count'),
                FluentAffiliate('db')->raw('SUM(' . $wpdb->prefix . 'fa_referrals.amount) as total_payable_amount')
            )
            ->join('fa_referrals', 'fa_affiliates.id', '=', 'fa_referrals.affiliate_id')
            ->where('fa_referrals.status', 'unpaid')
            ->whereBetween('fa_referrals.created_at', $dateRange)
            ->when($affiliateIds, function ($query) use ($affiliateIds) {
                return $query->whereIn('fa_affiliates.id', $affiliateIds)
                    ->whereIn('fa_referrals.affiliate_id', $affiliateIds);
            })
            ->whereHas('user')
            ->groupBy('fa_affiliates.id')
            ->havingRaw('SUM(' . $wpdb->prefix . 'fa_referrals.amount) > ?', [$minAmount])
            ->orderBy('total_payable_amount', 'DESC')
            ->get();

        $totalAmount = $affiliates->sum('total_payable_amount');

        return [
            'payable_affiliates'   => $affiliates,
            'payable_total_amount' => $totalAmount,
            'config'               => [
                'start_date'    => $startDate,
                'end_date'      => $endDate,
                'min_payout'    => $minAmount,
                'affiliate_ids' => $affiliateIds,
            ]
        ];
    }

    public function processPayout(Request $request)
    {
        $validated = $this->validatePayoutConfig($request);
        if (empty($validated['payable_affiliates'])) {
            if ($validated instanceof \WP_REST_Response) {
                return $validated;
            }

            return $this->sendError([
                'message' => __('No affiliates found for the given criteria.', 'fluent-affiliate'),
            ]);
        }

        $affiliates = $validated['payable_affiliates'];
        $startDate = Arr::get($validated, 'config.start_date');
        $endDate = Arr::get($validated, 'config.end_date');
        $minPayout = Arr::get($validated, 'config.min_payout', 0);
        $dataConfig = $request->get('config', []);

        // create an empty payout
        $payout = Payout::create([
            'title'         => sanitize_text_field(Arr::get($dataConfig, 'title')),
            'description'   => sanitize_textarea_field(Arr::get($dataConfig, 'description')),
            'payout_method' => 'manual',
            'status'        => 'draft',
            'total_amount'  => 0,
            'settings'      => [
                'start_date'    => $startDate,
                'end_date'      => $endDate,
                'min_payout'    => $minPayout,
                'affiliate_ids' => Arr::get($dataConfig, 'affiliate_ids', []),
            ],
            'created_by'    => get_current_user_id(),
        ]);

        foreach ($affiliates as $affiliate) {
            // Atomically claim unpaid referrals by setting a temporary lock status
            $affectedRows = Referral::query()
                ->where('affiliate_id', $affiliate->id)
                ->where('status', 'unpaid')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->update(['status' => 'processing']);
        
            if ($affectedRows === 0) {
                continue;
            }

            // Now read the claimed referrals (status = 'processing')
            $referrals = Referral::query()
                ->where('affiliate_id', $affiliate->id)
                ->where('status', 'processing')
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
                continue;
            }

            $eachTransaction = [
                'affiliate_id'  => $affiliate->id,
                'payout_id'     => $payout->id,
                'payout_method' => 'manual',
                'status'        => 'processing',
                'currency'      => 'USD',
                'total_amount'  => $transactionData['total_amount'],
                'created_by'    => get_current_user_id(),
            ];

            $createdTransaction = Transaction::create($eachTransaction);

            Referral::query()->whereIn('id', $transactionData['referral_ids'])
                ->update([
                    'payout_transaction_id' => $createdTransaction->id,
                    'payout_id'             => $payout->id,
                    'status'                => 'paid'
                ]);

            $affiliate->recountEarnings();
        }

        $totalAmount = Transaction::query()
            ->where('payout_id', $payout->id)
            ->sum('total_amount');

        $payout->update([
            'total_amount' => $totalAmount,
            'status'       => 'processing',
        ]);

        return [
            'payout'  => $payout,
            'message' => __('Payout successfully created and marked as processing. Please complete the transactions now', 'fluent-affiliate'),
        ];
    }

    public function getTransactions(Request $request, $payoutId)
    {
        $payout = Payout::query()->findOrFail($payoutId);

        $transactions = Transaction::query()->where('payout_id', $payout->id)
            ->with(['affiliate'])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy(
                $request->getSafe('order_by', 'sanitize_sql_orderby', 'id'),
                $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC')
            )
            ->paginate($request->getSafe('per_page', 'intval', 10));

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

        $transaction->status = $newStatus;
        $transaction->save();
        $payout = $payout->recountPaymentTotal();
        do_action('fluent_affiliate/payout/transaction/transaction_updated_to_' . $transaction->status, $transaction, $payout);

        $processingCount = $payout->transactions()->where('status', 'processing')->count();

        $affiliate = Affiliate::query()->findOrFail($transaction->affiliate_id);
        $affiliate->recountEarnings();

        return [
            'transaction'      => $transaction,
            'processing_count' => $processingCount,
            'message'          => __('Transaction status successfully updated', 'fluent-affiliate'),
        ];
    }

    public function bulkPatchTransactions(Request $request, $payoutId)
    {
        $payout = Payout::findOrFail($payoutId);

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

            if ($transaction->status !== 'processing') {
                continue;
            }

            $transaction->status = 'paid';
            $transaction->save();
            $payout = $payout->recountPaymentTotal();

            $affiliate = Affiliate::find($transaction->affiliate_id);
            if ($affiliate) {
                $affiliate->recountEarnings();
            }

            do_action('fluent_affiliate/payout/transaction/transaction_updated_to_' . $transaction->status, $transaction, $payout);
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

        do_action('fluent_affiliate/payout/transaction/deleting', $transaction, $payout);
        $affiliate = Affiliate::query()->findOrFail($transaction->affiliate_id);

        // get the total amount of the these referrals
        Referral::query()
            ->where('payout_transaction_id', $transaction->id)
            ->update([
                'payout_transaction_id' => null,
                'payout_id'             => null,
                'status'                => 'unpaid',
            ]);

        $transaction->delete();
        // recount the affiliates
        $affiliate->recountEarnings();
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
            ->with(['affiliate'])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->orderBy(
                $request->getSafe('order_by', 'sanitize_sql_orderby', 'id'),
                $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC')
            )
            ->when($affiliateId, function ($query) use ($affiliateId) {
                return $query->where('affiliate_id', $affiliateId);
            })
            ->paginate($request->getSafe('per_page', 'intval', 10));

        foreach ($referrals as $referral) {
            $referral->provider_url = $referral->getProviderUrl();
        }


        $data = [
            'referrals' => $referrals,
        ];

        if ($request->get('with_affiliate_lists') === 'yes') {
            $affiliates = Affiliate::query()
                ->whereHas('referrals', function ($query) use ($payoutId) {
                    $query->where('payout_id', $payoutId);
                })
                ->with(['user'])
                ->get();

            $data['affiliate_lists'] = $affiliates;
        }

        return $data;
    }


    public function getExportableTransactions(Request $request, $payoutId)
    {
        $payout = Payout::findOrFail($payoutId);

        $transactions = Transaction::query()->where('payout_id', $payout->id)
            ->with(['affiliate'])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byStatus($request->getSafe('status', 'sanitize_text_field'))
            ->orderBy(
                $request->getSafe('order_by', 'sanitize_sql_orderby', 'id'),
                $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC')
            )
            ->get();

        $transactions = $transactions->map(function ($transaction) {
            return [
                'affiliate_id'   => (int)$transaction->affiliate_id,
                'affiliate_name' => Sanitizer::forCsv($transaction->affiliate->user_details['full_name']),
                'email'          => Sanitizer::forCsv($transaction->affiliate->user_details['email']),
                'payout_email'   => Sanitizer::forCsv($transaction->affiliate->payment_email),
                'amount'         => $transaction->total_amount,
                'currency'       => $transaction->currency,
            ];
        });

        return [
            'transactions' => $transactions,
        ];
    }

}
