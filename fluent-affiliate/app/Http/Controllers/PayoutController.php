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
use FluentAffiliate\App\Services\PayoutService;

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
        $config = $this->validatedPayoutConfig($request);

        $result = PayoutService::preview($config);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message(),
            ]);
        }

        return $result;
    }

    protected static function resolvePayoutMethod($config)
    {
        return PayoutService::resolvePayoutMethod($config);
    }

    public function processPayout(Request $request)
    {
        $result = PayoutService::create($this->validatedPayoutConfig($request));

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message(),
            ]);
        }

        return [
            'payout'  => $result['payout'],
            'message' => $result['message'],
        ];
    }

    /**
     * @param Request $request
     * @return array The config, checked against the payout form's rules.
     */
    protected function validatedPayoutConfig(Request $request)
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

        return $config;
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

        $result = PayoutService::setTransactionStatus($payout, $transaction, $request->get('status'));

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        if (!$result['changed']) {
            return [
                'transaction' => $result['transaction'],
                'message'     => $result['message'],
            ];
        }

        return [
            'transaction'      => $result['transaction'],
            'processing_count' => $result['processing_count'],
            'message'          => $result['message'],
        ];
    }

    public function bulkPatchTransactions(Request $request, $payoutId)
    {
        $payout = Payout::findOrFail($payoutId);

        $result = PayoutService::markAllPaid($payout);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message(),
            ]);
        }

        unset($result['marked']);

        return $result;
    }

    public function deleteTransaction(Request $request, $payoutId, $transactionId)
    {
        $payout = Payout::query()->findOrFail($payoutId);
        $transaction = Transaction::query()
            ->where('payout_id', $payout->id)
            ->findOrFail($transactionId);

        $payout = PayoutService::removeTransaction($payout, $transaction, $transactionId);

        if (is_wp_error($payout)) {
            return $this->sendError([
                'message' => $payout->get_error_message()
            ]);
        }

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
