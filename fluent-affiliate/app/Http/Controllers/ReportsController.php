<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Models\Visit;
use FluentAffiliate\Framework\Request\Request;

class ReportsController extends Controller
{
    /**
     * @var string
     */
    protected static $daily = 'P1D';
    /**
     * @var string
     */
    protected static $weekly = 'P1W';
    /**
     * @var string
     */
    protected static $monthly = 'P1M';


    /**
     * @param Request $request
     * @return array[]
     */
    public function getAdvancedReportProviders(Request $request)
    {
        $providers['fla'] = [
            'title' => __('FluentAffiliate', 'fluent-affiliate')
        ];
        $providers['edd'] = [
            'title' => __('Easy Digital Downloads', 'fluent-affiliate')
        ];
        $providers['woo'] = [
            'title' => __('WooCommerce', 'fluent-affiliate')
        ];

        apply_filters('fluent_affiliate/advanced_report_providers', []);

        return [
            'providers' => $providers
        ];
    }

    /**
     * @param $items
     * @param $title
     * @param $compareRange
     * @param $type
     * @return array
     */
    protected function formatDataSet($items, $title, $compareRange, $type = 'primary')
    {
        $data = [
            'data'            => $items['key'],
            'date'            => $items['date'],
            'label'           => $title,
            'id'              => sanitize_title($title, $type, 'display'),
            'backgroundColor' => 'rgba(81, 52, 178, 0.5)',
            'borderColor'     => '#b175eb',
            'fill'            => true
        ];

        return $data;

    }


    /**
     * @param $period
     * @return array
     */
    protected function getDateRangeArray($period)
    {
        $range = [];

        $formatter = 'basicFormatter';

        foreach ($period as $key => $date) {
            $range['date'][$key] = $this->{$formatter}($date);
            $range['key'][$key] = 0;
        }

        return $range;
    }

    /**
     * @param $date
     * @return string
     * @throws \Exception
     */
    protected function basicFormatter($date)
    {
        if (is_string($date)) {
            $date = new \DateTime($date);
        }

        return $date->format('Y-m-d');
    }

    /**
     * @param $from
     * @param $to
     * @param $interval
     * @return \DatePeriod
     * @throws \Exception
     */
    protected function makeDatePeriod($from, $to, $interval = null)
    {
        $interval = $interval ?: static::$daily;

        return new \DatePeriod($from, new \DateInterval($interval), $to);
    }


    /**
     * @param $from
     * @param $to
     * @return string
     */
    protected function getFrequency($from, $to)
    {
        $numDays = $to->diff($from)->format("%a");

        if ($numDays > 91) {
            return static::$monthly;
        }

        return static::$daily;
    }


    /**
     * @param Request $request
     * @param $provider
     * @return array
     */
    public function getReports(Request $request, $provider)
    {
        if ($provider == 'woo') {
            return $this->getWooCommerceReports($provider);
        } else if ($provider == 'edd') {
            return $this->getEddCommerceReports($provider);
        }

        return $this->getFluentAffiliateReports($provider);
    }

    /**
     * @param $provider
     * @return array
     */
    public function getFluentAffiliateReports($provider)
    {
        $widgets = $this->getFluentAffiliateWidgets();
        foreach ($widgets as $widgetKey => $widget) {
            $widgets[$widgetKey]['label'] = $widget['title'];
            $widgets[$widgetKey]['value'] = $widget['count'];
            unset($widgets[$widgetKey]['title']);
            unset($widgets[$widgetKey]['count']);
        }
        $data['report'] = [
            'enabled' => true,
            'title'   => __('FluentAffiliate - Advanced Reports', 'fluent-affiliate'),
            'widgets' => $widgets,
        ];

        return $data;
    }

    /**
     * @param $provider
     * @return array
     */
    public function getWooCommerceReports($provider)
    {
        $widgets = $this->getWidget($provider);
        foreach ($widgets as $widgetKey => $widget) {
            $widgets[$widgetKey]['label'] = $widget['title'];
            $widgets[$widgetKey]['value'] = $widget['count'];
            unset($widgets[$widgetKey]['title']);
            unset($widgets[$widgetKey]['count']);
        }
        $data['report'] = [
            'enabled' => true,
            'title'   => __('WooCommerce - Advanced Reports', 'fluent-affiliate'),
            'widgets' => $widgets,
        ];

        return $data;

    }

    /**
     * @param $provider
     * @return array
     */
    public function getEddCommerceReports($provider)
    {
        $widgets = $this->getWidget($provider);
        foreach ($widgets as $widgetKey => $widget) {
            $widgets[$widgetKey]['label'] = $widget['title'];
            $widgets[$widgetKey]['value'] = $widget['count'];
            unset($widgets[$widgetKey]['title']);
            unset($widgets[$widgetKey]['count']);
        }
        $data['report'] = [
            'enabled' => true,
            'title'   => __('Easy Digital Downloads - Advanced Reports', 'fluent-affiliate'),
            'widgets' => $widgets,
        ];

        return $data;
    }

    /**
     * @return mixed
     */
    public function getFluentAffiliateWidgets()
    {
        $data = [
            'affiliates'        => [
                'title'    => __('Affiliates', 'fluent-affiliate'),
                'count'    => Affiliate::where('status', 'active')->count(),
                'is_money' => false,
            ],
            'pending'           => [
                'title'    => __('Affiliates - Pending', 'fluent-affiliate'),
                'count'    => Affiliate::where('status', 'pending')->count(),
                'is_money' => false,
            ],
            'paid'              => [
                'title'    => __('Total Paid Referrals', 'fluent-affiliate'),
                'count'    => array_sum(Referral::where('status', 'paid')->pluck('amount')
                    ->toArray()),
                'is_money' => true,
            ],
            'unpaid'            => [
                'title'    => __('Total Unpaid Referrals', 'fluent-affiliate'),
                'count'    => array_sum(Referral::where('status', 'unpaid')->pluck('amount')
                    ->toArray()),
                'is_money' => true,
            ],
            'visits'            => [
                'title'    => __('Visits', 'fluent-affiliate'),
                'count'    => Visit::count(),
                'is_money' => false,
            ],
            'paid_transactions' => [
                'title'    => __('Paid Transactions', 'fluent-affiliate'),
                'count'    => array_sum(Transaction::where('status', 'paid')->pluck('total_amount')
                    ->toArray()),
                'is_money' => true,
            ],
            'pending_payout'    => [
                'title'    => __('Total Pending Payouts', 'fluent-affiliate'),
                'count'    => array_sum(Payout::where('status', 'processing')->pluck('total_amount')
                    ->toArray()),
                'is_money' => true,
            ],
        ];

        return apply_filters('fluent_affiliate_dashboard_stats', $data);
    }

    /**
     * @param $provider
     * @return mixed
     */
    public function getWidget($provider)
    {
        $data = [
            'total_revenue' => [
                'title'    => __('Total Revenue', 'fluent-affiliate'),
                'count'    => array_sum(Referral::where('provider', $provider)->where('status', 'paid')->pluck('amount')
                    ->toArray()),
                'is_money' => true
            ],
            'total_orders'  => [
                'title'    => __('Total Orders', 'fluent-affiliate'),
                'count'    => Referral::where('provider', $provider)->count(),
                'is_money' => false,
            ],
        ];

        return apply_filters('fluent_affiliate_dashboard_' . $provider . '_stats', $data);
    }
}
