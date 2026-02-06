<?php

namespace FluentAffiliate\App\Modules\FluentCRM;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\User;
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

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
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

        $paymentEmail = Arr::get($sequence->settings, 'payment_email', '');

        $affiliateData = array_filter([
            'rate'          => $rateType !== 'default' ? Arr::get($sequence->settings, 'rate_value') : null,
            'rate_type'     => $rateType,
            'status'        => Arr::get($sequence->settings, 'affiliate_status', 'active'),
            'note'          => Arr::get($sequence->settings, 'rate', ''),
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
