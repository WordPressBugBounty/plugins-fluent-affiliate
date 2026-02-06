<?php

namespace FluentAffiliate\App\Modules\Integrations;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\Framework\Support\Arr;

abstract class BaseConnectorSettings
{
    protected $integration = '';

    public function register()
    {
        add_filter('fluent_affiliate/get_integrations', [$this, 'pushIntegration']);

        add_filter('fluent_affiliate/get_integration_config_' . $this->integration, function ($config) {
            return [
                'config' => $this->config(),
                'fields' => $this->getConfigFields(),
            ];
        }, 10, 1);

        add_filter('fluent_affiliate/save_integration_config_' . $this->integration, function ($message, $config) {
            return $this->saveConfig($config);
        }, 10, 2);

        add_filter('fluent_affiliate/get_product_cat_options_' . $this->integration, [$this, 'getProductCatOptions'], 10, 2);
    }

    public function getProductCatOptions($options = [], $params = [])
    {
        return $options;
    }

    public function pushIntegration($allIntegrations = [])
    {
        $allIntegrations[$this->integration] = $this->getInfo();
        return $allIntegrations;
    }

    public function getInfo()
    {
        return [
            'integration'    => $this->integration,
            'title'          => '',
            'description'    => '',
            'type'           => '',
            'logo'           => '',
            'is_unavailable' => !$this->isAvailable(),
            'config'         => $this->config(),
        ];
    }

    public function config()
    {
        $defaults = [
            'status' => 'no',
        ];

        if (!$this->willConnectorRun()) {
            return $defaults;
        }

        $settings = Utility::getOption('_' . $this->integration . '_connector_config', []);

        return wp_parse_args($settings, $defaults);
    }

    abstract public function getConfigFields();

    public function saveConfig($config = [])
    {

        $config = wp_parse_args($config, $this->config());

        if (isset($config['custom_affiliate_rate'])) {
            if ($config['custom_affiliate_rate'] == 'yes') {
                $productIds = [];
                $catIds = [];

                foreach ($config['custom_affiliate_rates'] as $rate) {
                    if ($rate['object_type'] == 'product') {
                        $productIds = array_merge($productIds, Arr::get($rate, 'object_ids', []));
                    } else {
                        $catIds = array_merge($catIds, Arr::get($rate, 'object_ids', []));
                    }
                }
                $config['watched_product_ids'] = array_values(array_unique($productIds));
                $config['watched_cat_ids'] = array_values(array_unique($catIds));
            } else {
                $config['watched_product_ids'] = [];
                $config['watched_cat_ids'] = [];
            }
        }


        Utility::updateOption('_' . $this->integration . '_connector_config', $config);

        return __('Settings saved successfully.', 'fluent-affiliate');
    }

    public function isAvailable()
    {
        return false;
    }

    public function isActive()
    {
        return Utility::isConnectorEnabled($this->integration);
    }

    public function willConnectorRun()
    {
        return $this->isAvailable() && $this->isActive();
    }

    public function isRenewalEnabled()
    {
        return defined('FLUENT_AFFILIATE_PRO') && Utility::getReferralSetting('enable_subscription_renewal') === 'yes';
    }

    protected function getCustomPostTypeOptions($inputs = [])
    {
        $search = isset($inputs['search']) ? $inputs['search'] : '';
        $includeIds = isset($inputs['include_ids']) ? $inputs['include_ids'] : [];
        $postType = isset($inputs['post_type']) ? $inputs['post_type'] : '';

        if (empty($postType)) {
            return [];
        }

        $args = [
            'post_type'      => $postType,
            'posts_per_page' => 20,
            'post_status'    => 'any',
            's'              => $search
        ];

        $allProducts = get_posts($args);
        $products = [];

        $pushedIds = [];

        foreach ($allProducts as $product) {

            $pushedIds[] = $product->ID;

            $products[] = [
                'id'    => $product->ID,
                'label' => $product->post_title,
            ];
        }

        $leftIds = array_diff($includeIds, $pushedIds);

        if (!empty($leftIds)) {
            $additionalProducts = get_posts([
                'post_type'   => $postType,
                'post_status' => 'any',
                'post__in'    => $leftIds,

            ]);

            foreach ($additionalProducts as $product) {
                $products[] = [
                    'id'    => $product->ID,
                    'label' => $product->post_title,
                ];
            }
        }

        return $products;
    }

    protected function getPostTypeTerms($inputs = [])
    {
        $search = isset($inputs['search']) ? $inputs['search'] : '';
        $includeIds = isset($inputs['include_ids']) ? $inputs['include_ids'] : [];
        $taxonomy = isset($inputs['taxonomy']) ? $inputs['taxonomy'] : '';

        if (empty($taxonomy)) {
            return [];
        }


        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'name__like' => $search,
        ];

        $categories = get_terms($args);

        $pushedIds = [];

        $formattedCategories = [];

        foreach ($categories as $category) {
            $pushedIds[] = $category->term_id;
            $formattedCategories[] = [
                'id'    => $category->term_id,
                'label' => $category->name,
            ];
        }

        $leftIds = array_diff($includeIds, $pushedIds);

        if (!empty($leftIds)) {
            $additionalCategories = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'include'    => $leftIds,
            ]);

            foreach ($additionalCategories as $category) {
                $formattedCategories[] = [
                    'id'    => $category->term_id,
                    'label' => $category->name,
                ];
            }
        }

        return $formattedCategories;
    }

}
