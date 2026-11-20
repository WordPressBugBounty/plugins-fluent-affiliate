<?php

namespace FluentAffiliate\App\Modules\MCP\Tools;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\AffiliateGroup;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Models\User;
use FluentAffiliate\App\Modules\MCP\Formatter;
use FluentAffiliate\App\Modules\MCP\McpHelper;
use FluentAffiliate\App\Services\AffiliateService;
use FluentAffiliate\App\Services\PermissionManager;
use FluentAffiliate\Framework\Support\Arr;

class AffiliateTools
{
    const SORT_COLUMNS = ['id', 'created_at', 'unpaid_earnings', 'total_earnings', 'referrals', 'visits'];

    public static function listAffiliates($params)
    {
        $range = McpHelper::dateRange($params, 'created_from', 'created_to');
        if (is_wp_error($range)) {
            return $range;
        }

        $paging = McpHelper::pagination($params);

        $query = Affiliate::query()->with(['user', 'group']);

        if ($range[0]) {
            $query->where('created_at', '>=', $range[0]);
        }
        if ($range[1]) {
            $query->where('created_at', '<=', $range[1]);
        }

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (isset($params['group_id'])) {
            $query->where('group_id', $params['group_id']);
        }

        if (!empty($params['has_unpaid'])) {
            $query->where('unpaid_earnings', '>', 0);
        } elseif (isset($params['has_unpaid']) && $params['has_unpaid'] === false) {
            $query->where(function ($q) {
                $q->whereNull('unpaid_earnings')->orWhere('unpaid_earnings', '<=', 0);
            });
        }

        $search = isset($params['search']) ? trim((string) $params['search']) : '';
        if ($search !== '') {
            $like = McpHelper::likeAnywhere($search);
            $query->where(function ($q) use ($like, $search) {
                $q->whereHas('user', function ($userQuery) use ($like) {
                    $userQuery->where('display_name', 'LIKE', $like)
                        ->orWhere('user_email', 'LIKE', $like)
                        ->orWhere('user_login', 'LIKE', $like);
                })->orWhere('payment_email', 'LIKE', $like);

                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        $sortBy = in_array(Arr::get($params, 'sort_by'), self::SORT_COLUMNS, true) ? $params['sort_by'] : 'id';
        $sortDir = Arr::get($params, 'sort_dir') === 'ASC' ? 'ASC' : 'DESC';

        $total = (clone $query)->count();

        $affiliates = $query->orderBy($sortBy, $sortDir)
            ->orderBy('id', $sortDir)
            ->offset(($paging['page'] - 1) * $paging['per_page'])
            ->limit($paging['per_page'])
            ->get();

        $rows = [];
        foreach ($affiliates as $affiliate) {
            $rows[] = Formatter::affiliate($affiliate);
        }

        return McpHelper::envelope(
            sprintf('%d affiliate(s) match; showing %d.', $total, count($rows)),
            $rows,
            McpHelper::pagingMeta($total, $paging)
        );
    }

    public static function getAffiliate($params)
    {
        $affiliate = Affiliate::query()->with(['user', 'group'])->find($params['id']);

        if (!$affiliate) {
            return McpHelper::notFound('Affiliate ' . $params['id']);
        }

        $affiliate->recountEarnings();

        $data = Formatter::affiliate($affiliate);
        $data['referral_link'] = $affiliate->getShareUrl();
        $data['note'] = $affiliate->note !== null && $affiliate->note !== '' ? (string) $affiliate->note : null;

        $access = PermissionManager::hasAccess(true);

        if ($access['referral']) {
            $data['order_value'] = McpHelper::money(Referral::query()
                ->where('affiliate_id', $affiliate->id)
                ->whereIn('status', ['paid', 'unpaid', 'processing'])
                ->sum('order_total'));
        }

        if ($access['referral'] && $access['visit'] && $affiliate->visits > 0) {
            $data['conversion_rate_percent'] = round(($affiliate->referrals / $affiliate->visits) * 100, 2);
        }

        $include = (array) Arr::get($params, 'include', []);

        if (in_array('recent_referrals', $include, true)) {
            if ($access['referral']) {
                $data['recent_referrals'] = [];
                $referrals = Referral::query()->where('affiliate_id', $affiliate->id)->orderBy('id', 'DESC')->limit(10)->get();
                foreach ($referrals as $referral) {
                    $row = Formatter::referral($referral);
                    unset($row['affiliate']);
                    $data['recent_referrals'][] = $row;
                }
            } else {
                McpHelper::warn('recent_referrals was left out: it needs read access to referrals.');
            }
        }

        if (in_array('recent_transactions', $include, true)) {
            if ($access['payout']) {
                $data['recent_transactions'] = [];
                $transactions = Transaction::query()->where('affiliate_id', $affiliate->id)->orderBy('id', 'DESC')->limit(10)->get();
                foreach ($transactions as $transaction) {
                    $data['recent_transactions'][] = [
                        'id'         => (int) $transaction->id,
                        'payout_id'  => (int) $transaction->payout_id,
                        'amount'     => McpHelper::money($transaction->total_amount),
                        'currency'   => McpHelper::currency($transaction->currency),
                        'status'     => (string) $transaction->status,
                        'created_at' => McpHelper::iso($transaction->created_at),
                    ];
                }
            } else {
                McpHelper::warn('recent_transactions was left out: it needs read access to payouts.');
            }
        }

        return McpHelper::envelope(
            sprintf('%s, %s, %s %s unpaid.', $data['name'] ?: 'Affiliate ' . $affiliate->id, $affiliate->status, number_format($data['unpaid_earnings'], 2, '.', ''), $data['currency']),
            $data
        );
    }

    public static function createAffiliate($params)
    {
        $user = User::query()->find($params['user_id']);
        if (!$user) {
            return McpHelper::notFound('WordPress user ' . $params['user_id'], 'user_id');
        }

        if (Affiliate::query()->where('user_id', $user->ID)->exists()) {
            $existing = Affiliate::query()->where('user_id', $user->ID)->first();

            return McpHelper::error('conflict', 'This user is already affiliate ' . $existing->id . '. Use update-affiliate to change it.', ['fields' => ['user_id']]);
        }

        $terms = self::terms($params, null);
        if (is_wp_error($terms)) {
            return $terms;
        }

        $data = array_merge(['rate_type' => 'default'], $terms, ['status' => $params['status']]);

        $payoutMethod = Utility::getReferralSetting('payout_method', 'paypal');
        if ($payoutMethod === 'bank_transfer') {
            $bank = trim((string) Arr::get($params, 'bank_details', ''));
            if ($bank === '' || strlen($bank) > 5000) {
                return McpHelper::invalid('bank_details', 'bank_details is required: this site pays affiliates by bank transfer. At most 5000 characters.');
            }
            $data['settings'] = ['bank_details' => sanitize_textarea_field($bank)];
        } else {
            $email = trim((string) Arr::get($params, 'payment_email', ''));
            if ($email === '' || !is_email($email)) {
                return McpHelper::invalid('payment_email', 'payment_email is required and must be an email address: payouts are sent to it.');
            }
            $data['payment_email'] = sanitize_email($email);
        }

        $affiliate = AffiliateService::createForUser($user, $data);
        if (is_wp_error($affiliate)) {
            return McpHelper::error('conflict', 'This user is already an affiliate.', ['fields' => ['user_id']]);
        }

        $affiliate = Affiliate::query()->with(['user', 'group'])->find($affiliate->id);

        return McpHelper::envelope(
            sprintf('Created affiliate %d for user %d, status %s.', $affiliate->id, $user->ID, $affiliate->status),
            Formatter::affiliate($affiliate)
        );
    }

    public static function updateAffiliate($params)
    {
        foreach (['payment_email', 'bank_details'] as $refused) {
            if (array_key_exists($refused, $params)) {
                return McpHelper::error('not_allowed', $refused . ' cannot be changed here: where an affiliate is paid is a takeover target, so it changes only in the FluentAffiliate admin screen, by a person. Nothing was changed.', ['fields' => [$refused]]);
            }
        }

        $affiliate = Affiliate::query()->with(['user', 'group'])->find($params['id']);
        if (!$affiliate) {
            return McpHelper::notFound('Affiliate ' . $params['id']);
        }

        $changes = array_intersect_key($params, array_flip(['status', 'rate_type', 'rate', 'group_id', 'note']));
        if (!$changes) {
            return McpHelper::invalid(['status', 'rate_type', 'rate', 'group_id', 'note'], 'Pass at least one of status, rate_type, rate, group_id or note.');
        }

        $terms = self::terms($params, $affiliate);
        if (is_wp_error($terms)) {
            return $terms;
        }

        if (isset($params['status']) && $params['status'] !== $affiliate->status) {
            if ($error = AffiliateService::activationError($affiliate, $params['status'])) {
                return McpHelper::error('not_allowed', 'This affiliate has no WordPress user, so it cannot be activated. Add the affiliate again with a user.', ['fields' => ['status']]);
            }
        }

        if (array_key_exists('note', $params)) {
            $terms['note'] = $params['note'];
        }

        // the terms first: the status hooks email the affiliate and may read the rate
        if ($terms) {
            AffiliateService::updateTerms($affiliate, $terms);
        }

        $changed = array_keys($terms);

        if (isset($params['status'])) {
            if ($params['status'] === $affiliate->status) {
                McpHelper::warn('status was already ' . $params['status'] . '; it did not change.');
            } else {
                $result = AffiliateService::changeStatus($affiliate, $params['status']);
                if (is_wp_error($result)) {
                    $saved = $changed ? ' These changes were saved before it: ' . implode(', ', array_unique($changed)) . '.' : ' Nothing else was changed.';

                    return McpHelper::error('not_allowed', 'The status could not be changed (' . $result->get_error_code() . '); the affiliate may have changed meanwhile.' . $saved, ['fields' => ['status'], 'saved' => array_values(array_unique($changed))]);
                }
                $changed[] = 'status';
            }
        }

        $affiliate = Affiliate::query()->with(['user', 'group'])->find($affiliate->id);

        return McpHelper::envelope(
            $changed ? sprintf('Affiliate %d updated: %s.', $affiliate->id, implode(', ', array_unique($changed))) : sprintf('Affiliate %d: nothing changed.', $affiliate->id),
            Formatter::affiliate($affiliate)
        );
    }

    /**
     * The commission terms in $params, checked. Empty when none are given.
     *
     * @param array          $params
     * @param Affiliate|null $affiliate Null when creating.
     * @return array|\WP_Error rate_type, rate, group_id
     */
    private static function terms($params, $affiliate)
    {
        $given = array_intersect_key($params, array_flip(['rate_type', 'rate', 'group_id']));
        if (!$given) {
            return [];
        }

        $rateType = isset($params['rate_type']) ? $params['rate_type'] : ($affiliate ? $affiliate->rate_type : 'default');

        if (isset($params['rate']) && !in_array($rateType, ['flat', 'percentage'], true)) {
            return McpHelper::invalid('rate', 'rate applies only with rate_type flat or percentage; this affiliate\'s rate_type is ' . $rateType . '. Send rate_type too.');
        }

        if (isset($params['group_id']) && $rateType !== 'group') {
            return McpHelper::invalid('group_id', 'group_id applies only with rate_type group. Send rate_type=group too.');
        }

        $terms = ['rate_type' => $rateType];

        if (in_array($rateType, ['flat', 'percentage'], true)) {
            $rate = isset($params['rate']) ? $params['rate'] : ($affiliate && $affiliate->rate_type === $rateType ? $affiliate->rate : null);
            if ($rate === null) {
                return McpHelper::invalid('rate', 'rate is required with rate_type ' . $rateType . '.');
            }
            $terms['rate'] = (float) $rate;
        }

        if ($rateType === 'group') {
            if (!defined('FLUENT_AFFILIATE_PRO')) {
                return McpHelper::invalid('rate_type', 'Group rates need FluentAffiliate Pro.');
            }

            $groupId = isset($params['group_id']) ? $params['group_id'] : ($affiliate ? $affiliate->group_id : null);
            if (!$groupId || !AffiliateGroup::query()->find($groupId)) {
                return McpHelper::invalid('group_id', 'group_id must be an existing affiliate group. get-affiliate-context with include=groups lists them.');
            }
            $terms['group_id'] = (int) $groupId;
        }

        return $terms;
    }
}
