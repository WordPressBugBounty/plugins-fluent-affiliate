<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\Framework\Http\Request\Request;
use FluentAffiliate\Framework\Support\Arr;

class IntegrationController extends Controller
{
    public function index()
    {
        $integrations = apply_filters('fluent_affiliate/get_integrations', []);

        return [
            'integrations' => $integrations,
        ];
    }

    public function getConfig(Request $request)
    {
        $integration = $request->get('integration');

        $data = apply_filters('fluent_affiliate/get_integration_config_' . $integration, [
            'config' => [],
            'fields' => []
        ]);

        $data = array_filter($data);

        $integrations = apply_filters('fluent_affiliate/get_integrations', []);
        $data['info'] = Arr::get($integrations, $integration, []);

        $data['integration_key'] = $integration;

        return $data;
    }

    public function saveConfig(Request $request)
    {
        $integration = $request->get('integration');
        $config = $request->get('config', []);
        $message = apply_filters('fluent_affiliate/save_integration_config_' . $integration, '', $config);

        if (is_wp_error($message)) {
            return $this->sendError([
                'message' => $message->get_error_message(),
                'data'    => $message->get_error_data(),
            ]);
        }

        if (!$message) {
            return $this->sendError([
                'message' => __('Failed to save settings', 'fluent-affiliate'),
            ]);
        }

        $this->reindexIntegrations($config['is_enabled'] === 'yes' ? $integration : null);

        return [
            'message' => $message,
        ];
    }

    public function updateIntegrationStatus(Request $request)
    {
        $integrationKey = $request->getSafe('integration', 'sanitize_text_field');
        $status = $request->getSafe('is_enabled', 'sanitize_text_field') == 'yes' ? 'yes' : 'no';

        $integrations = apply_filters('fluent_affiliate/get_integrations', []);

        $integration = Arr::get($integrations, $integrationKey);

        if (!$integration || $integration['is_unavailable']) {
            return [
                'message' => __('Integration not found or base plugin is not installed', 'fluent-affiliate'),
            ];
        }

        $settings = Arr::get($integration, 'config', []);
        $settings['is_enabled'] = $status;
        $integrations[$integrationKey]['config'] = $settings;

        Utility::updateOption('_' . $integrationKey . '_connector_config', $settings);

        $this->reindexIntegrations(($status === 'yes') ? $integrationKey : null);

        return [
            'message' => __('Integration status updated successfully', 'fluent-affiliate'),
            'status'  => $status,
            'config'  => $settings,
        ];
    }

    public function getCustomAffiliateOptions(Request $request)
    {
        $integration = $request->getSafe('integration', 'sanitize_text_field');
        $search = $request->getSafe('search', 'sanitize_text_field');
        $type = $request->getSafe('object_type', 'sanitize_text_field');
        $includeIds = $request->get('include_ids', []);

        $includeIds = array_map('intval', (array)$includeIds);

        $options = apply_filters('fluent_affiliate/get_product_cat_options_' . $integration, [], [
            'object_type' => $type,
            'search'      => $search,
            'include_ids' => $includeIds,
        ]);

        return [
            'options' => $options,
        ];
    }

    protected function reindexIntegrations($addedIntegration = null)
    {
        $integrations = apply_filters('fluent_affiliate/get_integrations', []);

        $enabledIntegrations = array_filter($integrations, function ($integration) {
            return !empty($integration['config']['is_enabled']) && $integration['config']['is_enabled'] === 'yes';
        });
        $globalRefSettings = Utility::getReferralSettings(false);

        $enabled = array_keys($enabledIntegrations);

        if ($addedIntegration) {
            $enabled[] = $addedIntegration;
        }

        $enabled = array_values(array_unique($enabled));

        $globalRefSettings['enabled_integrations'] = $enabled;

        update_option('_fa_referral_settings', $globalRefSettings, 'yes');

    }
}
