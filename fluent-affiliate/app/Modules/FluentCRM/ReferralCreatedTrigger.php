<?php

namespace FluentAffiliate\App\Modules\FluentCRM;

use FluentAffiliate\Framework\Support\Arr;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;

class ReferralCreatedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fluent_affiliate/referral_marked_unpaid'; // just change the active to approved if changed to approved
        $this->priority = 50;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('FluentAffiliate', 'fluent-affiliate'),
            'label'       => __('Successful Referral Created', 'fluent-affiliate'),
            'description' => __('This automation will be initiated when a successful referral is created with paid order.', 'fluent-affiliate'),
            'icon'        => 'el-icon-link',
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed'
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'update_type'  => 'update', // skip_all_actions, skip_update_if_exist
            'run_multiple' => 'yes'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'update_type'  => [
                'type'    => 'radio',
                'label'   => __('If Contact Already Exist?', 'fluent-affiliate'),
                'help'    => __('Please specify what will happen if the subscriber already exist in the database', 'fluent-affiliate'),
                'options' => FunnelHelper::getUpdateOptions()
            ],
            'run_multiple' => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event.', 'fluent-affiliate'),
                'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluent-affiliate')
            ],
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Successful Referral Created', 'fluent-affiliate'),
            'sub_title' => __('This automation will be initiated when a successful referral is created with paid order.', 'fluent-affiliate'),
            'fields'    => [
                'subscription_status'      => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'editable_statuses',
                    'is_multiple' => false,
                    'label'       => __('Subscription Status', 'fluent-affiliate'),
                    'placeholder' => __('Select Status', 'fluent-affiliate')
                ],
                'subscription_status_info' => [
                    'type'       => 'html',
                    'info'       => '<b>' . __('An Automated double-optin email will be sent for new subscribers', 'fluent-affiliate') . '</b>',
                    'dependency' => [
                        'depends_on' => 'subscription_status',
                        'operator'   => '=',
                        'value'      => 'pending'
                    ]
                ]
            ],
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        if (empty($originalArgs[0])) {
            return false;
        }
        $referral = $originalArgs[0];
        $affiliate = $referral->affiliate;

        $subscriberData = \FluentCrm\App\Services\Helper::getWPMapUserInfo($affiliate->user_id);

        if (!$subscriberData) {
            return;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);
        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);
        $subscriberData['source'] = 'fluent_affiliate';

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);

        if (!$this->isProcessable($funnel, $subscriber)) {
            return;
        }

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $referral->id
        ], $subscriber);
    }

    private function isProcessable($funnel, $subscriber)
    {
        if (!$subscriber) {
            return true;
        }

        $conditions = $funnel->conditions;
        // check update_type
        $updateType = Arr::get($conditions, 'update_type');

        if ($updateType == 'skip_all_if_exist') {
            return false;
        }

        if (FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            $multipleRun = Arr::get($funnel->conditions, 'run_multiple') == 'yes';
            if ($multipleRun) {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            } else {
                return false;
            }
        }

        return true;
    }
}
