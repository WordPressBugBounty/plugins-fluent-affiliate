<?php

namespace FluentAffiliate\App\Modules\Integrations\FluentCart;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Modules\Integrations\BaseConnectorSettings;
use FluentAffiliate\Framework\Support\Arr;

class Connector extends BaseConnectorSettings
{
    protected $integration = 'fluent_cart';

    public function init()
    {
        $this->register();
    }

    public function isAvailable()
    {
        return defined('\FLUENTCART_VERSION');
    }

    public function getInfo()
    {
        return [
            'integration'    => $this->integration,
            'title'          => 'FluentCart',
            'description'    => 'Connect FluentAffiliate with FluentCart to track sales and commissions',
            'type'           => 'commerce',
            'logo'           => Utility::asset('images/integrations/fluentcart.svg'),
            'is_unavailable' => !$this->isAvailable(),
            'config'         => $this->config(),
        ];
    }

    public function config()
    {
        $defaults = [
            'is_enabled'                    => 'no',
            'affiliate_on_discount_product' => 'no',
            'disable_on_upgrades'           => 'yes',
            'custom_affiliate_rate'         => 'no',
            'custom_affiliate_rates'        => [],
            'enable_subscription_renewal'   => 'no',
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

        if (Arr::get($params, 'object_type') === 'product') {
            return $this->getCustomPostTypeOptions([
                'search'      => $search,
                'include_ids' => $includeIds,
                'post_type'   => 'fluent-products',
            ]);
        }

        return $this->getPostTypeTerms([
            'search'      => $search,
            'include_ids' => $includeIds,
            'taxonomy'    => 'product-categories'
        ]);
    }

    public function getConfigFields()
    {
        return [
            'affiliate_on_discount_product' => [
                'type'           => 'inline_checkbox',
                'checkbox_label' => __('Enable Branded Coupon Codes for affiliates', 'fluent-affiliate'),
                'help_text'      => __('When enabled, you can offer branded coupon codes for affiliates. This allows them to promote products with unique discount codes. You can find the option in Discount Codes editor in FluentCart', 'fluent-affiliate'),
                'true_label'     => 'yes',
                'false_label'    => 'no',
            ],
            'disable_on_upgrades'           => [
                'type'           => 'inline_checkbox',
                'checkbox_label' => __('Disable Referrals on Upgrades', 'fluent-affiliate'),
                'help_text'      => __('When enabled, No referrals will be added on purchased upgrades', 'fluent-affiliate'),
                'true_label'     => 'yes',
                'false_label'    => 'no',
            ],
            'custom_rate_component'         => [
                'type'           => 'custom_rate_component',
                'has_categories' => true,
                'has_products'   => true,
                'main_label'     => __('Enable custom rate for specific products or categories', 'fluent-affiliate'),
            ],
            'enable_subscription_renewal'  => [
                'type'           => 'inline_checkbox',
                'checkbox_label' => __('Enable Referrals on Subscription Renewals', 'fluent-affiliate'),
                'help_text'      => __('When enabled, referrals will be created for subscription renewal orders', 'fluent-affiliate'),
                'hidden'         => $this->isRenewalEnabled() ? 'no' : 'yes',
                'true_label'     => 'yes',
                'false_label'    => 'no',
            ]
        ];
    }

}
