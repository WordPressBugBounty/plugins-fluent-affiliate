<?php

namespace FluentAffiliate\App\Modules\MCP\Tools;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\App\Modules\MCP\Formatter;
use FluentAffiliate\App\Modules\MCP\McpHelper;
use FluentAffiliate\App\Services\PermissionManager;

class ReportTools
{
    /** Commission that counts as earned: everything but pending and rejected. */
    const EARNED = ['unpaid', 'processing', 'paid'];

    public static function getReport($params)
    {
        $today = wp_date('Y-m-d');
        $from = isset($params['from']) ? McpHelper::siteDate($params['from'], 'from') : wp_date('Y-m-d', current_time('timestamp', true) - 29 * DAY_IN_SECONDS) . ' 00:00:00';
        if (is_wp_error($from)) {
            return $from;
        }

        $to = isset($params['to']) ? McpHelper::siteDate($params['to'], 'to', true) : $today . ' 23:59:59';
        if (is_wp_error($to)) {
            return $to;
        }

        if ($from > $to) {
            return McpHelper::invalid(['from', 'to'], 'from is after to. Send the earlier date as from.');
        }

        $access = PermissionManager::hasAccess(true);
        $range = [$from, $to];

        $data = [
            'from' => McpHelper::iso($from),
            'to'   => McpHelper::iso($to),
        ];

        if ($access['referral']) {
            $byStatus = [];
            $rows = Referral::query()
                ->select(['status', 'currency'])
                ->selectRaw('COUNT(*) as aggregate_count, SUM(amount) as aggregate_amount, SUM(order_total) as aggregate_order')
                ->whereBetween('created_at', $range)
                ->groupBy('status', 'currency')
                ->get();

            $orderValue = [];
            foreach ($rows as $row) {
                $currency = McpHelper::currency($row->currency);
                $byStatus[] = [
                    'status'   => (string) $row->status,
                    'count'    => (int) $row->aggregate_count,
                    'amount'   => McpHelper::money($row->aggregate_amount),
                    'currency' => $currency,
                ];

                if (in_array($row->status, self::EARNED, true)) {
                    $orderValue[$currency] = (isset($orderValue[$currency]) ? $orderValue[$currency] : 0) + (float) $row->aggregate_order;
                }
            }

            $data['referrals'] = $byStatus;
            $data['order_value'] = [];
            foreach ($orderValue as $currency => $amount) {
                $data['order_value'][] = ['amount' => McpHelper::money($amount), 'currency' => $currency];
            }

            $top = Referral::query()
                ->select(['affiliate_id', 'currency'])
                ->selectRaw('COUNT(*) as aggregate_count, SUM(amount) as aggregate_amount')
                ->whereBetween('created_at', $range)
                ->whereIn('status', self::EARNED)
                // one row per affiliate and currency: amounts in two currencies are never added
                ->groupBy('affiliate_id', 'currency')
                ->orderBy('aggregate_amount', 'DESC')
                ->limit(5)
                ->get();

            $affiliates = Affiliate::query()
                ->select(['id', 'user_id'])
                ->with(['user' => function ($q) {
                    $q->select(['ID', 'display_name']);
                }])
                ->whereIn('id', $top->pluck('affiliate_id')->toArray() ?: [0])
                ->get()
                ->keyBy('id');

            $data['top_affiliates'] = [];
            foreach ($top as $row) {
                $affiliate = isset($affiliates[$row->affiliate_id]) ? $affiliates[$row->affiliate_id] : null;
                $data['top_affiliates'][] = [
                    'affiliate'  => $affiliate ? Formatter::affiliateRef($affiliate) : ['id' => (int) $row->affiliate_id, 'name' => ''],
                    'referrals'  => (int) $row->aggregate_count,
                    'commission' => McpHelper::money($row->aggregate_amount),
                    'currency'   => McpHelper::currency($row->currency),
                ];
            }
        }

        if ($access['visit']) {
            $visits = (int) Visit::query()->whereBetween('created_at', $range)->count();
            $converted = (int) Visit::query()->whereBetween('created_at', $range)->byConvertedStatus('converted')->count();

            $data['visits'] = [
                'count'                   => $visits,
                'converted'               => $converted,
                'conversion_rate_percent' => $visits ? round($converted / $visits * 100, 2) : 0,
            ];
        }

        if ($access['affiliate']) {
            $data['new_affiliates'] = [];
            $rows = Affiliate::query()
                ->select(['status'])
                ->selectRaw('COUNT(*) as aggregate_count')
                ->whereBetween('created_at', $range)
                ->groupBy('status')
                ->get();
            foreach ($rows as $row) {
                $data['new_affiliates'][(string) $row->status] = (int) $row->aggregate_count;
            }
            $data['new_affiliates'] = (object) $data['new_affiliates'];
        }

        if ($access['payout']) {
            $paid = Transaction::query()
                ->select(['currency'])
                ->selectRaw('COUNT(*) as aggregate_count, SUM(total_amount) as aggregate_amount')
                ->where('status', 'paid')
                ->whereBetween('updated_at', $range)
                ->groupBy('currency')
                ->get();

            $data['paid_out'] = [];
            foreach ($paid as $row) {
                $data['paid_out'][] = [
                    'transactions' => (int) $row->aggregate_count,
                    'amount'       => McpHelper::money($row->aggregate_amount),
                    'currency'     => McpHelper::currency($row->currency),
                ];
            }

            $data['payouts_created'] = (int) Payout::query()->whereBetween('created_at', $range)->count();
        }

        $left = [];
        foreach (['referral' => 'referrals', 'visit' => 'visits', 'affiliate' => 'new affiliates', 'payout' => 'payouts'] as $area => $label) {
            if (!$access[$area]) {
                $left[] = $label;
            }
        }
        if ($left) {
            McpHelper::warn('Left out for your permissions: ' . implode(', ', $left) . '.');
        }

        return McpHelper::envelope(
            sprintf('Affiliate program from %s to %s.', substr($from, 0, 10), substr($to, 0, 10)),
            $data,
            ['note' => 'Referrals and visits count by the day they were created; paid_out counts transactions marked paid in the range.']
        );
    }
}
