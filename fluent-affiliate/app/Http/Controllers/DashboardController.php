<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\App\Services\PermissionManager;
use FluentAffiliate\App\Services\Reports\ReportService;
use FluentAffiliate\Framework\Http\Request\Request;

class DashboardController extends Controller
{
    /**
     * @param Request $request
     *
     * @return array|\WP_REST_Response
     * @throws \Exception
     */
    public function getStats(Request $request)
    {
        $hasAccess = PermissionManager::hasAccess(true);

        $activeAffiliates = $hasAccess['affiliate'] ? Affiliate::where('status', 'active')->count() : 0;
        $pendingAffiliates = $hasAccess['affiliate'] ? Affiliate::where('status', 'pending')->count() : 0;
        $totalReferrals = $hasAccess['referral'] ? Referral::count() : 0;
        $visitsCount = $hasAccess['visit'] ? Affiliate::where('status', 'active')->sum('visits') : 0;
        $conversionRate = $visitsCount > 0 ? round(($totalReferrals / $visitsCount) * 100, 2) : 0;
        $totalPaid = $hasAccess['payout'] ? Payout::where('status', 'paid')->sum('total_amount') : 0;
        $unpaidAmount = $hasAccess['payout'] ? Referral::where('status', 'unpaid')->sum('amount') : 0;
        $totalOrderValue = $hasAccess['referral'] ? Referral::whereIn('status', ['paid', 'unpaid'])->sum('order_total') : 0;

        $stats = array_filter([
            'total_paid' => $hasAccess['payout'] ? [
                'title' => __('Total Paid', 'fluent-affiliate'),
                'amount' => $totalPaid,
                'is_currency' => true,
                'icon' => 'MoneyPaid'
            ] : [],
            'total_unpaid' => $hasAccess['payout'] ? [
                'title' => __('Total Unpaid', 'fluent-affiliate'),
                'amount' => $unpaidAmount,
                'is_currency' => true,
                'icon' => 'MoneyUnPaid'
            ] : [],
            'active_affiliates' => $hasAccess['affiliate'] ? [
                'title' => __('Active Affiliates', 'fluent-affiliate'),
                'amount' => $activeAffiliates,
                'is_currency' => false,
                'is_number' => true,
                'icon' => 'Persons'
            ] : [],
            'total_visits' => $hasAccess['visit'] ? [
                'title' => __('Total Visits', 'fluent-affiliate'),
                'amount' => $visitsCount,
                'is_currency' => false,
                'is_number' => true,
                'icon' => 'LinkClick'
            ] : [],
            'total_referrals' => $hasAccess['referral'] ? [
                'title' => __('Total Referrals', 'fluent-affiliate'),
                'amount' => $totalReferrals,
                'is_currency' => false,
                'is_number' => true,
                'icon' => 'LinkReferal'
            ] : [],
            'conversion_rate' => $hasAccess['referral'] && $hasAccess['visit'] ? [
                'title' => __('Conversion Rate', 'fluent-affiliate'),
                'amount' => number_format($conversionRate, 2) . '%',
                'is_currency' => false,
                'is_number' => false,
                'icon' => 'PercentIcon'
            ] : [],
            'pending_affiliates' => $hasAccess['affiliate'] ? [
                'title' => __('Pending Affiliates', 'fluent-affiliate'),
                'amount' => $pendingAffiliates,
                'is_currency' => false,
                'is_number' => true,
                'icon' => 'PendingUser'
            ] : [],
            'total_order_value' => $hasAccess['referral'] ? [
                'title' => __('Total Order Value by Referrals', 'fluent-affiliate'),
                'amount' => $totalOrderValue,
                'is_currency' => true,
                'icon' => 'BagLoveIcon'
            ] : []
        ]);

        $recentReferrals = $hasAccess['referral'] ? Referral::query()
            ->orderBy('created_at', 'DESC')
            ->with(['affiliate.user'])
            ->limit(5)
            ->get() : [];

        $topAffiliates = $hasAccess['affiliate'] ? Affiliate::query()
            ->where('status', 'active')
            ->with(['user'])
            ->orderBy('total_earnings', 'DESC')
            ->limit(5)
            ->get() : [];

        $recentVisits = $hasAccess['visit'] ? Visit::query()
            ->orderBy('created_at', 'DESC')
            ->with(['affiliate.user'])
            ->limit(5)
            ->get() : [];

        $recentPayouts = $hasAccess['payout'] ? Payout::query()
            ->where('status', 'paid')
            ->orderBy('created_at', 'DESC')
            ->limit(5)
            ->get() : [];

        return [
            'stats'            => $stats,
            'recent_referrals' => $recentReferrals,
            'top_affiliates'   => $topAffiliates,
            'recent_visits'    => $recentVisits,
            'recent_payouts'   => $recentPayouts
        ];
    }

    public function getChartStats(Request $request)
    {
        $defaultStartDate = gmdate('Y-m-d', strtotime('-30 days'));
        $defaultEndDate = gmdate('Y-m-d');

        $startDate = $request->getSafe('start_date', 'sanitize_text_field', $defaultStartDate);
        $endDate = $request->getSafe('end_date', 'sanitize_text_field', $defaultEndDate);

        $hasAccess = PermissionManager::hasAccess(true);

        $seriesConfigs = array_filter([
            $hasAccess['referral'] ? [
                'model' => Referral::class,
                'label' => 'Referrals'
            ] : null,
            $hasAccess['visit'] ? [
                'model' => Visit::class,
                'label' => 'Visits'
            ] : null,
            $hasAccess['affiliate'] ? [
                'model' => Affiliate::class,
                'label' => 'Affiliates'
            ] : null
        ]);

        return ReportService::create()->getMultiChartStatistics($seriesConfigs, $startDate, $endDate);
    }
}
