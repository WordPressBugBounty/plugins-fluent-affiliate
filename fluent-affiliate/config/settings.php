<?php

/**
 * This configuration file contains the initial settings for the application.
 * These settings are used as default values if the user has not provided their own settings.
 *
 * The settings are divided into different categories, each represented as an associative array.
 *
 * You can add or modify these settings as per your application's requirements.
 * These settings are accessed and used throughout the application to ensure consistent configuration.
 */
return [

    'referral_settings'                   => [
        'referral_variable'                         => 'ref',
        'rate_type'                                 => 'percentage',
        'rate'                                      => 10,
        'referral_format'                           => 'id',
        'thousands_separator'                       => ',',
        'decimal_separator'                         => '.',
        'currency'                                  => 'USD',
        'currency_symbol_position'                  => 'left',
        'pretty_affiliate_urls'                     => 'yes',
        'credit_last_referrer'                      => 'no',
        'exclude_shipping'                          => 'yes',
        'exclude_tax'                               => 'yes',
        'cookie_sharing'                            => 'yes',
        'credit_first_or_last_referrer'             => 'no',
        'credit_first_or_last_affiliate_as_default' => 'first',
        'recurring_payment'                         => 'no',
        'recurring_payment_rate_type'               => 'percentage',
        'recurring_payment_rate'                    => '20',
        'self_referral_disabled'                    => 'no',
        'exclude_discount'                          => 'no',
    ],

    'email_settings'                      => [
        'from_name'         => '',
        'from_email'        => '',
        'emails_per_second' => '35',
        'reply_to_name'     => '',
        'reply_to_email'    => '',
    ],

    'general_settings'                    => [
        'affiliate_area_page'         => '',
        'term_of_use'                 => '',
        'terms_of_use_label'          => '',
        'hide_title_from_portal_page' => 'no',
        'payout_method'               => 'paypal', // 'paypal' or 'bank_transfer'
    ],

    'email_template'                      => [
        'new_affiliate_registration' => [
            'status'   => 'yes',
            'subject'  => 'New Affiliate Registration',
            'template' => 'Hi Admin, <br><br> A new affiliate has been registered. <br><br> Affiliate Name: {affiliate_name} <br> Affiliate Email: {affiliate_email} <br> Affiliate URL: {affiliate_url} <br><br> Thanks',
        ],
        'new_commission_registered'  => [
            'status'   => 'yes',
            'subject'  => 'New Referral Created',
            'template' => 'Hi Admin, <br><br> A new referral has been created. <br><br> Affiliate Name: {affiliate_name} <br> Affiliate Email: {affiliate_email} <br> Affiliate URL: {affiliate_url} <br> Commission Amount: {commission_amount} <br> Commission Status: {commission_status} <br><br> Thanks',
        ],
        'new_payout_created'         => [
            'status'   => 'yes',
            'subject'  => 'New Payout Created',
            'template' => 'Hi Admin, <br><br> Someone made payout on your site. <br><br> Amount: {payout_amount} <br> Payout Status: {payout_status} <br><br> Thanks',
        ],
        'new_transaction_created'    => [
            'status'   => 'yes',
            'subject'  => 'You have a payment',
            'template' => 'Hi {affiliate_name}, <br><br> You have a payment from {site_name}. <br><br> Amount: {transaction_amount} <br> Transaction Status: {transaction_status} <br><br> Thanks',
        ],
        'new_account_registration'   => [
            'status'   => 'yes',
            'subject'  => 'New Account Registration',
            'template' => 'Hi Admin, <br><br> A new account has been registered. <br><br> Affiliate Name: {affiliate_name} <br> Affiliate Email: {affiliate_email} <br> Affiliate URL: {affiliate_url} <br> Account Name: {account_name} <br> Account Status: {account_status} <br><br> Thanks',
        ],
        'account_approved'           => [
            'status'   => 'yes',
            'subject'  => 'Account Approved',
            'template' => 'Hi Admin, <br><br> An account has been approved. <br><br> Affiliate Name: {affiliate_name} <br> Affiliate Email: {affiliate_email} <br> Affiliate URL: {affiliate_url} <br> Account Name: {account_name} <br> Account Status: {account_status} <br><br> Thanks',
        ],
        'account_rejected'           => [
            'status'   => 'yes',
            'subject'  => 'Account Rejected',
            'template' => 'Hi Admin, <br><br> An account has been rejected. <br><br> Affiliate Name: {affiliate_name} <br> Affiliate Email: {affiliate_email} <br> Affiliate URL: {affiliate_url} <br> Account Name: {account_name} <br> Account Status: {account_status} <br><br> Thanks',
        ],
        'commission_approved'        => [
            'status'   => 'yes',
            'subject'  => 'Referral Created',
            'template' => 'Hi Admin, <br><br> A Referral has been Created. <br><br> Affiliate Name: {affiliate_name} <br> Affiliate Email: {affiliate_email} <br> Affiliate URL: {affiliate_url} <br> Commission Amount: {commission_amount} <br> Commission Status: {commission_status} <br><br> Thanks',
        ],
    ],

    'referral_rate_options'               => [
        'default'    => 'Site default',
        'percentage' => 'Percentage (%)',
        'flat'       => 'Flat',
        'group'      => 'Group',
    ],

    'referral_statuses'                   => [
        'pending'  => 'Pending',
        'unpaid'   => 'Unpaid',
        'paid'     => 'Paid',
        'rejected' => 'Rejected',
    ],

    'affiliate_statuses'                  => [
        'active'    => 'Active',
        'pending'   => 'Pending',
        'cancelled' => 'Cancelled',
        'rejected'  => 'Rejected',
    ],

    'affiliate_group_statuses'            => [
        'active'   => 'Active',
        'inactive' => 'Inactive',
    ],

    'site_default_rate_options'           => [
        'percentage' => 'Percentage (%)',
        'flat'       => 'Flat',
    ],

    'site_default_recurring_rate_options' => [
        'percentage' => 'Percentage (%)',
        'flat'       => 'Flat',
    ],

    'group_rate_type_options'             => [
        'percentage' => 'Percentage (%)',
        'flat'       => 'Flat',
    ],

    'payout_status_options'               => [
        'processing' => 'Processing',
        'paid'       => 'Paid',
        'cancelled'  => 'Cancelled',
    ],

    'default_smart_code'                  => [
        [
            'key'        => 'general',
            'title'      => 'General',
            'shortcodes' => [
                '{site_name}'   => 'Site Name',
                '{admin_email}' => 'Admin Email',
                '{site_url}'    => 'Site URL',
            ],
        ],
        [
            'key'        => 'affiliate',
            'title'      => 'Affiliate',
            'shortcodes' => [
                '{affiliate_name}'  => 'Affiliate Name',
                '{affiliate_email}' => 'Affiliate Email',
                '{affiliate_url}'   => 'Affiliate Url',
            ],
        ],
        [
            'key'        => 'referral',
            'title'      => 'Referral',
            'shortcodes' => [
                '{commission_amount}' => 'Commission Amount',
                '{commission_status}' => 'Commission Status',
            ],
        ],
        [
            'key'        => 'payout',
            'title'      => 'Payout',
            'shortcodes' => [
                '{payout_amount}' => 'Payout Amount',
                '{payout_status}' => 'Payout Status',
            ],
        ],
    ],
];
