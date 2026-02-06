<?php

namespace FluentAffiliate\App\Hooks\Handlers;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Payout;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Transaction;
use FluentAffiliate\App\Services\EmailNotificationSettings;
use FluentAffiliate\App\Services\Libs\Mailer;
use FluentAffiliate\Framework\Support\Arr;

class EmailNotificationHandler
{
    public function register()
    {
        // Affiliate Onboarding Hooks
        add_action('fluent_affiliate/affiliate_created', [$this, 'sendAffiliateCreatedNotification'], 10, 1);
        add_action('fluent_affiliate/affiliate_status_to_active', [$this, 'sendAffiliateApprovedNotification'], 10, 1);

        // Payout Transaction Hooks
        add_action('fluent_affiliate/payout/transaction/transaction_updated_to_paid', [$this, 'sendPayoutTransactionPaidNotification'], 10, 2);

        // Add more hooks as needed
        add_action('fluent_affiliate/referral_marked_unpaid', [$this, 'scheduleReferralNotification'], 10, 1);
        add_action('fluent_affiliate/send_new_referral_notification', [$this, 'sendNewReferralNotification'], 10, 1);
    }

    public function sendAffiliateCreatedNotification($affiliate)
    {
        $contextData = [
            'affiliate' => $affiliate,
            'user'      => $affiliate->user
        ];

        // let's send the email to the affiliate
        if ($affiliate->status === 'pending') {
            $toAffiliate = EmailNotificationSettings::getEmailSetting('pending_account_created_to_affiliate');

            if ($toAffiliate) {
                $emailBody = $this->wrapEmailBody($toAffiliate['email_body'], $contextData);

                $emailSubject = $toAffiliate['email_subject'] ?? __('Your Affiliate Account is Pending Approval', 'fluent-affiliate');
                $emailSubject = apply_filters('fluent_affiliate/parse_smart_codes', $emailSubject, $contextData, 'text');

                $mailer = new Mailer($affiliate->user->user_email, $emailSubject, $emailBody);

                if ($affiliate->user->display_name) {
                    $mailer->to($affiliate->user->user_email, $affiliate->user->display_name);
                }

                $mailer->send(true);
            }
        }

        // let's send the email to the admin
        $toAdmin = EmailNotificationSettings::getEmailSetting('account_created_to_admin');

        if (!$toAdmin) {
            return;
        }

        $emailBody = $this->wrapEmailBody($toAdmin['email_body'], $contextData);

        $emailSubject = $toAdmin['email_subject'] ?? __('New Affiliate Account Created', 'fluent-affiliate');

        $adminEmail = EmailNotificationSettings::getAdminEmail();

        if (!$adminEmail) {
            return;
        }

        $emailSubject = apply_filters('fluent_affiliate/parse_smart_codes', $emailSubject, $contextData, 'text');

        $mailer = new Mailer($adminEmail, $emailSubject, $emailBody);
        $mailer->send(true);

        return true;
    }

    public function sendAffiliateApprovedNotification(Affiliate $affiliate)
    {
        $toAffiliate = EmailNotificationSettings::getEmailSetting('account_approved_to_affiliate');

        if (!$toAffiliate) {
            return;
        }

        $user = $affiliate->user;

        $contextData = [
            'affiliate' => $affiliate,
            'user'      => $user
        ];

        $emailBody = $this->wrapEmailBody($toAffiliate['email_body'], $contextData);
        $emailSubject = $toAffiliate['email_subject'] ?? __('Your Affiliate Account is Approved', 'fluent-affiliate');
        $emailSubject = apply_filters('fluent_affiliate/parse_smart_codes', $emailSubject, $contextData, 'text');
        $mailer = new Mailer($user->user_email, $emailSubject, $emailBody);
        if ($user->display_name) {
            $mailer->to($user->user_email, $user->display_name);
        }

        return $mailer->send(true);
    }

    public function sendPayoutTransactionPaidNotification(Transaction $transaction, Payout $payout)
    {
        $toAffiliate = EmailNotificationSettings::getEmailSetting('payout_to_affiliate');
        if (!$toAffiliate) {
            return;
        }

        $user = $transaction->affiliate ? $transaction->affiliate->user : null;

        if (!$user) {
            return;
        }

        $contextData = [
            'transaction' => $transaction,
            'payout'      => $payout,
            'affiliate'   => $transaction->affiliate,
            'user'        => $user
        ];

        $emailBody = $this->wrapEmailBody($toAffiliate['email_body'], $contextData);
        $emailSubject = $toAffiliate['email_subject'] ?? __('Your Payout Transaction is Paid', 'fluent-affiliate');
        $emailSubject = apply_filters('fluent_affiliate/parse_smart_codes', $emailSubject, $contextData, 'text');

        $mailer = new Mailer($user->user_email, $emailSubject, $emailBody);

        if ($user && $user->display_name) {
            $mailer->to($user->user_email, $user->display_name);
        }

        return $mailer->send(true);
    }


    public function scheduleReferralNotification(Referral $referral)
    {
        $user = $referral->affiliate ? $referral->affiliate->user : null;
        if (!$user) {
            return;
        }

        // Use a background job or async processing to send the email
        // This is a placeholder for actual async logic
        wp_schedule_single_event(time() + 5, 'fluent_affiliate/send_new_referral_notification', [$referral->id]);
    }

    public function sendNewReferralNotification($referralId)
    {
        $referral = Referral::find($referralId);
        if (!$referral) {
            return;
        }

        $user = $referral->affiliate ? $referral->affiliate->user : null;

        if (!$user) {
            return;
        }

        $affiliate = $referral->affiliate;

        $contextData = [
            'referral'  => $referral,
            'affiliate' => $affiliate,
            'user'      => $user
        ];
        $toAffiliate = EmailNotificationSettings::getEmailSetting('new_sale_to_affiliate');
        if ($toAffiliate && $affiliate->isNewRefEmailEnabled()) {
            $emailBody = $this->wrapEmailBody($toAffiliate['email_body'], $contextData);
            $emailSubject = $toAffiliate['email_subject'] ?? __('Affiliate Sale Confirmation: New Commission Earned', 'fluent-affiliate');
            $emailSubject = apply_filters('fluent_affiliate/parse_smart_codes', $emailSubject, $contextData, 'text');

            $mailer = new Mailer($user->user_email, $emailSubject, $emailBody);

            if ($user->display_name) {
                $mailer->to($user->user_email, $user->display_name);
            }

            $mailer->send(true);
        }

        $toAdmin = EmailNotificationSettings::getEmailSetting('new_sale_to_admin');
        $adminEmail = EmailNotificationSettings::getAdminEmail();
        if (!$adminEmail || !$toAdmin) {
            return;
        }

        $emailBody = $this->wrapEmailBody($toAdmin['email_body'], $contextData);
        $emailSubject = $toAdmin['email_subject'] ?? __('New Affiliate Sale Notification', 'fluent-affiliate');

        $emailSubject = apply_filters('fluent_affiliate/parse_smart_codes', $emailSubject, $contextData, 'text');

        $mailer = new Mailer($adminEmail, $emailSubject, $emailBody);
        return $mailer->send(true);
    }

    private function wrapEmailBody($body, $data = [])
    {
        $template_config = Utility::getEmailSettings();
        $footerText = Arr::get($template_config, 'email_footer', '');

        if (Arr::get($template_config, 'disable_powered_by', 'no') !== 'yes' || !defined('FLUENT_AFFILIATE_PRO')) {
            // translators: %s is the FluentAffiliate link
            $footerText .= '<br>' . sprintf(__('Affiliate Platform is powered by %s.', 'fluent-affiliate'), '<a href="https://fluentaffiliate.com/?utm_campaign=in_plugin&utm_source=email_footer&utm_medium=dist" target="_blank">FluentAffiliate</a>');
        }

        Arr::get($template_config, 'email_body', $body);

        $body = (string)Utility::getApp('view')->make('email.email_template', [
            'body'            => $body,
            'template_config' => $template_config,
            'footer'          => $footerText,
        ]);

        return apply_filters('fluent_affiliate/parse_smart_codes', $body, $data, 'html');
    }

}
