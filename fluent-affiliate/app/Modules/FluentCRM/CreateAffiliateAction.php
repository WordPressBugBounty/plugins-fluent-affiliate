<?php

namespace FluentAffiliate\App\Modules\FluentCRM;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\User;
use FluentAffiliate\App\Modules\Auth\AuthHelper;
use FluentAffiliate\Framework\Support\Arr;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;

class CreateAffiliateAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'create_affiliate_account';
        $this->priority = 20;
        parent::__construct();

        // Only admins may add or change this step: it creates an affiliate with its own status and rate.
        add_filter('fluent_crm/funnel_privileged_actions', function ($actions) {
            $actions = (array)$actions;
            $actions[$this->actionName] = 'manage_options';
            return $actions;
        });
    }

    public function getBlock()
    {
        return [
            'category'    => __('FluentAffiliate', 'fluent-affiliate'),
            'title'       => __('Create Affiliate Account', 'fluent-affiliate'),
            'description' => __('This action will create an affiliate account for the user.', 'fluent-affiliate'),
            'icon'        => 'el-icon-s-finance',
            'settings'    => [
                'affiliate_status' => 'active',
                'rate_type'        => 'default',
                'rate'             => '',
                'payment_email'    => '',
                'note'             => '',
            ]
        ];
    }


    public function getBlockFields()
    {
        return [
            'title'     => __('Create Affiliate Account', 'fluent-affiliate'),
            'sub_title' => __('This action will create an affiliate account for the user.', 'fluent-affiliate'),
            'fields'    => [
                'affiliate_status' => [
                    'type'        => 'select',
                    'label'       => __('Affiliate Status', 'fluent-affiliate'),
                    'inline_help' => __('Select the status for the affiliate account. Please note, WordPress user account must need to be available for this contact.', 'fluent-affiliate'),
                    'options'     => $this->getAffiliateStatusOptions()
                ],
                'rate_type'        => [
                    'type'        => 'radio',
                    'label'       => __('Rate Type', 'fluent-affiliate'),
                    'inline_help' => __('Select the rate type for the affiliate account.', 'fluent-affiliate'),
                    'options'     => [
                        [
                            'id'    => 'default',
                            'title' => __('Default', 'fluent-affiliate'),
                        ],
                        [
                            'id'    => 'percentage',
                            'title' => __('Percentage', 'fluent-affiliate'),
                        ],
                        [
                            'id'    => 'Flat Rate',
                            'title' => __('Fixed Amount', 'fluent-affiliate'),
                        ],
                    ],
                ],
                'rate_value'       => [
                    'type'        => 'input-text',
                    'placeholder' => __('Enter rate value', 'fluent-affiliate'),
                    'label'       => __('Rate Value', 'fluent-affiliate'),
                    'help'        => __('Enter the rate value for the affiliate account.', 'fluent-affiliate'),
                    'dependency'  => [
                        'depends_on' => 'rate_type',
                        'operator'   => '!=',
                        'value'      => 'default',
                    ]
                ],
                'payment_email'    => [
                    'type'        => 'input-text-popper',
                    'label'       => __('Payment Email', 'fluent-affiliate'),
                    'placeholder' => __('Enter payment email for the affiliate account.', 'fluent-affiliate'),
                    'inline_help' => __('This email will be used for payment purposes.', 'fluent-affiliate'),
                ],
                'note'             => [
                    'type'        => 'input-text-popper',
                    'label'       => __('Note (Optional)', 'fluent-affiliate'),
                    'placeholder' => __('Enter any additional notes for the affiliate account.', 'fluent-affiliate'),
                    'inline_help' => __('This note will be added to the affiliate account.', 'fluent-affiliate'),
                ],
            ],
        ];
    }

    private function getAffiliateStatusOptions()
    {
        return [
            [
                'id'    => 'active',
                'title' => __('Approved', 'fluent-affiliate'),
            ],
            [
                'id'    => 'inactive',
                'title' => __('Rejected', 'fluent-affiliate'),
            ],
            [
                'id'    => 'pending',
                'title' => __('Pending', 'fluent-affiliate'),
            ],
        ];
    }

    protected function fluentCrmChecksPrivilegedSteps()
    {
        return class_exists('\\FluentCrm\\App\\Services\\Funnel\\PrivilegedSteps');
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        // FluentCRM before 3.2.6 has no privileged-step check, so the step's author is checked here.
        if (!$this->fluentCrmChecksPrivilegedSteps() && !user_can((int)$sequence->created_by, 'manage_options')) {
            $funnelMetric->notes = __('Funnel Skipped because only an administrator can add this step', 'fluent-affiliate');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $userId = $subscriber->getWpUserId();
        if (!$userId) {
            $funnelMetric->notes = __('Funnel Skipped because user could not be found', 'fluent-affiliate');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $existingAffiliate = Affiliate::query()
            ->where('user_id', $userId)
            ->first();

        if ($existingAffiliate) {
            $funnelMetric->notes = __('Funnel Skipped because the contact is already an affiliate', 'fluent-affiliate');
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $user = User::query()->find($userId);

        if (!$user) {
            return false;
        }

        $rateType = Arr::get($sequence->settings, 'rate_type', 'default');

        // the action's "Fixed Amount" option is saved as 'Flat Rate'
        if ($rateType === 'Flat Rate') {
            $rateType = 'flat';
        }

        // Steps saved before this check keep running, so the settings are validated here.
        $rate = Arr::get($sequence->settings, 'rate_value');
        $isValidRate = in_array($rateType, ['percentage', 'flat'], true)
            && is_numeric($rate) && $rate >= 0
            && ($rateType !== 'percentage' || $rate <= 100);
        if (!$isValidRate) {
            $rateType = 'default';
        }

        $status = Arr::get($sequence->settings, 'affiliate_status');
        if (!in_array($status, ['active', 'pending', 'inactive'], true)) {
            $status = AuthHelper::getInitialAffiliateStatus();
        }

        $paymentEmail = Arr::get($sequence->settings, 'payment_email', '');

        $affiliateData = array_filter([
            'rate'          => $rateType !== 'default' ? $rate : null,
            'rate_type'     => $rateType,
            'status'        => $status,
            'note'          => sanitize_textarea_field(Arr::get($sequence->settings, 'note', '')),
            'payment_email' => $paymentEmail ? sanitize_email($paymentEmail) : '',
        ]);

        $affiliate = $user->syncAffiliateProfile($affiliateData);

        if ($affiliate) {
            // translators: %1$s is the user's full name, %2$s is the affiliate status
            $funnelMetric->notes = sprintf(__('Affiliate account created for user %1$s with status %2$s', 'fluent-affiliate'), $user->full_name, $affiliate->status);

            $funnelMetric->save();
        }

        return true;
    }
}
