<?php

namespace FluentAffiliate\App\Modules\MCP\Tools;

use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\App\Modules\MCP\Formatter;
use FluentAffiliate\App\Modules\MCP\McpHelper;

class VisitTools
{
    public static function listVisits($params)
    {
        $range = McpHelper::dateRange($params, 'created_from', 'created_to');
        if (is_wp_error($range)) {
            return $range;
        }

        $paging = McpHelper::pagination($params);

        $query = Visit::query()->with(['referrals' => function ($q) {
            $q->select(['id', 'visit_id']);
        }]);

        if (isset($params['affiliate_id'])) {
            $query->where('affiliate_id', $params['affiliate_id']);
        }

        // a visit converted when a referral points to it (referrals.visit_id), as the admin screen counts it
        if (isset($params['converted'])) {
            $query->byConvertedStatus($params['converted'] ? 'converted' : 'not_converted');
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
            $query->where(function ($q) use ($like) {
                $q->where('url', 'LIKE', $like)
                    ->orWhere('referrer', 'LIKE', $like)
                    ->orWhere('utm_campaign', 'LIKE', $like)
                    ->orWhere('utm_source', 'LIKE', $like)
                    ->orWhere('utm_medium', 'LIKE', $like);
            });
        }

        $total = (clone $query)->count();
        $visits = $query->orderBy('id', 'DESC')
            ->offset(($paging['page'] - 1) * $paging['per_page'])
            ->limit($paging['per_page'])
            ->get();

        $rows = [];
        foreach ($visits as $visit) {
            $rows[] = Formatter::visit($visit);
        }

        return McpHelper::envelope(
            sprintf('%d visit(s) match; showing %d, newest first.', $total, count($rows)),
            $rows,
            McpHelper::pagingMeta($total, $paging)
        );
    }
}
