<?php

namespace FluentAffiliate\App\Modules\FluentCRM;

use FluentAffiliate\App\Models\Affiliate;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Libs\ConditionAssessor;
use FluentCrm\Framework\Support\Arr;

class DeepIntegration
{
    private $importKey = 'fluent_affiliate';

    public function init()
    {
        add_filter('fluent_crm/import_providers', array($this, 'getDriverInfo'));
        add_filter('fluent_crm/get_import_driver_' . $this->importKey, array($this, 'processUserDriver'), 10, 2);
        add_filter('fluent_crm/post_import_driver_' . $this->importKey, array($this, 'importData'), 10, 3);

        // Advanced Filters
        add_filter('fluentcrm_contacts_filter_fluent_affiliate', array($this, 'addAdvancedFilter'), 10, 2);
        add_filter('fluentcrm_advanced_filter_options', array($this, 'addAdvancedFilterOptions'), 10, 1);
        add_filter('fluent_crm/smartcode_group_callback_fluent_affiliate', array($this, 'parseSmartcode'), 10, 4);
        add_filter('fluent_crm/extended_smart_codes', array($this, 'pushGeneralCodes'));

        // Automation
        add_filter('fluentcrm_automation_condition_groups', array($this, 'addAdvancedFilterOptions'), 10, 1);
        add_filter('fluentcrm_automation_conditions_assess_fluent_affiliate', array($this, 'assessFunnelConditions'), 10, 3);

    }

    public function getDriverInfo($drivers)
    {
        $drivers[$this->importKey] = [
            'label'    => __('FluentAffiliate', 'fluent-affiliate'),
            'logo'     => FLUENT_AFFILIATE_URL . 'assets/images/FluentAffiliate.svg',
            'disabled' => false
        ];

        return $drivers;
    }

    public function processUserDriver($config, $request)
    {
        $summary = $request->get('summary');

        if ($summary) {
            $users = fluentCrmDb()->table('fa_affiliates')
                ->join('users', 'users.ID', '=', 'fa_affiliates.user_id')
                ->select(['users.user_email', 'users.display_name'])
                ->limit(5)
                ->get();

            $formattedUsers = [];
            foreach ($users as $user) {
                $formattedUsers[] = [
                    'name'  => $user->display_name,
                    'email' => $user->user_email
                ];
            }

            return [
                'import_info' => [
                    'subscribers'       => $formattedUsers,
                    'total'             => Affiliate::count(),
                    'has_tag_config'    => true,
                    'has_list_config'   => true,
                    'has_status_config' => true,
                    'has_update_config' => false,
                    'has_silent_config' => true
                ]
            ];
        }

        $importType = 'fluent_affiliate_sync';

        $importTitle = __('Sync FluentAffiliate Affiliates Now', 'fluent-affiliate');

        $configFields = [
            'config' => [
                'import_type' => $importType
            ],
            'fields' => [
                'sync_import_html' => [
                    'type'    => 'html-viewer',
                    'heading' => __('Affiliates Sync', 'fluent-affiliate'),
                    'info'    => __('You can sync all your Affiliates into FluentCRM. After this sync you can segment your contacts easily', 'fluent-affiliate')
                ]
            ],
            'labels' => [
                'step_2' => __('Next [Review Data]', 'fluent-affiliate'),
                'step_3' => $importTitle
            ]
        ];

        return $configFields;
    }

    public function importData($returnData, $config, $page)
    {
        $inputs = Arr::only($config, [
            'lists', 'tags', 'status', 'double_optin_email', 'import_silently'
        ]);

        $inputs = wp_parse_args($inputs, [
            'lists'              => [],
            'tags'               => [],
            'new_status'         => 'subscribed',
            'double_optin_email' => 'no',
            'import_silently'    => 'yes'
        ]);

        if (Arr::get($inputs, 'import_silently') == 'yes') {
            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                define('FLUENTCRM_DISABLE_TAG_LIST_EVENTS', true);
            }
        }

        $sendDoubleOptin = Arr::get($inputs, 'double_optin_email') == 'yes';
        $contactStatus = Arr::get($inputs, 'status', 'subscribed');

        $startTime = time();

        $runTime = 15;
        if ($page == 1) {
            fluentcrm_update_option('_fluent_aff_sync_count', 0);
            $runTime = 5;
        }

        $run = true;

        while ($run) {
            $offset = fluentcrm_get_option('_fluent_aff_sync_count', 0);
            $affiliates = fluentCrmDb()->table('fa_affiliates')
                ->limit(10)
                ->offset($offset)
                ->orderBy('id', 'ASC')
                ->get();

            if ($affiliates) {
                foreach ($affiliates as $affiliate) {
                    $subscribers = Helper::getWPMapUserInfo($affiliate->user_id);
                    Subscriber::import(
                        [$subscribers],
                        Arr::get($inputs, 'tags', []),
                        Arr::get($inputs, 'lists', []),
                        true,
                        $contactStatus,
                        $sendDoubleOptin
                    );

                    fluentcrm_update_option('_fluent_aff_sync_count', $offset + 1);
                    if (time() - $startTime > $runTime) {
                        return $this->getSyncStatus();
                    }
                }
            } else {
                $run = false;
            }
        }

        return $this->getSyncStatus();
    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public function addAdvancedFilter($query, $filters)
    {
        foreach ($filters as $filter) {
            $query = $this->applyFilter($query, $filter);
        }

        return $query;
    }

    public function addAdvancedFilterOptions($groups)
    {
        $disabled = false;

        $groups['fluent_affiliate'] = [
            'label'    => __('FluentAffiliate', 'fluent-affiliate'),
            'value'    => 'fluent_affiliate',
            'children' => [
                [
                    'value'   => 'is_affiliate',
                    'label'   => __('Is Affiliate', 'fluent-affiliate'),
                    'type'    => 'single_assert_option',
                    'options' => [
                        'yes' => __('Yes', 'fluent-affiliate'),
                        'no'  => __('No', 'fluent-affiliate')
                    ],
                ],
                [
                    'value'    => 'id',
                    'label'    => __('Affiliate ID', 'fluent-affiliate'),
                    'type'     => 'numeric',
                    'disabled' => $disabled
                ],
                [
                    'value'    => 'referrals',
                    'label'    => __('Total Referrals', 'fluent-affiliate'),
                    'type'     => 'numeric',
                    'disabled' => $disabled
                ],
                [
                    'value'   => 'status',
                    'label'   => __('Status', 'fluent-affiliate'),
                    'type'    => 'single_assert_option',
                    'options' => [
                        'active'   => __('Active', 'fluent-affiliate'),
                        'inactive' => __('Inactive', 'fluent-affiliate'),
                        'pending'  => __('Pending', 'fluent-affiliate')
                    ],
                ],
                [
                    'value'    => 'total_earnings',
                    'label'    => __('Earnings', 'fluent-affiliate'),
                    'type'     => 'numeric',
                    'disabled' => $disabled
                ],
                [
                    'value'    => 'unpaid_earnings',
                    'label'    => __('Unpaid Earnings', 'fluent-affiliate'),
                    'type'     => 'numeric',
                    'disabled' => $disabled
                ],
                [
                    'value'    => 'created_at',
                    'label'    => __('Registration Date', 'fluent-affiliate'),
                    'type'     => 'dates',
                    'disabled' => $disabled
                ],
                [
                    'value'    => 'last_payout_date',
                    'label'    => __('Last Payout Date', 'fluent-affiliate'),
                    'type'     => 'dates',
                    'disabled' => $disabled
                ]
            ]
        ];

        return $groups;
    }

    private function applyFilter($query, $filter)
    {
        $key = Arr::get($filter, 'property', '');
        $value = Arr::get($filter, 'value', '');
        $operator = Arr::get($filter, 'operator', '');

        if ($value === '' || !$key || !$operator) {
            return $query;
        }


        if ($key == 'is_affiliate') {

            if ($value === 'yes') {
                return $query->whereExists(function ($q) {
                    $q->select(fluentCrmDb()->raw(1))
                        ->from('fa_affiliates')
                        ->whereColumn('fa_affiliates.user_id', 'fc_subscribers.user_id');
                });
            }

            return $query->whereNotExists(function ($q) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fa_affiliates')
                    ->whereColumn('fa_affiliates.user_id', 'fc_subscribers.user_id');
            });
        }

        $affProperties = ['id', 'status', 'referrals', 'total_earnings', 'unpaid_earnings'];

        if (in_array($key, $affProperties)) {
            return $query->whereExists(function ($q) use ($key, $value, $operator) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fa_affiliates')
                    ->whereColumn('fa_affiliates.user_id', 'fc_subscribers.user_id')
                    ->where('fa_affiliates.' . $key, $operator, $value);
            });
        }

        if ($key == 'created_at') {
            $filter = Subscriber::filterParser($filter);
            return $query->whereExists(function ($q) use ($filter) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fa_affiliates')
                    ->whereColumn('fa_affiliates.user_id', 'fc_subscribers.user_id');
                if ($filter['operator'] == 'BETWEEN') {
                    return $q->whereBetween('fa_affiliates.created_at', $filter['value']);
                } else {
                    return $q->where('fa_affiliates.created_at', $filter['operator'], $filter['value']);
                }
            });
        }

        if ($key == 'last_payout_date') {
            $filter = Subscriber::filterParser($filter);
            return $query->whereExists(function ($q) use ($filter) {
                $q->select(fluentCrmDb()->raw(1))
                    ->from('fa_affiliates')
                    ->whereColumn('fa_affiliates.user_id', 'fc_subscribers.user_id')
                    ->join('fa_payout_transactions', 'fa_payout_transactions.affiliate_id', '=', 'fa_affiliates.id');
                if ($filter['operator'] == 'BETWEEN') {
                    return $q->whereBetween('fa_payout_transactions.created_at', $filter['value']);
                }

                return $q->where('fa_payout_transactions.created_at', $filter['operator'], $filter['value']);
            });
        }

        return $query;
    }

    private function getSyncStatus()
    {
        $total = fluentCrmDb()->table('fa_affiliates')->count();
        $completedCount = fluentcrm_get_option('_fluent_aff_sync_count', 0);

        $hasMore = $total > $completedCount;

        return [
            'page_total'   => $total,
            'record_total' => $total,
            'has_more'     => $hasMore,
            'current_page' => $completedCount,
            'next_page'    => $completedCount + 1,
            'reload_page'  => !$hasMore
        ];
    }

    public function pushGeneralCodes($codes)
    {
        $codes['fluent_affiliate'] = [
            'key'        => 'fluent_affiliate',
            'title'      => 'FluentAffiliate',
            'shortcodes' => [
                '{{fluent_affiliate.id}}'                 => 'Affiliate ID',
                '{{fluent_affiliate.status}}'             => 'Status',
                '{{fluent_affiliate.total_earnings}}'     => 'Total Earning',
                '{{fluent_affiliate.paid_earnings}}'      => 'Total Paid Earning',
                '{{fluent_affiliate.unpaid_earnings}}'    => 'Unpaid Earnings',
                '{{fluent_affiliate.referrals}}'          => 'Referrals',
                '{{fluent_affiliate.visits}}'             => 'Visits',
                '{{fluent_affiliate.registered_at}}'      => 'Date Registered',
                '{{fluent_affiliate.payment_email}}'      => 'Payment Email',
                '{{fluent_affiliate.last_payout_amount}}' => 'Last payout Amount',
                '{{fluent_affiliate.last_payout_date}}'   => 'Last Payout Date',
                '{{fluent_affiliate.share_url}}'          => 'Sharable URL',
            ]
        ];

        return $codes;
    }

    public function parseSmartCode($value, $valueKey, $defaultValue, $subscriber)
    {
        $userId = $subscriber->user_id;

        if (!$userId) {
            $user = $subscriber->getWpUser();
            if (!$user) {
                return $defaultValue;
            }
            $userId = $user->ID;
        }

        if (!$userId) {
            return $defaultValue;
        }

        $affiliate = Affiliate::where('user_id', $userId)->first();

        if (!$affiliate) {
            return $defaultValue;
        }

        $propValue = $affiliate->getAffPropValue($valueKey, $defaultValue);

        $dates = [
            'registered_at',
            'created_at',
            'last_payout_date'
        ];

        if (in_array($valueKey, $dates) && $propValue) {
            $dateFormat = get_option('date_format');
            return gmdate($dateFormat, strtotime($propValue));
        }

        return $propValue;
    }

    public function assessFunnelConditions($result, $conditions, $subscriber)
    {
        $user = $subscriber->getWpUser();
        $affiliate = false;
        $inputs = [
            'is_affiliate' => 'no'
        ];

        if ($user) {
            $affiliate = Affiliate::where('user_id', $user->ID)->first();
            if ($affiliate) {
                $inputs['is_affiliate'] = 'yes';
            }
        }

        foreach ($conditions as $condition) {
            $prop = $condition['data_key'];
            if ($prop == 'is_affiliate') {
                $inputs[$prop] = ($affiliate) ? 'yes' : 'no';
            } else {
                $inputs[$prop] = ($affiliate) ? $affiliate->getAffPropValue($prop, '') : '';
            }
        }

        return ConditionAssessor::matchAllConditions($conditions, $inputs);
    }

}
