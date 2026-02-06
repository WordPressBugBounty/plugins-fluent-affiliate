<?php

namespace FluentAffiliate\App\Modules\Integrations\Paymattic;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Modules\Integrations\BaseConnectorSettings;
use FluentAffiliate\Framework\Support\Arr;

class Connector extends BaseConnectorSettings
{
    protected $integration = 'paymattic';

    public function init()
    {
        $this->register();

        if($this->isAvailable()) {
            add_action('init', function () {
                (new \FluentAffiliate\App\Modules\Integrations\Paymattic\BootstrapAffiliateRegister());
            });
        }
    }

    public function isAvailable()
    {
        return defined('\WPPAYFORM_VERSION');
    }

    public function getInfo()
    {
        return [
            'integration'    => $this->integration,
            'title'          => 'Paymattic',
            'description'    => 'Connect FluentAffiliate with Paymattic to track transactions and commissions',
            'type'           => 'commerce',
            'logo'           => Utility::asset('images/integrations/paymattic.svg'),
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
        $search = Arr::get($params, 'search', '');
        $includeIds = Arr::get($params, 'include_ids', []);

        return $this->getCustomPostTypeOptions([
            'post_type'      => 'wp_payform',
            'search'         => $search,
            'include_ids'    => $includeIds
        ]);
    }

    public function getConfigFields()
    {
        return [
            'custom_rate_component' => [
                'type'           => 'custom_rate_component',
                'has_categories' => false,
                'has_products'   => true,
                'product_label'  => __('Paymattic Forms', 'fluent-affiliate'),
                'main_label'     => __('Enable custom rate for specific forms', 'fluent-affiliate'),
            ]
        ];
    }

}
