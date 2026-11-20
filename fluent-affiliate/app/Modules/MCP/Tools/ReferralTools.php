<?php

namespace FluentAffiliate\App\Modules\MCP\Tools;

use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Modules\MCP\Formatter;
use FluentAffiliate\App\Modules\MCP\McpHelper;
use FluentAffiliate\App\Services\PermissionManager;
use FluentAffiliate\App\Services\ReferralService;
use FluentAffiliate\Framework\Support\Arr;

class ReferralTools
{
    const ACTION = 'save-referral';

    public static function listReferrals($params)
    {
        $range = McpHelper::dateRange($params, 'created_from', 'created_to');
        if (is_wp_error($range)) {
            return $range;
        }

        $paging = McpHelper::pagination($params);

        $query = Referral::query()->with(['affiliate' => Affiliate::identityOnly()]);

        foreach (['affiliate_id', 'status', 'type', 'provider', 'payout_id'] as $filter) {
            if (isset($params[$filter]) && $params[$filter] !== '') {
                $query->where($filter, $params[$filter]);
            }
        }

        if ($range[0]) {
            $query->where('created_at', '>=', $range[0]);
        }
        if ($range[1]) {
            $query->where('created_at', '<=', $range[1]);
        }

        $search = isset($params['search']) ? trim((string) $params['search']) : '';
        if ($search !== '') {
            $like = McpHelper::likeAnywhere($search);
            $query->where(function ($q) use ($like, $search) {
                $q->where('description', 'LIKE', $like);
                if (ctype_digit($search)) {
                    $q->orWhere('provider_id', (int) $search)->orWhere('id', (int) $search);
                }
            });
        }

        $sortBy = in_array(Arr::get($params, 'sort_by'), ['created_at', 'amount', 'id'], true) ? $params['sort_by'] : 'created_at';
        $sortDir = Arr::get($params, 'sort_dir') === 'ASC' ? 'ASC' : 'DESC';

        $total = (clone $query)->count();
        $referrals = $query->orderBy($sortBy, $sortDir)
            ->orderBy('id', $sortDir)
            ->offset(($paging['page'] - 1) * $paging['per_page'])
            ->limit($paging['per_page'])
            ->get();

        $rows = [];
        foreach ($referrals as $referral) {
            $rows[] = Formatter::referral($referral);
        }

        return McpHelper::envelope(
            sprintf('%d referral(s) match; showing %d.', $total, count($rows)),
            $rows,
            McpHelper::pagingMeta($total, $paging)
        );
    }

    public static function getReferral($params)
    {
        // the visit and the payout belong to their own permissions, as on the admin screen
        $relations = ['customer', 'affiliate' => Affiliate::identityOnly()];
        $withVisit = PermissionManager::hasVisitAccess();
        $withPayout = PermissionManager::hasPayoutAccess(true);

        if ($withVisit) {
            $relations[] = 'visit';
        }
        if ($withPayout) {
            $relations[] = 'payout';
        }

        $referral = Referral::query()->with($relations)->find($params['id']);
        if (!$referral) {
            return McpHelper::notFound('Referral ' . $params['id']);
        }

        $data = Formatter::referral($referral);

        $customer = $referral->customer;
        $data['customer'] = $customer ? [
            'id'    => (int) $customer->id,
            'name'  => trim($customer->first_name . ' ' . $customer->last_name),
            'email' => (string) $customer->email,
        ] : null;

        $products = $referral->products;
        $data['products'] = is_array($products) ? array_values(array_map(function ($product) {
            return is_array($product) ? array_intersect_key($product, array_flip(['title', 'name', 'item_id', 'product_id', 'quantity', 'price', 'subtotal', 'line_total', 'amount', 'commission'])) : $product;
        }, $products)) : [];

        $orderUrl = $referral->getProviderReferenceUrl();
        $data['order_url'] = $orderUrl ? (string) $orderUrl : null;
        $data['can_edit'] = ReferralService::editError($referral) === null;

        if ($withVisit) {
            $data['visit'] = $referral->visit ? Formatter::visit($referral->visit) : null;
        }

        if ($withPayout && $referral->payout) {
            $data['payout'] = [
                'id'     => (int) $referral->payout->id,
                'title'  => (string) $referral->payout->title,
                'status' => (string) $referral->payout->status,
            ];
        }

        return McpHelper::envelope(
            sprintf('Referral %d: %s %s, %s.', $referral->id, number_format($data['amount'], 2, '.', ''), $data['currency'], $referral->status),
            $data
        );
    }

    /**
     * Create or change a manual referral, in two calls: the first returns the
     * preview and a confirm value, the second applies it.
     */
    public static function saveReferral($params)
    {
        if (isset($params['id'])) {
            return self::updateReferral($params);
        }

        foreach (['affiliate_id', 'amount', 'status'] as $required) {
            if (!isset($params[$required])) {
                return McpHelper::invalid($required, $required . ' is required to create a referral. To change one, pass its id.');
            }
        }

        $affiliate = Affiliate::query()->with(['user'])->find($params['affiliate_id']);
        if (!$affiliate) {
            return McpHelper::notFound('Affiliate ' . $params['affiliate_id'], 'affiliate_id');
        }

        if ($affiliate->status !== 'active') {
            return McpHelper::error('not_allowed', 'Affiliate ' . $affiliate->id . ' is ' . $affiliate->status . '. Only an active affiliate can get a referral; update-affiliate with status=active approves it.', ['fields' => ['affiliate_id']]);
        }

        $values = [
            'amount'      => McpHelper::money($params['amount']),
            'status'      => $params['status'],
            'type'        => isset($params['type']) ? $params['type'] : 'sale',
            'description' => isset($params['description']) ? sanitize_text_field($params['description']) : '',
        ];

        $preview = [
            'action'    => 'create',
            'affiliate' => Formatter::affiliateRef($affiliate),
            'referral'  => array_merge($values, ['currency' => McpHelper::currency()]),
            'effect'    => self::effect(null, $values),
        ];

        $key = 'new:' . $affiliate->id;
        $fingerprint = McpHelper::fingerprint([$preview, $affiliate->status]);
        $confirm = McpHelper::confirmValue(self::ACTION, $key, $fingerprint);

        if (!array_key_exists('confirm', $params)) {
            return McpHelper::confirmationNeeded(self::ACTION, $preview, $confirm, 'Preview: a manual referral of ' . number_format($values['amount'], 2, '.', '') . ' ' . McpHelper::currency() . ' for affiliate ' . $affiliate->id . '. Nothing is saved yet.');
        }

        if (!McpHelper::confirmMatches($params['confirm'], self::ACTION, $key, $fingerprint)) {
            return McpHelper::confirmStale(self::ACTION, $preview, $confirm);
        }

        // a resent confirmed call would create a second commission
        $used = McpHelper::useConfirm(self::ACTION, $params['confirm']);
        if (is_wp_error($used)) {
            return $used;
        }

        $referral = ReferralService::create($affiliate, [
            'description' => $values['description'],
            'amount'      => $values['amount'],
            'status'      => $values['status'],
            'type'        => $values['type'],
            'provider'    => 'manual',
            'provider_id' => null,
        ]);

        if (is_wp_error($referral)) {
            return McpHelper::releaseConfirm(self::ACTION, $params['confirm'], McpHelper::error('not_allowed', 'The referral was not created: the affiliate is no longer active.', ['fields' => ['affiliate_id']]));
        }

        $referral = Referral::query()->with(['affiliate' => Affiliate::identityOnly()])->find($referral->id);

        return McpHelper::envelope(
            sprintf('Created referral %d: %s %s, %s, for affiliate %d.', $referral->id, number_format($values['amount'], 2, '.', ''), McpHelper::currency($referral->currency), $referral->status, $affiliate->id),
            Formatter::referral($referral)
        );
    }

    private static function updateReferral($params)
    {
        if (isset($params['affiliate_id'])) {
            return McpHelper::invalid('affiliate_id', 'affiliate_id is only for a new referral. A referral cannot move to another affiliate; reject it and create a new one.');
        }

        $referral = Referral::query()->with(['affiliate' => Affiliate::identityOnly()])->find($params['id']);
        if (!$referral) {
            return McpHelper::notFound('Referral ' . $params['id']);
        }

        if ($error = ReferralService::editError($referral)) {
            $message = $error->get_error_code() === 'referral_paid'
                ? 'Referral ' . $referral->id . ' is paid, so it cannot be changed.'
                : 'Referral ' . $referral->id . ' is in a payout a gateway is paying. It can be changed after the payout finishes.';

            return McpHelper::error('not_allowed', $message, ['fields' => ['id']]);
        }

        if ($referral->status === 'processing') {
            return McpHelper::error('not_allowed', 'Referral ' . $referral->id . ' is processing. It can be changed after its payout finishes.', ['fields' => ['id']]);
        }

        $allowedTypes = ReferralService::allowedTypes($referral);
        if (isset($params['type']) && !in_array($params['type'], $allowedTypes, true)) {
            return McpHelper::invalid('type', 'This referral was written by ' . ($referral->provider ?: 'an integration') . ' and keeps its type ' . $referral->type . '.', ['allowed' => $allowedTypes]);
        }

        $before = [
            'amount'      => McpHelper::money($referral->amount),
            'status'      => (string) $referral->status,
            'type'        => (string) $referral->type,
            'description' => (string) $referral->description,
        ];

        $after = [
            'amount'      => isset($params['amount']) ? McpHelper::money($params['amount']) : $before['amount'],
            'status'      => isset($params['status']) ? $params['status'] : $before['status'],
            'type'        => isset($params['type']) ? $params['type'] : $before['type'],
            'description' => isset($params['description']) ? sanitize_text_field($params['description']) : $before['description'],
        ];

        if (!in_array($after['status'], ['pending', 'unpaid', 'rejected'], true)) {
            return McpHelper::invalid('status', 'Send status: a referral can be set to pending, unpaid or rejected.', ['allowed' => ['pending', 'unpaid', 'rejected']]);
        }

        if ($after === $before) {
            return McpHelper::invalid(['amount', 'status', 'type', 'description'], 'Nothing would change: pass a new amount, status, type or description.');
        }

        $currency = McpHelper::currency($referral->currency);
        $preview = [
            'action'   => 'update',
            'referral' => ['id' => (int) $referral->id, 'affiliate' => Formatter::affiliateRef($referral->affiliate), 'currency' => $currency],
            'before'   => $before,
            'after'    => $after,
            'effect'   => self::effect($before, $after, $referral->currency),
        ];

        $fingerprint = McpHelper::fingerprint([$preview, $referral->payout_transaction_id]);
        $confirm = McpHelper::confirmValue(self::ACTION, $referral->id, $fingerprint);

        if (!array_key_exists('confirm', $params)) {
            return McpHelper::confirmationNeeded(self::ACTION, $preview, $confirm, 'Preview of the change to referral ' . $referral->id . '. Nothing is saved yet.');
        }

        if (!McpHelper::confirmMatches($params['confirm'], self::ACTION, $referral->id, $fingerprint)) {
            return McpHelper::confirmStale(self::ACTION, $preview, $confirm);
        }

        $used = McpHelper::useConfirm(self::ACTION, $params['confirm']);
        if (is_wp_error($used)) {
            return $used;
        }

        $data = $after;
        // an unchanged amount keeps its stored precision
        if ($after['amount'] === $before['amount']) {
            unset($data['amount']);
        }

        $saved = self::saveIfUnchanged((int) $referral->id, $before['status'], $data);
        if (!$saved) {
            return McpHelper::releaseConfirm(self::ACTION, $params['confirm'], McpHelper::error('conflict', 'Referral ' . $referral->id . ' changed after the preview (a payout may have claimed it). Nothing was changed. Call save-referral without confirm to see it now.', ['fields' => ['id']]));
        }
        $referral = $saved;
        $referral = Referral::query()->with(['affiliate' => Affiliate::identityOnly()])->find($referral->id);

        return McpHelper::envelope(
            sprintf('Referral %d saved: %s %s, %s.', $referral->id, number_format($after['amount'], 2, '.', ''), $currency, $referral->status),
            Formatter::referral($referral)
        );
    }

    /**
     * Set the status of many referrals in two calls: one preview, one confirm.
     * Each row goes through the same service as the admin's referral edit, so
     * the same values are stored and the same recounts run.
     */
    public static function updateReferrals($params)
    {
        $action = 'update-referrals';
        $status = $params['status'];
        $ids = array_values(array_unique(array_map('intval', $params['ids'])));
        sort($ids);

        $referrals = Referral::query()
            ->with(['affiliate' => Affiliate::identityOnly()])
            ->whereIn('id', $ids)
            ->orderBy('id', 'ASC')
            ->get()
            ->keyBy('id');

        $missing = array_values(array_diff($ids, array_map('intval', $referrals->keys()->toArray())));
        if ($missing) {
            return McpHelper::error('not_found', 'These referrals do not exist: ' . implode(', ', $missing) . '. Nothing was changed.', ['fields' => ['ids'], 'ids' => $missing]);
        }

        // paid, in a payout, or claimed by one: the admin edit refuses these too
        $locked = [];
        foreach ($referrals as $referral) {
            if (ReferralService::editError($referral) || in_array($referral->status, ['paid', 'processing'], true) || $referral->payout_id || $referral->payout_transaction_id) {
                $locked[] = ['id' => (int) $referral->id, 'status' => (string) $referral->status, 'payout_id' => $referral->payout_id ? (int) $referral->payout_id : null];
            }
        }
        if ($locked) {
            return McpHelper::error('not_allowed', count($locked) . ' of the referrals are paid, processing or in a payout, so their status cannot change. Nothing was changed. Send the ids without them.', ['fields' => ['ids'], 'locked' => $locked]);
        }

        $change = [];
        $already = [];
        foreach ($referrals as $referral) {
            if ($referral->status === $status) {
                $already[] = (int) $referral->id;
            } else {
                $change[] = $referral;
            }
        }

        if ($already) {
            McpHelper::warn(count($already) . ' referral(s) are already ' . $status . ' and stay as they are: ' . implode(', ', $already) . '.');
        }

        if (!$change) {
            return McpHelper::envelope('Every referral is already ' . $status . '. Nothing to change.', ['changed' => false, 'count' => 0]);
        }

        $rows = [];
        $fingerprintRows = [];
        $stored = [];
        foreach ($change as $referral) {
            $currency = McpHelper::currency($referral->currency);
            // the stored amount, unrounded: the save keeps it, so the totals use it too
            $stored[] = ['amount' => (float) $referral->amount, 'currency' => $currency, 'status' => (string) $referral->status];
            $fingerprintRows[] = [(int) $referral->id, (string) $referral->status, (string) $referral->amount];

            if (count($rows) < PayoutTools::PREVIEW_ROWS) {
                $rows[] = [
                    'id'          => (int) $referral->id,
                    'affiliate'   => Formatter::affiliateRef($referral->affiliate),
                    'amount'      => McpHelper::money($referral->amount),
                    'currency'    => $currency,
                    'from_status' => (string) $referral->status,
                    'to_status'   => $status,
                ];
            }
        }

        if (count($change) > PayoutTools::PREVIEW_ROWS) {
            McpHelper::warn(sprintf('The preview lists the first %d of %d referrals; confirm covers all of them.', PayoutTools::PREVIEW_ROWS, count($change)));
        }

        list($totals, $owedDelta) = self::bulkTotals($stored, $status);

        $effects = [];
        foreach ($owedDelta as $currency => $delta) {
            if ($delta > 0) {
                $effects[] = 'Unpaid earnings rise by ' . number_format($delta, 2, '.', '') . ' ' . $currency . '; the next payout includes them.';
            } elseif ($delta < 0) {
                $effects[] = 'Unpaid earnings fall by ' . number_format(-$delta, 2, '.', '') . ' ' . $currency . '.';
            }
        }

        $preview = [
            'status'  => $status,
            'count'   => count($change),
            'totals'  => array_map(function ($currency, $amount) {
                return ['amount' => McpHelper::money($amount), 'currency' => $currency];
            }, array_keys($totals), array_values($totals)),
            'rows'    => $rows,
            'effect'  => $effects ? implode(' ', $effects) : 'Unpaid earnings do not change.',
        ];

        $key = 'bulk:' . $status . ':' . md5(implode(',', $ids));
        $fingerprint = McpHelper::fingerprint($fingerprintRows);
        $confirm = McpHelper::confirmValue($action, $key, $fingerprint);

        if (!array_key_exists('confirm', $params)) {
            return McpHelper::confirmationNeeded($action, $preview, $confirm, sprintf('Preview: set %d referral(s) to %s. Nothing is changed yet.', count($change), $status));
        }

        if (!McpHelper::confirmMatches($params['confirm'], $action, $key, $fingerprint)) {
            return McpHelper::confirmStale($action, $preview, $confirm);
        }

        $used = McpHelper::useConfirm($action, $params['confirm']);
        if (is_wp_error($used)) {
            return $used;
        }

        $changed = [];
        $skipped = [];
        foreach ($change as $referral) {
            // only the status moves; the stored amount keeps its precision
            $saved = self::saveIfUnchanged((int) $referral->id, (string) $referral->status, [
                'description' => $referral->description,
                'status'      => $status,
                'type'        => $referral->type,
            ]);

            if ($saved) {
                $changed[] = (int) $referral->id;
            } else {
                $skipped[] = (int) $referral->id;
            }
        }

        if ($skipped) {
            McpHelper::warn(count($skipped) . ' referral(s) changed after the preview, for example a payout claimed them, and were left as they are: ' . implode(', ', $skipped) . '.');
        }

        return McpHelper::envelope(
            sprintf('Set %d referral(s) to %s.', count($changed), $status),
            ['status' => $status, 'changed_ids' => $changed, 'skipped_ids' => $skipped]
        );
    }

    /**
     * Totals and the change in unpaid earnings for a status change, per
     * currency. The stored amounts are added at full precision and only the
     * results are rounded, so the preview states what the save will do.
     *
     * @param array  $rows   Each: amount (stored, unrounded), currency, status (current).
     * @param string $status The new status.
     * @return array{0: array<string, float>, 1: array<string, float>} [totals, unpaid-earnings change]
     */
    public static function bulkTotals($rows, $status)
    {
        $totals = [];
        $delta = [];

        foreach ($rows as $row) {
            $currency = $row['currency'];
            $amount = (float) $row['amount'];

            $totals[$currency] = (isset($totals[$currency]) ? $totals[$currency] : 0) + $amount;
            $delta[$currency] = (isset($delta[$currency]) ? $delta[$currency] : 0)
                + ($status === 'unpaid' ? $amount : 0) - ($row['status'] === 'unpaid' ? $amount : 0);
        }

        return [
            array_map([McpHelper::class, 'money'], $totals),
            array_map([McpHelper::class, 'money'], $delta),
        ];
    }

    /**
     * Save a referral edit on a locked row, and only while the row is as the
     * preview saw it: the status unchanged and not claimed by a payout. A payout
     * that claims the row meanwhile wins; the edit is not applied.
     *
     * @param int    $id
     * @param string $expectedStatus
     * @param array  $data For ReferralService::update().
     * @return Referral|null Null when the row changed.
     */
    private static function saveIfUnchanged($id, $expectedStatus, $data)
    {
        return Helper::dbTransaction(function () use ($id, $expectedStatus, $data) {
            $locked = Referral::query()->where('id', $id)->lockForUpdate()->first();

            if (!$locked || $locked->status !== $expectedStatus || $locked->payout_transaction_id || $locked->payout_id || ReferralService::editError($locked)) {
                return null;
            }

            return ReferralService::update($locked, $data);
        });
    }

    /**
     * What a change does to the affiliate's unpaid earnings, in one sentence.
     *
     * @param array|null  $before   Null for a new referral.
     * @param array       $after
     * @param string|null $currency The referral's own currency; the site currency when empty.
     * @return string
     */
    private static function effect($before, $after, $currency = null)
    {
        $owedBefore = $before && $before['status'] === 'unpaid' ? $before['amount'] : 0;
        $owedAfter = $after['status'] === 'unpaid' ? $after['amount'] : 0;
        $delta = round($owedAfter - $owedBefore, 2);
        $currency = McpHelper::currency($currency);

        if ($delta > 0) {
            return 'The affiliate\'s unpaid earnings rise by ' . number_format($delta, 2, '.', '') . ' ' . $currency . '; the next payout includes it.';
        }

        if ($delta < 0) {
            return 'The affiliate\'s unpaid earnings fall by ' . number_format(-$delta, 2, '.', '') . ' ' . $currency . '.';
        }

        return 'The affiliate\'s unpaid earnings do not change.';
    }
}
