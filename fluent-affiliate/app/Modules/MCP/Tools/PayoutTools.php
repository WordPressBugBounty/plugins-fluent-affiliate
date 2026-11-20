<?php

namespace FluentAffiliate\App\Modules\MCP\Tools;

use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Modules\MCP\Formatter;
use FluentAffiliate\App\Modules\MCP\McpHelper;
use FluentAffiliate\App\Services\PayoutService;
use FluentAffiliate\Framework\Support\Arr;

class PayoutTools
{
    /** Rows a preview lists by name; the confirm value still covers every row. */
    const PREVIEW_ROWS = 50;

    public static function listPayouts($params)
    {
        $range = McpHelper::dateRange($params, 'created_from', 'created_to');
        if (is_wp_error($range)) {
            return $range;
        }

        $paging = McpHelper::pagination($params);
        $query = Payout::query();

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        if ($range[0]) {
            $query->where('created_at', '>=', $range[0]);
        }
        if ($range[1]) {
            $query->where('created_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $payouts = $query->orderBy('id', 'DESC')
            ->offset(($paging['page'] - 1) * $paging['per_page'])
            ->limit($paging['per_page'])
            ->get();

        $counts = self::transactionCounts($payouts->pluck('id')->toArray());

        $rows = [];
        foreach ($payouts as $payout) {
            $rows[] = Formatter::payout($payout, Arr::get($counts, $payout->id, ['transactions' => 0, 'transactions_to_settle' => 0, 'transactions_failed' => 0]));
        }

        return McpHelper::envelope(
            sprintf('%d payout(s) match; showing %d, newest first.', $total, count($rows)),
            $rows,
            McpHelper::pagingMeta($total, $paging)
        );
    }

    public static function getPayout($params)
    {
        $payout = Payout::query()->find($params['id']);
        if (!$payout) {
            return McpHelper::notFound('Payout ' . $params['id']);
        }

        $paging = McpHelper::pagination($params, 50);
        $counts = self::transactionCounts([$payout->id]);
        $data = Formatter::payout($payout, Arr::get($counts, $payout->id, []));
        $data['referrals'] = (int) Referral::query()->where('payout_id', $payout->id)->count();

        $query = Transaction::query()
            ->with(['affiliate' => function ($q) {
                $q->select(['id', 'user_id', 'payment_email'])->with(['user' => function ($userQuery) {
                    $userQuery->select(['ID', 'display_name']);
                }]);
            }])
            ->where('payout_id', $payout->id);

        if (!empty($params['transaction_status'])) {
            $query->where('status', $params['transaction_status']);
        }

        $total = (clone $query)->count();
        $transactions = $query->orderBy('id', 'ASC')
            ->offset(($paging['page'] - 1) * $paging['per_page'])
            ->limit($paging['per_page'])
            ->get();

        $data['transactions_list'] = [];
        foreach ($transactions as $transaction) {
            $data['transactions_list'][] = Formatter::transaction($transaction);
        }

        $summary = sprintf('Payout %d "%s": %s %s, %s.', $payout->id, $payout->title, number_format($data['total_amount'], 2, '.', ''), $data['currency'], $payout->status);
        if (!empty($data['transactions_to_settle'])) {
            $summary .= sprintf(' %d transaction(s) still to settle.', $data['transactions_to_settle']);
        }

        return McpHelper::envelope($summary, $data, McpHelper::pagingMeta($total, $paging));
    }

    /**
     * Create a payout run in two calls. The confirm value covers every referral
     * the preview pays; the second call pays exactly those.
     */
    public static function createPayout($params)
    {
        $config = self::payoutConfig($params);
        if (is_wp_error($config)) {
            return $config;
        }

        $preview = self::buildPreview($config);
        if (is_wp_error($preview)) {
            return $preview;
        }

        list($shown, $referrals, $recipients) = $preview;

        if (!$referrals) {
            return McpHelper::envelope('Nothing to pay: no unpaid referrals match.', array_merge($shown, ['needs_confirmation' => false]));
        }

        $action = 'create-payout';
        // the referrals with their amounts, and where each affiliate is paid
        // and the currency the transactions will record: a changed site currency makes the value stale
        $fingerprint = McpHelper::fingerprint([$config, $referrals, $recipients, $shown['records_in_currency']]);
        $confirm = McpHelper::confirmValue($action, 'payout', $fingerprint);

        if (!array_key_exists('confirm', $params)) {
            return McpHelper::confirmationNeeded($action, $shown, $confirm, sprintf(
                'Preview: pay %s to %d affiliate(s) for %d referral(s). Nothing is created yet.',
                McpHelper::amountsText(array_column($shown['totals_by_currency'], 'amount', 'currency'))
                    . (McpHelper::currencyNotice(array_column($shown['totals_by_currency'], 'amount', 'currency'), $shown['currency']) ? ' (not converted, see meta.warnings)' : ''),
                $shown['affiliates_count'],
                $shown['referrals_count']
            ));
        }

        if (!McpHelper::confirmMatches($params['confirm'], $action, 'payout', $fingerprint)) {
            return McpHelper::confirmStale($action, $shown, $confirm);
        }

        $used = McpHelper::useConfirm($action, $params['confirm']);
        if (is_wp_error($used)) {
            return $used;
        }

        // the claim takes only these referrals, and only at these amounts
        $expected = [];
        foreach ($referrals as $rows) {
            foreach ($rows as $row) {
                $expected[$row['id']] = $row['amount'];
            }
        }
        $referralIds = array_keys($expected);

        $result = PayoutService::create($config, $referralIds, $expected);
        if (is_wp_error($result)) {
            return McpHelper::releaseConfirm($action, $params['confirm'], self::serviceError($result));
        }

        $payout = Payout::query()->find($result['payout']->id);
        $counts = self::transactionCounts([$payout->id]);

        // another payout can claim some of the previewed referrals between the confirm and the claim
        $paidCount = (int) Referral::query()->where('payout_id', $payout->id)->count();
        if ($paidCount < count($referralIds)) {
            McpHelper::warn(sprintf(
                'The payout holds %d of the %d previewed referrals; the others changed after the preview (another payout took them, or their amount changed) and stay out of it. get-payout shows what it holds.',
                $paidCount,
                count($referralIds)
            ));
        }
        $data = Formatter::payout($payout, Arr::get($counts, $payout->id, []));

        $data['next'] = $result['is_gateway']
            ? 'The payout is created and no money has moved. A person sends it from the payout page in the FluentAffiliate admin.'
            : 'The referrals are marked paid. Pay each affiliate outside the site (get-payout lists who and where), then record each payment with update-transaction.';

        return McpHelper::envelope(
            sprintf('Created payout %d: %s %s to %d affiliate(s).', $payout->id, number_format($data['total_amount'], 2, '.', ''), $data['currency'], (int) Arr::get($data, 'transactions', 0)),
            $data
        );
    }

    /**
     * Mark one transaction paid or processing, or every processing transaction
     * of a manual payout paid, in two calls.
     */
    public static function setTransactionStatus($params)
    {
        $action = 'update-transaction';

        $payout = Payout::query()->find($params['payout_id']);
        if (!$payout) {
            return McpHelper::notFound('Payout ' . $params['payout_id'], 'payout_id');
        }

        if (!isset($params['transaction_id'])) {
            // settling a whole payout is never what a forgotten id means
            if (empty($params['all_processing'])) {
                return McpHelper::invalid(['transaction_id', 'all_processing'], 'Send transaction_id for one transaction, or all_processing=true with status=paid for every processing transaction of the payout.');
            }

            return self::markAllPaid($payout, $params);
        }

        if (!empty($params['all_processing'])) {
            return McpHelper::invalid(['transaction_id', 'all_processing'], 'Send either transaction_id or all_processing=true, not both.');
        }

        $transaction = Transaction::query()->with(['affiliate' => function ($q) {
            $q->select(['id', 'user_id', 'payment_email'])->with(['user' => function ($userQuery) {
                $userQuery->select(['ID', 'display_name']);
            }]);
        }])->where('payout_id', $payout->id)->find($params['transaction_id']);

        if (!$transaction) {
            return McpHelper::notFound('Transaction ' . $params['transaction_id'] . ' in payout ' . $payout->id, 'transaction_id');
        }

        $newStatus = $params['status'];

        if ($transaction->status === $newStatus) {
            return McpHelper::envelope('Transaction ' . $transaction->id . ' is already ' . $newStatus . '. Nothing changed.', Formatter::transaction($transaction));
        }

        if (!$transaction->isManual() && ($newStatus !== 'paid' || $transaction->status !== 'failed')) {
            return McpHelper::error('not_allowed', 'Transaction ' . $transaction->id . ' is paid through a payout gateway. It can be marked paid by hand only after the gateway reports it failed.', ['fields' => ['transaction_id']]);
        }

        if (!$transaction->isManual() && $payout->status === 'sending') {
            return McpHelper::error('not_allowed', 'The payout gateway is sending payout ' . $payout->id . '. Try again after the send ends.', ['fields' => ['payout_id'], 'retryable' => true]);
        }

        $row = Formatter::transaction($transaction);
        $amount = number_format($row['amount'], 2, '.', '') . ' ' . $row['currency'];
        $name = $row['affiliate'] ? $row['affiliate']['name'] : 'a deleted affiliate';

        $preview = [
            'transaction' => $row,
            'from_status' => (string) $transaction->status,
            'to_status'   => $newStatus,
            'effect'      => $newStatus === 'paid'
                ? 'Records that ' . $amount . ' was paid to ' . $name . '. Its referrals are marked paid if they are not yet. No money is sent by this call.'
                : 'Records that ' . $amount . ' to ' . $name . ' is not paid yet. The referrals stay paid.',
        ];

        $fingerprint = McpHelper::fingerprint([$row['status'], $row['amount'], $row['currency'], Arr::get($row, 'payment_email'), $newStatus, $payout->status]);
        $confirm = McpHelper::confirmValue($action, 'tx:' . $transaction->id, $fingerprint);

        if (!array_key_exists('confirm', $params)) {
            return McpHelper::confirmationNeeded($action, $preview, $confirm, 'Preview: transaction ' . $transaction->id . ' (' . $amount . ', ' . $name . ') to ' . $newStatus . '. Nothing is changed yet.');
        }

        if (!McpHelper::confirmMatches($params['confirm'], $action, 'tx:' . $transaction->id, $fingerprint)) {
            return McpHelper::confirmStale($action, $preview, $confirm);
        }

        $used = McpHelper::useConfirm($action, $params['confirm']);
        if (is_wp_error($used)) {
            return $used;
        }

        $result = PayoutService::setTransactionStatus($payout, $transaction, $newStatus);
        if (is_wp_error($result)) {
            return McpHelper::releaseConfirm($action, $params['confirm'], self::serviceError($result));
        }

        $transaction = Transaction::query()->with(['affiliate' => function ($q) {
            $q->select(['id', 'user_id', 'payment_email'])->with(['user' => function ($userQuery) {
                $userQuery->select(['ID', 'display_name']);
            }]);
        }])->find($transaction->id);

        $left = (int) Arr::get($result, 'processing_count', 0);

        return McpHelper::envelope(
            sprintf('Transaction %d is %s. %d transaction(s) of payout %d still processing.', $transaction->id, $transaction->status, $left, $payout->id),
            Formatter::transaction($transaction)
        );
    }

    private static function markAllPaid(Payout $payout, $params)
    {
        $action = 'update-transaction';

        if ($params['status'] !== 'paid') {
            return McpHelper::invalid('transaction_id', 'transaction_id is required with status=processing. Only status=paid works on every transaction of a payout at once.');
        }

        if (Arr::get((array) $payout->settings, 'gateway')) {
            return McpHelper::error('not_allowed', 'Payout ' . $payout->id . ' is paid through a payout gateway, so its transactions cannot be marked paid in bulk.', ['fields' => ['payout_id']]);
        }

        $transactions = Transaction::query()
            ->with(['affiliate' => function ($q) {
                $q->select(['id', 'user_id', 'payment_email'])->with(['user' => function ($userQuery) {
                    $userQuery->select(['ID', 'display_name']);
                }]);
            }])
            ->where('payout_id', $payout->id)
            ->where('status', 'processing')
            ->orderBy('id', 'ASC')
            ->get();

        if ($transactions->isEmpty()) {
            return McpHelper::envelope('Payout ' . $payout->id . ' has no processing transactions. Nothing changed.', ['payout_id' => (int) $payout->id, 'processing' => 0]);
        }

        $rows = [];
        $sum = 0;
        $fingerprintRows = [];
        foreach ($transactions as $transaction) {
            $row = Formatter::transaction($transaction);
            $sum += $row['amount'];
            $fingerprintRows[] = [$row['id'], $row['amount'], $row['currency'], Arr::get($row, 'payment_email')];
            if (count($rows) < self::PREVIEW_ROWS) {
                $rows[] = $row;
            }
        }

        $currency = $rows[0]['currency'];
        $preview = [
            'payout_id'          => (int) $payout->id,
            'transactions_count' => count($fingerprintRows),
            'total_amount'       => McpHelper::money($sum),
            'currency'           => $currency,
            'transactions'       => $rows,
            'effect'             => 'Records that every affiliate listed was paid. No money is sent by this call.',
        ];

        if (count($fingerprintRows) > self::PREVIEW_ROWS) {
            McpHelper::warn(sprintf('The preview lists the first %d of %d transactions; confirm covers all of them.', self::PREVIEW_ROWS, count($fingerprintRows)));
        }

        $key = 'all:' . $payout->id;
        $fingerprint = McpHelper::fingerprint($fingerprintRows);
        $confirm = McpHelper::confirmValue($action, $key, $fingerprint);

        if (!array_key_exists('confirm', $params)) {
            return McpHelper::confirmationNeeded($action, $preview, $confirm, sprintf('Preview: mark %d transaction(s) of payout %d paid, %s %s in all. Nothing is changed yet.', count($fingerprintRows), $payout->id, number_format($sum, 2, '.', ''), $currency));
        }

        if (!McpHelper::confirmMatches($params['confirm'], $action, $key, $fingerprint)) {
            return McpHelper::confirmStale($action, $preview, $confirm);
        }

        $used = McpHelper::useConfirm($action, $params['confirm']);
        if (is_wp_error($used)) {
            return $used;
        }

        $result = PayoutService::markAllPaid($payout);
        if (is_wp_error($result)) {
            return McpHelper::releaseConfirm($action, $params['confirm'], self::serviceError($result));
        }

        $left = (int) Arr::get($result, 'processing_count', 0);
        if ($left > 0) {
            McpHelper::warn(sprintf('The site stopped after 30 seconds: %d transaction(s) are still processing. Call update-transaction again for the rest.', $left));
        }

        return McpHelper::envelope(
            sprintf('Marked %d transaction(s) of payout %d paid. %d still processing.', count((array) Arr::get($result, 'marked', [])), $payout->id, $left),
            [
                'payout_id'   => (int) $payout->id,
                'marked_paid' => array_values((array) Arr::get($result, 'marked', [])),
                'processing'  => $left,
            ]
        );
    }

    /**
     * Delete one unpaid transaction and release its referrals, in two calls.
     */
    public static function removeTransaction($params)
    {
        $action = 'remove-transaction';

        $payout = Payout::query()->find($params['payout_id']);
        if (!$payout) {
            return McpHelper::notFound('Payout ' . $params['payout_id'], 'payout_id');
        }

        $transaction = Transaction::query()->with(['affiliate' => function ($q) {
            $q->select(['id', 'user_id', 'payment_email'])->with(['user' => function ($userQuery) {
                $userQuery->select(['ID', 'display_name']);
            }]);
        }])->where('payout_id', $payout->id)->find($params['transaction_id']);

        if (!$transaction) {
            return McpHelper::notFound('Transaction ' . $params['transaction_id'] . ' in payout ' . $payout->id, 'transaction_id');
        }

        if ($transaction->status === 'paid') {
            return McpHelper::error('not_allowed', 'Transaction ' . $transaction->id . ' is paid, so it cannot be removed. If it was not really paid, set it to processing first with update-transaction.', ['fields' => ['transaction_id']]);
        }

        if ($transaction->isLockedByGateway($payout)) {
            return McpHelper::error('not_allowed', 'The payout gateway may still pay transaction ' . $transaction->id . '. It can be removed only after the gateway reports it failed.', ['fields' => ['transaction_id']]);
        }

        $referrals = Referral::query()->where('payout_transaction_id', $transaction->id);
        $referralCount = (int) (clone $referrals)->count();
        $referralAmount = McpHelper::money((clone $referrals)->sum('amount'));

        $row = Formatter::transaction($transaction);
        $preview = [
            'transaction'       => $row,
            'referrals_count'   => $referralCount,
            'referrals_amount'  => $referralAmount,
            'currency'          => $row['currency'],
            'payout_total_after' => McpHelper::money((float) $payout->total_amount - (float) $transaction->total_amount),
            'effect'            => sprintf('Deletes the transaction. Its %d referral(s), %s %s, become unpaid and go into a later payout. This cannot be undone.', $referralCount, number_format($referralAmount, 2, '.', ''), $row['currency']),
        ];

        $key = 'rm:' . $transaction->id;
        $fingerprint = McpHelper::fingerprint([$row['status'], $row['amount'], $row['currency'], $referralCount, $referralAmount, $payout->status]);
        $confirm = McpHelper::confirmValue($action, $key, $fingerprint);

        if (!array_key_exists('confirm', $params)) {
            return McpHelper::confirmationNeeded($action, $preview, $confirm, 'Preview: remove transaction ' . $transaction->id . ' from payout ' . $payout->id . '. Nothing is changed yet.');
        }

        if (!McpHelper::confirmMatches($params['confirm'], $action, $key, $fingerprint)) {
            return McpHelper::confirmStale($action, $preview, $confirm);
        }

        $used = McpHelper::useConfirm($action, $params['confirm']);
        if (is_wp_error($used)) {
            return $used;
        }

        $payout = PayoutService::removeTransaction($payout, $transaction);
        if (is_wp_error($payout)) {
            return McpHelper::releaseConfirm($action, $params['confirm'], self::serviceError($payout));
        }

        return McpHelper::envelope(
            sprintf('Removed transaction %d. %d referral(s) are unpaid again. Payout %d total is now %s %s.', $transaction->id, $referralCount, $payout->id, number_format(McpHelper::money($payout->total_amount), 2, '.', ''), $row['currency']),
            Formatter::payout($payout, Arr::get(self::transactionCounts([$payout->id]), $payout->id, []))
        );
    }

    /**
     * The service config from the tool's parameters, checked.
     *
     * @return array|\WP_Error
     */
    private static function payoutConfig($params)
    {
        $config = [
            'title'                 => sanitize_text_field($params['title']),
            'description'           => sanitize_textarea_field($params['description']),
            'affiliate_ids'         => [],
            'min_payout'            => isset($params['min_payout']) ? (float) $params['min_payout'] : 0,
            'ignore_holding_period' => !empty($params['ignore_holding_period']) ? 'yes' : 'no',
            'payout_method'         => isset($params['payout_method']) ? $params['payout_method'] : 'manual',
        ];

        if (mb_strlen($config['title']) > 255) {
            return McpHelper::invalid('title', 'title is at most 255 characters.');
        }

        foreach (['start_date', 'end_date'] as $field) {
            if (isset($params[$field])) {
                $day = McpHelper::day($params[$field], $field);
                if (is_wp_error($day)) {
                    return $day;
                }
                $config[$field] = $day;
            }
        }

        if (!empty($config['start_date']) && !empty($config['end_date']) && $config['start_date'] > $config['end_date']) {
            return McpHelper::invalid(['start_date', 'end_date'], 'start_date is after end_date. Send the earlier day as start_date.');
        }

        if (!empty($params['affiliate_ids'])) {
            $ids = array_values(array_unique(array_map('intval', $params['affiliate_ids'])));
            $found = Affiliate::query()->whereIn('id', $ids)->pluck('id')->toArray();
            $missing = array_values(array_diff($ids, array_map('intval', $found)));
            if ($missing) {
                return McpHelper::invalid('affiliate_ids', 'These affiliates do not exist: ' . implode(', ', $missing) . '.');
            }
            $config['affiliate_ids'] = $ids;
        }

        return $config;
    }

    /**
     * @return array|\WP_Error [the preview to show, the referrals by affiliate]
     */
    private static function buildPreview($config)
    {
        $validated = PayoutService::preview($config);
        if (is_wp_error($validated)) {
            return self::serviceError($validated);
        }

        $referrals = PayoutService::payableReferrals($validated);
        $currency = McpHelper::currency();
        $method = Arr::get($validated, 'config.payout_method', 'manual');
        $isGateway = Helper::isGatewayPayoutMethod($method);

        $affiliates = [];
        $recipients = [];
        $total = 0;
        $count = 0;
        $byCurrency = [];
        foreach (Arr::get($validated, 'payable_affiliates', []) as $affiliate) {
            $rows = Arr::get($referrals, (int) $affiliate->id, []);
            if (!$rows) {
                continue;
            }

            $amount = 0;
            $affiliateByCurrency = [];
            foreach ($rows as $row) {
                $amount += $row['amount'];
                $rowCurrency = $row['currency'] !== '' ? $row['currency'] : $currency;
                $byCurrency[$rowCurrency] = (isset($byCurrency[$rowCurrency]) ? $byCurrency[$rowCurrency] : 0) + $row['amount'];
                $affiliateByCurrency[$rowCurrency] = (isset($affiliateByCurrency[$rowCurrency]) ? $affiliateByCurrency[$rowCurrency] : 0) + $row['amount'];
            }

            $total += $amount;
            $count += count($rows);
            $recipients[(int) $affiliate->id] = (string) $affiliate->payment_email;

            if (count($affiliates) < self::PREVIEW_ROWS) {
                $affiliates[] = [
                    'affiliate_id'  => (int) $affiliate->id,
                    'name'          => $affiliate->user ? (string) $affiliate->user->display_name : '',
                    'status'        => (string) $affiliate->status,
                    'payment_email' => $affiliate->payment_email ? (string) $affiliate->payment_email : null,
                    'referrals'     => count($rows),
                    // each amount beside the currency it was earned in
                    'amounts'       => self::amountRows($affiliateByCurrency),
                ];
            }
        }

        if (count($referrals) > self::PREVIEW_ROWS) {
            McpHelper::warn(sprintf('The preview lists the first %d of %d affiliates; confirm covers all of them.', self::PREVIEW_ROWS, count($referrals)));
        }

        // the payout adds referral amounts as they are and labels each transaction
        // with the site currency, whatever currency a referral was earned in
        // the notice runs on the confirm call too, because it builds the preview again
        $notice = McpHelper::currencyNotice($byCurrency, $currency);
        if ($notice) {
            McpHelper::warn($notice);
        }

        $held = (array) Arr::get($validated, 'held', []);

        $shown = [
            'title'            => $config['title'],
            'payout_method'    => $method,
            'currency'         => $currency,
            'referrals_from'   => strpos((string) Arr::get($validated, 'config.start_date'), '1970-01-01') === 0 ? null : McpHelper::iso(Arr::get($validated, 'config.start_date')),
            'referrals_to'     => McpHelper::iso(Arr::get($validated, 'config.end_date')),
            // one total only when every amount is in the currency the payout records
            'total_amount'     => array_keys($byCurrency) === [$currency] ? McpHelper::money($total) : null,
            'totals_by_currency' => self::amountRows($byCurrency),
            // what each transaction will record, whatever the referrals' own currencies
            'records_in_currency' => $currency,
            'affiliates_count' => count($referrals),
            'referrals_count'  => $count,
            'affiliates'       => $affiliates,
            'held_back'        => [
                'referrals' => (int) Arr::get($held, 'count', 0),
                'amount'    => McpHelper::money(Arr::get($held, 'total_amount', 0)),
                'currency'  => $currency,
                'why'       => 'Still in the holding period; ignore_holding_period=true pays them too.',
            ],
            'effect'           => $isGateway
                ? 'Creates the payout with one transaction per affiliate. The referrals stay processing until the gateway pays them; no money moves until a person sends the payout.'
                : 'Creates the payout with one transaction per affiliate and marks these referrals paid at once. You then pay each affiliate outside the site and record it with update-transaction.',
        ];

        $holdingDays = (int) Utility::getReferralSetting('holding_days', 0);
        $shown['held_back']['holding_days'] = $holdingDays;
        if ($holdingDays <= 0) {
            $shown['held_back']['why'] = 'The site has no holding period: every unpaid referral is payable.';
        } elseif (Arr::get($config, 'ignore_holding_period') === 'yes') {
            $shown['held_back']['why'] = 'ignore_holding_period=true: referrals in the holding period are paid too.';
        }

        ksort($recipients);

        return [$shown, $referrals, $recipients];
    }

    /**
     * Amounts per currency as a list of {amount, currency}.
     *
     * @param array<string, float> $byCurrency
     * @return array
     */
    public static function amountRows($byCurrency)
    {
        $rows = [];
        foreach ($byCurrency as $code => $sum) {
            $rows[] = ['amount' => McpHelper::money($sum), 'currency' => (string) $code];
        }

        return $rows;
    }

    /**
     * transactions, transactions_to_settle, transactions_failed and currency per payout id.
     *
     * @param int[] $payoutIds
     * @return array
     */
    private static function transactionCounts($payoutIds)
    {
        $payoutIds = array_values(array_filter(array_map('intval', (array) $payoutIds)));
        if (!$payoutIds) {
            return [];
        }

        $rows = Transaction::query()
            ->select(['payout_id', 'status', 'currency'])
            ->selectRaw('COUNT(*) as aggregate_count')
            ->whereIn('payout_id', $payoutIds)
            ->groupBy('payout_id', 'status', 'currency')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $id = (int) $row->payout_id;
            if (!isset($counts[$id])) {
                $counts[$id] = ['transactions' => 0, 'transactions_to_settle' => 0, 'transactions_failed' => 0, 'currency' => $row->currency];
            }
            $number = (int) $row->aggregate_count;
            $counts[$id]['transactions'] += $number;
            if ($row->status === 'processing') {
                $counts[$id]['transactions_to_settle'] += $number;
            } elseif ($row->status === 'failed') {
                $counts[$id]['transactions_failed'] += $number;
            }
        }

        return $counts;
    }

    /**
     * A refusal from PayoutService, in plain English.
     */
    private static function serviceError(\WP_Error $error)
    {
        $messages = [
            'date_range_reversed' => ['invalid_param', 'start_date is after end_date.', ['start_date', 'end_date']],
            'all_held'            => ['not_allowed', 'Every unpaid referral in this range is still in the holding period. Pass ignore_holding_period=true to pay them anyway, or choose an earlier end_date.', ['end_date']],
            'nothing_payable'     => ['not_allowed', 'No affiliate has unpaid referrals that match. Nothing was created.', []],
            'already_claimed'     => ['conflict', 'The referrals were taken by another payout meanwhile. Nothing was created. Call create-payout without confirm to see what is left.', []],
            'refused_by_filter'   => ['not_allowed', 'A plugin on the site refused this payout. Nothing was created. site_message holds its reason as the site wrote it.', []],
            'gateway_owned'       => ['not_allowed', 'This transaction is paid through a payout gateway. It can be marked paid by hand only after the gateway reports it failed.', ['transaction_id']],
            'changed_meanwhile'   => ['conflict', 'The transaction changed meanwhile. Nothing was changed. Read it again with get-payout.', ['transaction_id']],
            'gateway_sending'     => ['not_allowed', 'The payout gateway is sending this payout. Try again after the send ends.', ['payout_id']],
            'transaction_paid'    => ['not_allowed', 'The transaction is paid, so it cannot be removed.', ['transaction_id']],
            'paid_meanwhile'      => ['conflict', 'The transaction was paid meanwhile, so it was not removed.', ['transaction_id']],
            'gateway_locked'      => ['not_allowed', 'The payout gateway may still pay this transaction. It can be removed only after the gateway reports it failed.', ['transaction_id']],
        ];

        $code = $error->get_error_code();
        if (isset($messages[$code])) {
            $details = ['fields' => $messages[$code][2], 'retryable' => $code === 'gateway_sending'];
            // a site filter's text can be translated or long; it rides beside the fixed sentence
            if ($code === 'refused_by_filter') {
                $details['site_message'] = mb_substr(wp_strip_all_tags($error->get_error_message()), 0, 500);
            }

            return McpHelper::error($messages[$code][0], $messages[$code][1], $details);
        }

        return McpHelper::error('not_allowed', 'The site refused the change. Nothing more is known.');
    }
}
