<?php

namespace FluentAffiliate\App\Modules\Integrations;

use FluentAffiliate\App\Helper\Utility;

class CoreIntegrationsInit
{
    public function register()
    {
        if (defined('FLUENTCART_VERSION')) {
            (new \FluentAffiliate\App\Modules\Integrations\FluentCart\Bootstrap())->register();
        }

        if (defined('FLUENT_BOOKING_PRO_VERSION')) {
            (new \FluentAffiliate\App\Modules\Integrations\FluentBooking\Bootstrap())->register();
        }

        if (defined('FLUENTFORM_VERSION')) {
            (new \FluentAffiliate\App\Modules\Integrations\FluentForms\Bootstrap())->register();
        }

        if (defined('WPPAYFORM_VERSION')) {
            (new \FluentAffiliate\App\Modules\Integrations\Paymattic\Bootstrap())->register();
        }

        $this->registerConnectors();
    }

    private function registerConnectors()
    {
        (new \FluentAffiliate\App\Modules\Integrations\FluentCart\Connector())->init();
        (new \FluentAffiliate\App\Modules\Integrations\FluentBooking\Connector())->init();

        (new \FluentAffiliate\App\Modules\Integrations\FluentForms\Connector())->init();
        (new \FluentAffiliate\App\Modules\Integrations\Paymattic\Connector())->init();

        // add pro integrations here
        add_filter('fluent_affiliate/get_integrations', function ($allIntegrations) {

            if (!defined('FLUENT_AFFILIATE_PRO')) {
                $allIntegrations['woo'] = [
                    'integration'             => 'woo',
                    'title'                   => 'WooCommerce',
                    'description'             => 'Connect FluentAffiliate with WooCommerce to track sales and commissions',
                    'type'                    => 'commerce',
                    'logo'                    => Utility::asset('images/integrations/woocommerce.svg'),
                    'is_unavailable'          => true,
                    'locked'                  => true,
                    'config'                  => [],
                    'disable_advanced_config' => true,
                ];

                $allIntegrations['edd'] = [
                    'integration'             => 'edd',
                    'title'                   => 'Easy Digital Downloads',
                    'description'             => 'Connect FluentAffiliate with EDD to track sales and commissions',
                    'type'                    => 'commerce',
                    'logo'                    => Utility::asset('images/integrations/edd.svg'),
                    'is_unavailable'          => true,
                    'locked'                  => true,
                    'config'                  => [],
                    'disable_advanced_config' => true,
                ];

                $allIntegrations['surecart'] = [
                    'integration'             => 'surecart',
                    'title'                   => 'SureCart',
                    'description'             => 'Connect Fluent Affiliate with SureCart to track sales and commissions',
                    'type'                    => 'commerce',
                    'logo'                    => Utility::asset('images/integrations/surecart.svg'),
                    'is_unavailable'          => true,
                    'locked'                  => true,
                    'config'                  => [],
                    'disable_advanced_config' => true,
                ];

                $allIntegrations['give'] = [
                    'integration'             => 'giv',
                    'title'                   => 'GiveWP',
                    'description'             => 'Connect FluentAffiliate with GiveWP to track donations and commissions',
                    'type'                    => 'commerce',
                    'logo'                    => Utility::asset('images/integrations/give.svg'),
                    'is_unavailable'          => true,
                    'locked'                  => true,
                    'config'                  => [],
                    'disable_advanced_config' => true,
                ];

                $allIntegrations['lifter'] = [
                    'integration'             => 'lifter',
                    'title'                   => 'LifterLMS',
                    'description'             => 'Connect FluentAffiliate with LifterLMS Courses to track sales and commissions',
                    'type'                    => 'lms',
                    'logo'                    => Utility::asset('images/integrations/lifterlms.png'),
                    'is_unavailable'          => true,
                    'locked'                  => true,
                    'config'                  => [],
                    'disable_advanced_config' => true,
                ];

                $allIntegrations['memberpress'] = [
                    'integration'             => 'memberpress',
                    'title'                   => 'MemberPress',
                    'description'             => 'Connect FluentAffiliate with MemberPress Membership levels to track sales and commissions',
                    'type'                    => 'membership',
                    'logo'                    => Utility::asset('images/integrations/memberpress.png'),
                    'is_unavailable'          => true,
                    'locked'                  => true,
                    'config'                  => [],
                    'disable_advanced_config' => true,
                ];

                $allIntegrations['pmp'] = [
                    'integration'             => 'pmp',
                    'title'                   => 'Paid Memberships Pro',
                    'description'             => 'Connect FluentAffiliate with Paid Memberships Pro Membership levels to track sales and commissions',
                    'type'                    => 'membership',
                    'logo'                    => Utility::asset('images/integrations/pmp.png'),
                    'is_unavailable'          => true,
                    'locked'                  => true,
                    'config'                  => [],
                    'disable_advanced_config' => true,
                ];

                $allIntegrations['voxel'] = [
                    'integration'             => 'voxel',
                    'title'                   => 'Voxel',
                    'description'             => 'Connect FluentAffiliate with Voxel Ecommerce to track sales and commissions',
                    'type'                    => 'commerce',
                    'logo'                    => Utility::asset('images/integrations/voxel.svg'),
                    'is_unavailable'          => true,
                    'locked'                  => true,
                    'config'                  => [],
                    'disable_advanced_config' => true,
                ];

                $allIntegrations['tutorlms'] = [
                    'integration'             => 'tutorlms',
                    'title'                   => 'TutorLMS',
                    'description'             => 'Connect FluentAffiliate with Tutor LMS Courses to track sales and commissions',
                    'type'                    => 'lms',
                    'logo'                    => Utility::asset('images/integrations/tutor.svg'),
                    'is_unavailable'          => true,
                    'config'                  => [],
                    'disable_advanced_config' => true,
                ];

                $allIntegrations['profilepress'] = [
                    'integration'             => 'profilepress',
                    'title'                   => 'ProfilePress',
                    'description'             => 'Connect FluentAffiliate with ProfilePress Memberships to track sales and commissions',
                    'type'                    => 'membership',
                    'logo'                    => Utility::asset('images/integrations/profilepress.png'),
                    'is_unavailable'          => true,
                    'config'                  => [],
                    'disable_advanced_config' => true,
                ];
            }

//            $allIntegrations['learndash'] = [
//                'integration'             => 'learndash',
//                'title'                   => 'LearnDash',
//                'description'             => 'Connect FluentAffiliate with Learndash Courses to track sales and commissions',
//                'type'                    => 'lms',
//                'logo'                    => Utility::asset('images/integrations/learndash.svg'),
//                'is_unavailable'          => true,
//                'locked'                  => true,
//                'coming_soon'             => true,
//                'config'                  => [],
//                'disable_advanced_config' => true,
//            ];

            return $allIntegrations;
        });

    }
}
