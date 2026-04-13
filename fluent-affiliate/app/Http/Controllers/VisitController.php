<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Helper\Sanitizer;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\Framework\Http\Request\Request;

class VisitController extends Controller
{
    public function export(Request $request)
    {
        $limit = apply_filters('fluent_affiliate/data_export_limit', 5000);

        $visitQuery = Visit::query()
            ->with(['affiliate.user'])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byConvertedStatus($request->getSafe('convert_status', 'sanitize_text_field'))
            ->orderBy($request->getSafe('order_by', 'sanitize_sql_orderby', 'id'), $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC'));

        $total   = $visitQuery->count();
        $limited = $total > $limit;

        $visits = $visitQuery->take($limit)->get();

        $visitIds = $visits->pluck('id')->toArray();
        $convertedVisitIds = Referral::query()
            ->whereIn('visit_id', $visitIds)
            ->groupBy('visit_id')
            ->pluck('visit_id')
            ->toArray();

        $visits = $visits->map(function ($visit) use ($convertedVisitIds) {
            $user = $visit->affiliate ? $visit->affiliate->user : null;
            return [
                'id'           => (int) $visit->id,
                'affiliate_name' => Sanitizer::forCsv($user ? $user->full_name : ''),
                'url'          => Sanitizer::forCsv($visit->url ?? ''),
                'referrer'     => Sanitizer::forCsv($visit->referrer ?? ''),
                'utm_campaign' => Sanitizer::forCsv($visit->utm_campaign ?? ''),
                'utm_medium'   => Sanitizer::forCsv($visit->utm_medium ?? ''),
                'utm_source'   => Sanitizer::forCsv($visit->utm_source ?? ''),
                'converted'    => in_array($visit->id, $convertedVisitIds) ? 'yes' : 'no',
                'created_at'   => (string) $visit->created_at,
            ];
        });

        return [
            'visits'  => $visits,
            'limited' => $limited,
            'total'   => $total,
        ];
    }

    /**
     * @param Request $request
     * @return array|\WP_REST_Response
     */
    public function index(Request $request)
    {
        $visits = Visit::query()->with(
            [
                'affiliate',
                'referrals' => function ($query) {
                    return $query->select(['id', 'visit_id', 'status', 'amount', 'currency']);
                }
            ])
            ->searchBy($request->getSafe('search', 'sanitize_text_field'))
            ->byConvertedStatus($request->get('convert_status'))
            ->orderBy($request->getSafe('order_by', 'sanitize_sql_orderby', 'id'), $request->getSafe('order_type', 'sanitize_sql_orderby', 'DESC'))
            ->paginate($request->getSafe('per_page', 'intval', 10));

        return [
            'visits' => $visits
        ];
    }
}
