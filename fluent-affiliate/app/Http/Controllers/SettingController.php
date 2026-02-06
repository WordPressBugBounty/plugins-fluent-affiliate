<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\User;
use FluentAffiliate\App\Modules\Auth\AuthHelper;
use FluentAffiliate\App\Services\EmailNotificationSettings;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\Framework\Http\Request\Request;
use FluentAffiliate\App\Helper\CustomSanitizer;
use FluentAffiliate\App\Services\AffiliateService;

class SettingController extends Controller
{
    public function getEmailConfig(Request $request)
    {
        $emailConfig = apply_filters('fluent_affiliate/get_email_config', Utility::getEmailSettings());

        return [
            'settings' => $emailConfig
        ];
    }

    public function updateEmailConfig(Request $request)
    {
        $newSettings = $request->get('settings', []);

        $newSettings = Arr::only($newSettings, array_keys(Utility::defaultEmailSettings()));

        $newSettings['email_footer'] = wp_kses_post($newSettings['email_footer']);

        if (!empty($newSettings['send_from_email'])) {
            if (!is_email($newSettings['send_from_email'])) {
                return $this->sendError([
                    'message' => __('Send from email is not valid', 'fluent-affiliate'),
                ]);
            }
        }

        if (!empty($newSettings['send_from_name'])) {
            $newSettings['send_from_name'] = sanitize_text_field($newSettings['send_from_name']);
        }

        if (!empty($newSettings['reply_to_email'])) {
            if (!is_email($newSettings['reply_to_email'])) {
                return $this->sendError([
                    'message' => __('Reply to email is not valid', 'fluent-affiliate'),
                ]);
            }
        }

        if (!empty($newSettings['reply_to_name'])) {
            $newSettings['reply_to_name'] = sanitize_text_field($newSettings['reply_to_name']);
        }

        if (!empty($newSettings['admin_email'])) {
            $emails = explode(',', $newSettings['admin_email']);
            $emails = array_map('trim', $emails);
            $emails = array_unique($emails);
            $emails = array_values(array_filter($emails, 'is_email'));
            $newSettings['admin_email'] = implode(',', $emails);
        }

        $textTypes = ['logo', 'body_bg', 'content_bg', 'content_color', 'footer_content_color', 'highlight_bg', 'highlight_color', 'font_family', 'disable_powered_by'];

        foreach ($textTypes as $textType) {
            if (!empty($newSettings[$textType])) {
                $newSettings[$textType] = sanitize_text_field($newSettings[$textType]);
            }
        }

        $newSettings = apply_filters('fluent_affiliate/update_email_config', $newSettings);

        Utility::updateOption('global_email_settings', $newSettings);

        return [
            'settings' => $newSettings,
            'message'  => __('Email settings has been updated successfully', 'fluent-affiliate'),
        ];
    }

    public function getNotificationEmails(Request $request)
    {
        $emails = EmailNotificationSettings::getDefaultEmailTypes();
        $customSettings = Utility::getOption('email_notifications', []);

        $formattedEmails = [];

        foreach ($emails as $emailKey => $data) {
            $customSetting = Arr::get($customSettings, $emailKey, []);
            $defaultBody = EmailNotificationSettings::getDefaultEmailBody($emailKey);
            $defaultSettings = $data['settings'];
            $settings = wp_parse_args($customSetting, $defaultSettings);
            $data['default_body'] = $defaultBody;
            $data['settings'] = $settings;
            $data['email_key'] = $emailKey;
            $formattedEmails[] = $data;
        }

        return [
            'emails'     => $formattedEmails,
            'smartcodes' => EmailNotificationSettings::getSmartCodes(),
        ];
    }

    public function patchSingleNotificationEmail(Request $request)
    {
        $emailKey = $request->get('email_key');

        $emails = EmailNotificationSettings::getDefaultEmailTypes();
        $customSettings = Utility::getOption('email_notifications', []);

        if (!isset($emails[$emailKey])) {
            return $this->sendError([
                'message' => __('Invalid email key provided', 'fluent-affiliate'),
            ]);
        }

        $existingSettings = Arr::get($customSettings, $emailKey, []);

        if (!$existingSettings) {
            $existingSettings = $emails[$emailKey]['settings'];
        }

        $validKeys = [
            'active',
            'subject',
            'is_default_body',
            'email_body'
        ];

        $data = array_filter(Arr::only($request->get('data', []), $validKeys));

        if (isset($data['active'])) {
            $data['active'] = $data['active'] === 'yes' ? 'yes' : 'no';
        }

        if (isset($data['is_default_body'])) {
            $data['is_default_body'] = $data['is_default_body'] === 'yes' ? 'yes' : 'no';
        }

        if (isset($data['subject'])) {
            $data['subject'] = sanitize_text_field($data['subject']);
        }

        if (isset($data['email_body'])) {
            $data['email_body'] = wp_kses_post($data['email_body']);
        }

        $newSettings = wp_parse_args($data, $existingSettings);

        if ($newSettings['is_default_body'] == 'yes') {
            $newSettings['email_body'] = '';
        }

        if ($newSettings['active'] == 'no') {
            $newSettings['email_body'] = '';
            $newSettings['is_default_body'] = 'yes';
        }

        $customSettings[$emailKey] = $newSettings;

        Utility::updateOption('email_notifications', $customSettings);

        return [
            'message'      => __('Email settings has been updated successfully', 'fluent-affiliate'),
            'settings'     => $newSettings,
            'all_settings' => $customSettings
        ];
    }


    public function getReferralConfig(Request $request)
    {
        $config = apply_filters('fluent_affiliate/get_referral_config', Utility::getReferralSettings());

        return [
            'config' => $config
        ];
    }

    public function saveReferralConfig(Request $request)
    {
        $referralConfig = $request->get('referral_config', []);

        $referralVariable = Arr::get($referralConfig, 'referral_variable');

        // Step 1: Keep only letters, hyphen, and underscore
        $referralVariable = preg_replace('/[^a-zA-Z-_]/', '', $referralVariable);

        // Step 2: Replace multiple consecutive hyphens with a single one
        $referralVariable = preg_replace('/-+/', '-', $referralVariable);

        // Step 3: Replace multiple consecutive underscores with a single one
        $referralVariable = preg_replace('/_+/', '_', $referralVariable);

        $referralConfig = Arr::set($referralConfig, 'referral_variable', $referralVariable);

        $referralConfig = Arr::set($referralConfig, 'referral_variable', $referralVariable);

        $referralConfig = $this->maybeUpdateIntegrations($referralConfig);

        $referralConfig = CustomSanitizer::sanitizeReferralConfig($referralConfig);

        $config = apply_filters('fluent_affiliate/update_referral_config', $referralConfig);

        Utility::updateReferralSettings($config);

        return [
            'config'  => $config,
            'message' => __('Referral settings updated successfully', 'fluent-affiliate')
        ];
    }

    public function maybeUpdateIntegrations($referralConfig)
    {
        $enabledIntegrations = Arr::get($referralConfig, 'enabled_integrations', []);
        if (!$enabledIntegrations) {
            return $referralConfig;
        }

        $integrations = apply_filters('fluent_affiliate/get_integrations', []);

        $enabled = [];
        foreach ($enabledIntegrations as $integration) {
            $integrationConfig = Arr::get($integrations, $integration, []);
            if (!$integrationConfig) {
                continue;
            }

            $enabled[] = $integration;
            $integrationConfig['is_enabled'] = 'yes';
            Utility::updateOption('_' . $integration . '_connector_config', $integrationConfig);
        }

        $referralConfig['enabled_integrations'] = array_values(array_unique($enabled));

        return $referralConfig;
    }

    public function createPage(Request $request)
    {
        $title = $request->getSafe('title');
        $content = $request->getSafe('content');

        $page = get_page_by_path($title);

        if ($page) {
            return $this->sendError([
                'message' => __('Page already exists', 'fluent-affiliate')
            ]);
        }

        $pageId = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_type'    => 'page',
            'post_status'  => 'publish'
        ]);

        $page = [
            'id'    => $pageId,
            'link'  => get_permalink($pageId),
            'title' => $title
        ];

        return [
            'page'    => $page,
            'message' => __('Page created successfully', 'fluent-affiliate')
        ];
    }

    public function getPagesOptions(Request $request)
    {
        $search = $request->getSafe('search');

        $db = Utility::getApp('db');

        $allPages = $db->table('posts')
            ->when($search, function ($query) use ($search) {
                return $query->where('post_title', 'LIKE', '%%' . $search . '%%')
                    ->orWhere('post_name', 'LIKE', '%%' . $search . '%%');
            })
            ->where('post_type', 'page')
            ->where('post_status', 'publish')
            ->select(['ID', 'post_title', 'post_name'])
            ->orderBy('post_title', 'ASC')
            ->get();

        $pages = [];
        foreach ($allPages as $page) {
            $pages[] = [
                'id'    => (int)$page->ID,
                'link'  => get_permalink($page->ID),
                'title' => $page->post_title ? $page->post_title : __('(no title)', 'fluent-affiliate')
            ];
        }

        return [
            'pages' => $pages
        ];
    }

    public function getAffiliatesOptions(Request $request)
    {
        $search = $request->getSafe('search');

        $includedIds = (array)$request->get('include_ids', []);

        $formattedAffiliates = AffiliateService::getAffiliatesOptions($search, $includedIds);

        return [
            'affiliates' => $formattedAffiliates
        ];
    }

    public function getUsersOptions(Request $request)
    {
        $search = $request->getSafe('search');
        $userQuery = User::query();

        if ($request->get('exclude_affiliates', false)) {
            $userQuery->whereDoesntHave('affiliate');
        }

        $users = $userQuery
            ->searchBy($search)
            ->limit(20)
            ->get();

        $includedIds = $request->get('included_ids', []);

        if ($includedIds) {
            $pushedIds = $users->pluck('ID')->toArray();
            $leftOutIds = array_diff($includedIds, $pushedIds);

            if ($leftOutIds) {
                $leftOutUsers = User::query()
                    ->whereIn('ID', $leftOutIds)
                    ->get();

                $users = $users->merge($leftOutUsers);
            }
        }

        $formattedUsers = [];

        $canViewUsers = current_user_can('list_users');

        foreach ($users as $user) {
            $formattedUsers[] = [
                'ID'           => $user->ID,
                'display_name' => $user->full_name . ($canViewUsers ? ' (' . $user->user_email . ')' : ''),
            ];
        }

        return [
            'users' => $formattedUsers
        ];
    }

    public function getRegistrationFields(Request $request)
    {
        $fields = AuthHelper::getRegistrationFormFields();

        return [
            'fields'   => $fields,
            'settings' => AuthHelper::getRegrationSettings()
        ];
    }
}
