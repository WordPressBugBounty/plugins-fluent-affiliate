<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\Framework\Http\Request\Request;

class VisitController extends Controller
{
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
