<?php

namespace FluentAffiliate\App\Hooks\Handlers;

use FluentAffiliate\App\App;
use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Models\Referral;

class DashboardWidgetHandler
{
    public function __construct()
    {
        if (current_user_can('manage_options')) {
            add_action('wp_dashboard_setup', [$this, 'add']);
        }
    }

    public function add()
    {

        wp_add_dashboard_widget(
            'fluent_affiliate_dashboard_widget',
            __('FluentAffiliate Referral Summary', 'fluent-affiliate'),
            [$this, 'render']
        );

        add_action('admin_enqueue_scripts', [$this, 'enqueueWidgetStyles']);
    }

    public function render()
    {

        $db = App::make('db');
        /**
         * :TODO Optimize this query with a single query
         * */


        $todayReferralsAmount = Referral::query()
            ->whereDate('created_at', $db->raw('CURDATE()'))
            ->whereNotIn('status', ['rejected', 'refunded'])
            ->sum('amount')
        ;

        $lastWeekReferralsAmount = Referral::query()
            ->whereDate('created_at', '>=', $db->raw('DATE_SUB(CURDATE(), INTERVAL 7 DAY)'))
            ->whereNotIn('status', ['rejected', 'refunded'])
            ->sum('amount')
        ;

        $lastMonthReferralsAmount = Referral::query()
            ->whereDate('created_at', '>=', $db->raw('DATE_SUB(CURDATE(), INTERVAL 30 DAY)'))
            ->whereNotIn('status', ['rejected', 'refunded'])
            ->sum('amount')
        ;

        $allTimeReferralsAmount = Referral::query()
            ->whereNotIn('status', ['rejected', 'refunded'])
            ->sum('amount')
        ;

        $todayStats = Helper::formatMoney($todayReferralsAmount);
        $lastSevenDayStats = Helper::formatMoney($lastWeekReferralsAmount);
        $lastMonthStats = Helper::formatMoney($lastMonthReferralsAmount);
        $allTimeStats = Helper::formatMoney($allTimeReferralsAmount);


        $headers = [
            ['label' => __('Period', 'fluent-affiliate')],
            ['label' => __('Amount', 'fluent-affiliate')]
        ];

        $data = [
            [
                'label' => __('Today', 'fluent-affiliate'),
                'value' => esc_attr($todayStats)
            ],
            [
                'label' => __('Last 7 days', 'fluent-affiliate'),
                'value' => esc_attr($lastSevenDayStats)
            ],
            [
                'label' => __('Last 30 days', 'fluent-affiliate'),
                'value' => esc_attr($lastMonthStats)
            ],
            [
                'label' => __('All time', 'fluent-affiliate'),
                'value' => esc_attr($allTimeStats)
            ]
        ];


        return App::make('view')->render('template.dashboard-widget', [
            'stats'   => $data,
            'headers' => $headers
        ]);
    }

    public function enqueueWidgetStyles()
    {
        wp_register_style(
            'fluent-affiliate-dashboard-widget-style',
            false,
            [],
            FLUENT_AFFILIATE_VERSION
        );

        // Enqueue the registered style
        wp_enqueue_style('fluent-affiliate-dashboard-widget-style');

        // Add our inline styles to the registered handle
        wp_add_inline_style('fluent-affiliate-dashboard-widget-style', $this->getWidgetStyles());
    }

    public function getWidgetStyles()
    {
        return '
        .fluent-affiliate-dashboard-widget {
            width: 100%;
            border-collapse: collapse;
        }
        
        .fluent-affiliate-dashboard-widget thead tr th {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 1px solid #c3c4c7;
            color: #1b1b1b;
        }
        .fluent-affiliate-dashboard-widget thead tr th:last-child {
            width: 150px;
        }
        
        .fluent-affiliate-dashboard-widget tbody tr td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
        }
        
        .fluent-affiliate-dashboard-widget tbody tr:nth-child(odd) td {
            background: #f6f7f7;
        }
        .fluent-affiliate-dashboard-widget tbody tr:last-child td {
            border-bottom: none;
        }
        
        .fluent-affiliate-dashboard-widget tbody tr td:first-child {
            width: 200px;
        }
        
        .fluent-affiliate-dashboard-widget tbody tr td:last-child {
            color: #313334;
            font-weight: 600;
        }
    ';
    }
}
