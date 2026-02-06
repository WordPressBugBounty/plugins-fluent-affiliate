<?php

namespace FluentAffiliate\App\Modules\Integrations\FluentForms;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Modules\Integrations\BaseConnectorSettings;
use FluentAffiliate\Framework\Support\Arr;

class Connector extends BaseConnectorSettings
{

    protected $integration = 'fluent_forms';


    public function init()
    {
        $this->register();

        if ($this->isAvailable()) {
            add_action('init', function () {
                if (class_exists('FluentForm\App\Http\Controllers\IntegrationManagerController')) {
                    (new \FluentAffiliate\App\Modules\Integrations\FluentForms\FluentFormAffiliateRegistration());
                }
            });
        }
    }

    public function isAvailable()
    {
        return defined('FLUENTFORM_VERSION');
    }

    public function getInfo()
    {
        return [
            'integration'    => $this->integration,
            'title'          => 'FluentForms',
            'description'    => __('Connect FluentAffiliate with FluentForms to track form submissions and payments as referrals', 'fluent-affiliate'),
            'type'           => 'forms',
            'logo'           => esc_url(Utility::asset('images/integrations/fluentforms.png')),
            'is_unavailable' => !$this->isAvailable(),
            'config'         => $this->config(),
        ];
    }

    public function config()
    {
        $defaults = [
            'is_enabled'             => 'no',
            'custom_affiliate_rate'  => 'no',
            'custom_affiliate_rates' => [],
        ];

        if (!$this->willConnectorRun()) {
            return $defaults;
        }

        $settings = Utility::getOption('_' . $this->integration . '_connector_config', []);

        return wp_parse_args($settings, $defaults);
    }

    public function getProductCatOptions($options = [], $params = [])
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $search = Arr::get($params, 'search', '');

        $db = Utility::getApp('db');

        $forms = $db->table('fluentform_forms')
            ->where('has_payment', 1)
            ->when($search, function ($query) use ($search) {
                $query->where('title', 'LIKE', '%' . $search . '%');
            })
            ->limit(50)
            ->get();

        $formattedOptions = [];

        foreach ($forms as $form) {
            $formattedOptions[] = [
                'id'    => $form->id,
                'label' => $form->title,
            ];
        }

        return $formattedOptions;
    }

    public function getConfigFields()
    {
        return [
            'custom_rate_component' => [
                'type'           => 'custom_rate_component',
                'has_categories' => false,
                'has_products'   => true,
                'product_label'  => __('Select Forms', 'fluent-affiliate'),
                'main_label'     => __('Enable custom rate for specific forms', 'fluent-affiliate'),
            ]
        ];
    }

}
