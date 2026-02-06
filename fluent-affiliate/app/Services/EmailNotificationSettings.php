<?php

namespace FluentAffiliate\App\Services;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\Framework\Support\Arr;

class EmailNotificationSettings
{

    public static function getAdminEmail()
    {
        $emailSettings = Utility::getEmailSettings();
        $adminEmail = $emailSettings['admin_email'];

        if (!$adminEmail) {
            $adminEmail = get_bloginfo('admin_email');
        }
        return $adminEmail;
    }

    public static function getDefaultEmailTypes()
    {
        return [
            'account_created_to_admin'             => [
                'name'             => 'account_created_to_admin',
                'title'            => __('New Affiliate Signup Notification Email', 'fluent-affiliate'),
                'description'      => __('An email will be sent to the admin when someone register as an affiliate. So admin can review the application and approve the affiliate', 'fluent-affiliate'),
                'recipient'        => 'admin',
                'smartcode_groups' => ['affiliate', 'user', 'site'],
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('New Affiliate registered to your site {{site.name}}', 'fluent-affiliate'),
                    'is_default_body' => 'yes',
                    'email_body'      => ''
                ]
            ],
            'new_sale_to_admin'                    => [
                'name'             => 'new_sale_to_admin',
                'title'            => __('New Sale Notification Email to Admin', 'fluent-affiliate'),
                'description'      => __('An email will be sent to the admin when a new sale is made through the affiliate program.', 'fluent-affiliate'),
                'recipient'        => 'admin',
                'smartcode_groups' => ['affiliate', 'user', 'referral'],
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('New Affiliate Sale Notification', 'fluent-affiliate'),
                    'is_default_body' => 'yes',
                    'email_body'      => ''
                ]
            ],
            'pending_account_created_to_affiliate' => [
                'name'             => 'pending_account_created_to_affiliate',
                'title'            => __('Affiliate Application Pending Notification Email', 'fluent-affiliate'),
                'description'      => __('An email will be sent to the affiliate when they apply for an affiliate account and the application is pending review.', 'fluent-affiliate'),
                'recipient'        => 'affiliate',
                'smartcode_groups' => ['affiliate', 'user', 'site'],
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Affiliate application received - Review in progress', 'fluent-affiliate'),
                    'is_default_body' => 'yes',
                    'email_body'      => ''
                ]
            ],
            'account_approved_to_affiliate'        => [
                'name'             => 'account_approved_to_affiliate',
                'title'            => __('Affiliate Account Approved Notification Email', 'fluent-affiliate'),
                'description'      => __('An email will be sent to the affiliate when their account is approved.', 'fluent-affiliate'),
                'recipient'        => 'affiliate',
                'smartcode_groups' => ['affiliate', 'user', 'site'],
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Your affiliate application has been approved - Welcome aboard', 'fluent-affiliate'),
                    'is_default_body' => 'yes',
                    'email_body'      => ''
                ]
            ],
            'new_sale_to_affiliate'                => [
                'name'             => 'new_sale_to_affiliate',
                'title'            => __('New Sale Notification Email to Affiliate', 'fluent-affiliate'),
                'description'      => __('An email will be sent to the affiliate when they make a sale through their referral link.', 'fluent-affiliate'),
                'recipient'        => 'affiliate',
                'smartcode_groups' => ['affiliate', 'user', 'site', 'referral'],
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Affiliate Sale Confirmation: New Commission Earned', 'fluent-affiliate'),
                    'is_default_body' => 'yes',
                    'email_body'      => ''
                ]
            ],
            'payout_to_affiliate'                  => [
                'name'             => 'payout_to_affiliate',
                'title'            => __('Affiliate Payout Notification Email', 'fluent-affiliate'),
                'description'      => __('An email will be sent to the affiliate when their payout is processed.', 'fluent-affiliate'),
                'recipient'        => 'affiliate',
                'smartcode_groups' => ['affiliate', 'user', 'site', 'transaction'],
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Your Affiliate Payout Has Been Processed - {{site.name}}', 'fluent-affiliate'),
                    'is_default_body' => 'yes',
                    'email_body'      => ''
                ]
            ]
        ];
    }

    public static function getEmailsConfig()
    {
        $emails = self::getDefaultEmailTypes();
        $customSettings = Utility::getOption('email_notifications', []);

        foreach ($emails as $emailKey => $data) {
            $customSetting = Arr::get($customSettings, $emailKey, []);

            if (!$customSetting) {
                continue;
            }

            $isEnabled = Arr::get($customSetting, 'active', 'yes') === 'yes' ? 'yes' : 'no';

            if ($isEnabled !== 'yes') {
                $emails[$emailKey]['settings']['active'] = 'no';
                continue;
            }

            if (!empty($customSetting['subject'])) {
                $emails[$emailKey]['settings']['subject'] = $customSetting['subject'];
            }


            if (!empty($customSetting['email_body']) && $customSetting['is_default_body'] != 'yes') {
                $emails[$emailKey]['settings']['email_body'] = $customSetting['email_body'];
                $emails[$emailKey]['settings']['is_default_body'] = 'no';
            } else {
                $emails[$emailKey]['settings']['is_default_body'] = 'yes';
            }

        }

        return $emails;
    }

    public static function getEmailSetting($type)
    {
        $allEmails = self::getEmailsConfig();

        if (!isset($allEmails[$type])) {
            return null;
        }

        $email = $allEmails[$type];

        $settings = Arr::get($email, 'settings', []);


        if (Arr::get($settings, 'active', 'yes') !== 'yes') {
            return null;
        }

        if (Arr::get($settings, 'is_default_body', 'yes') == 'yes' || !Arr::get($settings, 'email_body', '')) {
            $settings['email_body'] = self::getDefaultEmailBody($type);
        }

        return $settings;
    }

    /**
     * Get the default email body for various notification types.
     *
     * @param string $type The type of notification.
     * @return string The default email body.
     */
    public static function getDefaultEmailBody($type = '')
    {
        if ($type == 'account_created_to_admin') {
            ob_start();
            ?>
            <p>Hello there,</p>
            <p>Great news! A new affiliate has registered to promote your products on your website ({{site.name}} -
                {{site.url}}).</p>
            <p><strong> Here are the details:</strong></p>
            <blockquote>
                <p><strong>Username: </strong>{{user.user_login}}</p>
                <p><strong>User Email:</strong> {{user.user_email}}</p>
                <p><strong>Display Name:</strong> {{user.display_name}}</p>
                <p><strong>User Role:</strong> {{user.roles}}</p>
                <p><strong>Affiliate Status:</strong> {{affiliate.status}}</p>
                <p><strong>User Website: </strong> {{user.user_url}}</p>
                <p><strong>Affiliate Payment Email:</strong> {{affiliate.payment_email}}</p>
                <p><strong>Application Note:</strong> {{affiliate.note}}</p>
            </blockquote>
            <p>
                <a style="color: #ffffff; background-color: #0072ff; font-size: 16px; border-radius: 5px; text-decoration: none; font-weight: bold; font-style: normal; padding: 0.8rem 1rem; border-color: #0072ff;"
                   href="##affiliate.edit_url##">View the Affiliate</a></p>
            <hr/>
            <p>This is an automated message from the FluentAffiliate.</p>
            <?php
            return ob_get_clean();
        }

        if ($type == 'pending_account_created_to_affiliate') {
            ob_start();
            ?>
            <p>Hi {{user.display_name}},</p>
            <p>Thank you for applying to become an affiliate partner with {{site.name}}! We're excited about the
                possibility of working together to promote our products.</p>
            <blockquote>
                <h3>📋 Application Status: Under Review</h3>
                <p>We have received your affiliate application and our team is currently reviewing it carefully. We want
                    to ensure that our partnership will be mutually beneficial and align with our brand values.</p>
                <p><b>Expected Review Time:</b> 2-3 business days</p>
            </blockquote>

            <h3>What Happens Next?</h3>
            <p>Once your application is reviewed, you will receive an email notification regarding the status of your
                application. If approved, you will gain access to our affiliate dashboard where you can track your
                performance and earnings.</p>

            <blockquote>
                <h4>💡 In the Meantime</h4>
                <ul>
                    <li>Familiarize yourself with our products and brand</li>
                    <li>Prepare your marketing content and strategies</li>
                    <li>Follow us on social media for updates and inspiration</li>
                    <li>Feel free to reach out if you have any questions</li>
                </ul>
            </blockquote>

            <p>
                <a style="color: #ffffff; background-color: #0072ff; font-size: 16px; border-radius: 5px; text-decoration: none; font-weight: bold; font-style: normal; padding: 0.8rem 1rem; border-color: #0072ff;"
                   href="##site.url##">Visit Our Website</a>
            </p>

            <hr/>

            <p>We appreciate your patience during the review process. If you have any questions or need additional
                information, please don't hesitate to contact us.</p>
            <p>Best regards,</p>
            <p>The {{site.name}} Team</p>
            <?php
            return ob_get_clean();
        }

        if ($type == 'account_approved_to_affiliate') {
            ob_start();
            ?>
            <div style="text-align: center;" class="text-center">
                <h1>🎉 Congratulations!</h1>
                <h2>Your Affiliate Application Has Been Approved</h2>
            </div>
            <p>Hi {{user.display_name}},</p>
            <p>
                We are thrilled to inform you that your application to become an affiliate partner with {{site.name}}
                has been approved! Welcome aboard!
            </p>
            <blockquote>
                <h3>✅ Your Account Details</h3>
                <p><strong>Affiliate ID:</strong> {{affiliate.id}}</p>
                <p><strong>Your Affiliate Link:</strong> {{affiliate.affiliate_link}}</p>
            </blockquote>
            <p>
                <a style="color: #ffffff; background-color: #0072ff; font-size: 16px; border-radius: 5px; text-decoration: none; font-weight: bold; font-style: normal; padding: 0.8rem 1rem; border-color: #0072ff;"
                   href="##site.portal_url##">Access to your dashboard</a>
            </p>

            <h3>🚀 Getting Started</h3>

            <p>To help you hit the ground running, here are a few steps to get started:</p>
            <ul>
                <li>Log in to your affiliate dashboard using the link above.</li>
                <li>Familiarize yourself with our products and promotional materials.</li>
                <li>Start sharing your unique affiliate link to earn commissions on sales.</li>
                <li>Track Your Success - Monitor clicks, conversions, and earnings in real-time</li>
            </ul>

            <blockquote>
                <h3>📋 Important Guidelines</h3>
                <ul>
                    <li>Always disclose your affiliate relationship</li>
                    <li>No spam or misleading advertising</li>
                    <li>Respect brand guidelines and messaging</li>
                    <li>Report any issues or questions promptly</li>
                </ul>
            </blockquote>

            <p>We're excited to see what you'll achieve as part of our affiliate family! Our team is here to support
                your success every step of the way.</p>

            <p>Welcome aboard!</p>
            <p>The {{site.name}} Team</p>
            <?php
            return ob_get_clean();
        }

        if ($type == 'new_sale_to_affiliate') {
            ob_start();
            ?>
            <p>Hi {{user.display_name}},</p>
            <p>
                One of your referrals just made a purchase. We're excited to share the details of your latest referral.
            </p>
            <blockquote>
                <h3>Referral Details</h3>
                <p><strong>Amount:</strong> {{referral.amount_formatted}}</p>
                <p><strong>Description:</strong> {{referral.description}}</p>
            </blockquote>
            <p>
                <a style="color: #ffffff; background-color: #0072ff; font-size: 16px; border-radius: 5px; text-decoration: none; font-weight: bold; font-style: normal; padding: 0.8rem 1rem; border-color: #0072ff;"
                   href="##site.portal_url##">Access to your dashboard</a>
            </p>

            <blockquote>
                <h3>📊 Your Performance Summary</h3>
                <p><strong>Earnings (last 30 days):</strong> {{affiliate.earning_total_30_days_formatted}}</p>
                <p><strong>Lifetime Earnings:</strong> {{affiliate.lifetime_earning_formatted}}</p>
                <p><strong>Current Unpaid Earnings:</strong> {{affiliate.unpaid_earning_formatted}}</p>
                <hr/>
                <p>Please note: This sale is eligible for payout after 60 days (credit card refund period). Your
                    commission will be processed in the next payout cycle following this waiting period.</p>
            </blockquote>

            <p>Thank you for being such a valuable partner! Your dedication to promoting our products is truly
                appreciated, and we're excited to see your continued success.</p>

            <p>Best regards!</p>
            <p>The {{site.name}} Team</p>
            <?php
            return ob_get_clean();
        }

        if ($type == 'new_sale_to_admin') {
            ob_start();
            ?>
            <p>Hi there,</p>
            <p>
                A new sale has been completed through your affiliate program.
            </p>
            <blockquote>
                <h3>Referral Details</h3>
                <p><strong>Amount:</strong> {{referral.amount_formatted}}</p>
                <p><strong>Description:</strong> {{referral.description}}</p>
                <p><strong>Affiliate:</strong> {{user.display_name}}</p>
                <p><strong>Affiliate ID:</strong> {{affiliate.id}}</p>
            </blockquote>

            <p>
                <a style="color: #ffffff; background-color: #0072ff; font-size: 16px; border-radius: 5px; text-decoration: none; font-weight: bold; font-style: normal; padding: 0.8rem 1rem; border-color: #0072ff;"
                   href="##site.admin_portal##">
                    Access to Admin dashboard
                </a>
            </p>

            <blockquote>
                <h3>More about the affiliate</h3>
                <p><strong>Earnings (last 30 days):</strong> {{affiliate.earning_total_30_days_formatted}}</p>
                <p><strong>Lifetime Earnings:</strong> {{affiliate.lifetime_earning_formatted}}</p>
                <p><strong>Current Unpaid Earnings:</strong> {{affiliate.unpaid_earning_formatted}}</p>
            </blockquote>

            <p>This is an automated message from the FluentAffiliate.</p>
            <?php
            return ob_get_clean();
        }

        if ($type == 'payout_to_affiliate') {
            ob_start();
            ?>
            <p>Hi {{user.display_name}},</p>
            <p>
                This email confirms that your affiliate commission payment has been processed and sent to your PayPal
                account.
            </p>
            <blockquote>
                <h3>Payment Details</h3>
                <p><strong>Amount:</strong> {{transaction.amount_formatted}}</p>
                <p><strong>PayPal Account Email:</strong> {{affiliate.payment_email}}</p>
                <p><strong>Payment Date:</strong> {{transaction.created_at_formatted}}</p>
                <p><strong>Total Referrals:</strong> {{transaction.referrals_count}}</p>
            </blockquote>
            <p>You can view detailed reports and transaction history in your affiliate dashboard at any time.</p>
            <p>
                <a href="##site.portal_url##">Access to your Affiliate dashboard</a>
            </p>

            <p>Thank you for your partnership.</p>
            <p>The {{site.name}} Team</p>
            <?php
            return ob_get_clean();
        }

        return '';
    }

    public static function getSmartCodes()
    {
        return [
            [
                'key'        => 'affiliate',
                'title'      => __('Affiliate Data', 'fluent-affiliate'),
                'shortcodes' => [
                    '{{affiliate.id}}'                              => __('Affiliate ID', 'fluent-affiliate'),
                    '##affiliate.edit_url##'                        => __('Affiliate Profile URL (Admin)', 'fluent-affiliate'),
                    '{{affiliate.affiliate_link}}'                  => __('Affiliate Link (Primary Domain)', 'fluent-affiliate'),
                    '{{affiliate.earning_total_30_days_formatted}}' => __('Last 30 Days Earnnings', 'fluent-affiliate'),
                    '{{affiliate.lifetime_earning_formatted}}'      => __('Lifetime Earnnings', 'fluent-affiliate'),
                    '{{affiliate.unpaid_earning_formatted}}'        => __('Unpaid Earnnings', 'fluent-affiliate'),
                    '{{affiliate.referrals}}'                       => __('Total Refferals', 'fluent-affiliate'),
                    '{{affiliate.visits}}'                          => __('Total Visits', 'fluent-affiliate'),
                    '{{affiliate.payment_email}}'                   => __('Payment Email', 'fluent-affiliate'),
                    '{{affiliate.note}}'                            => __('Affiliate Note', 'fluent-affiliate'),
                ]
            ],
            [
                'key'        => 'user',
                'title'      => __('User Data', 'fluent-affiliate'),
                'shortcodes' => [
                    '{{user.first_name}}'   => __('First Name', 'fluent-affiliate'),
                    '{{user.last_name}}'    => __('Last Name', 'fluent-affiliate'),
                    '{{user.display_name}}' => __('Display Name', 'fluent-affiliate'),
                    '{{user.user_email}}'   => __('Email', 'fluent-affiliate'),
                    '{{user.user_login}}'   => __('Username', 'fluent-affiliate'),
                    '{{user.roles}}'        => __('User Roles', 'fluent-affiliate'),
                ]
            ],
            [
                'key'        => 'transaction',
                'title'      => __('Payout Transaction', 'fluent-affiliate'),
                'shortcodes' => [
                    '{{transaction.amount_formatted}}'     => __('Payout Amount', 'fluent-affiliate'),
                    '{{transaction.created_at_formatted}}' => __('Created Date', 'fluent-affiliate'),
                    '{{transaction.referrals_count}}'      => __('Total Refferrals', 'fluent-affiliate'),
                    '{{transaction.currency}}'             => __('Payout Currency', 'fluent-affiliate'),
                ]
            ],
            [
                'key'        => 'referral',
                'title'      => __('Referral', 'fluent-affiliate'),
                'shortcodes' => [
                    '{{transaction.amount_formatted}}'     => __('Referral Amount', 'fluent-affiliate'),
                    '{{transaction.created_at_formatted}}' => __('Created Date', 'fluent-affiliate'),
                    '{{transaction.description}}'          => __('Referral Description', 'fluent-affiliate'),
                    '{{transaction.currency}}'             => __('Referral Currency', 'fluent-affiliate'),
                    '{{transaction.utm_campaign}}'         => __('Referral UTM Campaign', 'fluent-affiliate'),
                ]
            ],
            [
                'key'        => 'site',
                'title'      => __('Site Data', 'fluent-affiliate'),
                'shortcodes' => [
                    '{{site.name}}'         => __('Site Title', 'fluent-affiliate'),
                    '{{site.description}}'  => __('Site tagline', 'fluent-affiliate'),
                    '{{site.admin_email}}'  => __('Admin Email', 'fluent-affiliate'),
                    '##site.portal_url##'   => __('Affiliate Dashboard URL', 'fluent-affiliate'),
                    '##site.admin_portal##' => __('Affiliate Admin Dashboard URL', 'fluent-affiliate'),
                    '##site.url##'          => __('Site URL', 'fluent-affiliate'),
                    '##site.login_url##'    => __('Login Url', 'fluent-affiliate'),
                ]
            ]
        ];
    }
}
