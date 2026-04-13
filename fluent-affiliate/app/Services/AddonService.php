<?php

namespace FluentAffiliate\App\Services;

use FluentAffiliate\App\Helper\Utility;

/**
 * Shared addon installation service.
 *
 * This service is designed to be used from both the Settings "Features & Addons" tab
 * and a future Onboarding flow. It is intentionally context-free — it has no dependency
 * on any specific page or controller, so it can be called from either place without modification.
 *
 * Installation logic follows the same approach used in FluentCommunity's OnboardingService::installAddons().
 */
class AddonService
{
    /**
     * Get all defined addons with their current install/active status.
     *
     * @return array
     */
    public static function getAddons()
    {
        $addons = [
            'fluent_cart'    => [
                'title'        => __('FluentCart', 'fluent-affiliate'),
                'description'  => __('Sell digital and physical products in WordPress and track affiliate referrals for every sale automatically.', 'fluent-affiliate'),
                'logo'         => Utility::asset('images/integrations/fluentcart.svg'),
                'repo_slug'    => 'fluent-cart',
                'plugin_file'  => 'fluent-cart/fluent-cart.php',
                'is_repo'      => true,
                'is_installed' => defined('FLUENTCART_VERSION'),
                'settings_url' => admin_url('admin.php?page=fluent-cart#/'),
                'action_text'  => self::isPluginInstalled('fluent-cart/fluent-cart.php')
                    ? __('Activate FluentCart', 'fluent-affiliate')
                    : __('Install FluentCart', 'fluent-affiliate'),
            ],
            'fluentform'     => [
                'title'        => __('Fluent Forms', 'fluent-affiliate'),
                'description'  => __('Build any type of form, accept payments and subscriptions, and track affiliate referrals for every form submission.', 'fluent-affiliate'),
                'logo'         => Utility::asset('images/integrations/fluentforms.png'),
                'repo_slug'    => 'fluentform',
                'plugin_file'  => 'fluentform/fluentform.php',
                'is_repo'      => true,
                'is_installed' => defined('FLUENTFORM'),
                'settings_url' => admin_url('admin.php?page=fluent_forms'),
                'action_text'  => self::isPluginInstalled('fluentform/fluentform.php')
                    ? __('Activate Fluent Forms', 'fluent-affiliate')
                    : __('Install Fluent Forms', 'fluent-affiliate'),
            ],
            'fluent_booking' => [
                'title'        => __('FluentBooking', 'fluent-affiliate'),
                'description'  => __('Appointment scheduling and booking solution. Reward affiliates for every booking made through their referral links.', 'fluent-affiliate'),
                'logo'         => Utility::asset('images/integrations/fluentbooking.svg'),
                'repo_slug'    => 'fluent-booking',
                'plugin_file'  => 'fluent-booking/fluent-booking.php',
                'is_repo'      => true,
                'is_installed' => defined('FLUENT_BOOKING_DIR'),
                'settings_url' => admin_url('admin.php?page=fluent-booking#/'),
                'action_text'  => self::isPluginInstalled('fluent-booking/fluent-booking.php')
                    ? __('Activate FluentBooking', 'fluent-affiliate')
                    : __('Install FluentBooking', 'fluent-affiliate'),
            ],
            'fluent_crm'     => [
                'title'        => __('FluentCRM', 'fluent-affiliate'),
                'description'  => __('The Best Email Marketing Automation Plugin for WordPress. Capture, Segment and Automate your Marketing.', 'fluent-affiliate'),
                'logo'         => Utility::asset('images/integrations/fluentcrm.svg'),
                'repo_slug'    => 'fluent-crm',
                'plugin_file'  => 'fluent-crm/fluent-crm.php',
                'is_repo'      => true,
                'is_installed' => defined('FLUENTCRM'),
                'settings_url' => admin_url('admin.php?page=fluentcrm#/'),
                'action_text'  => self::isPluginInstalled('fluent-crm/fluent-crm.php')
                    ? __('Activate FluentCRM', 'fluent-affiliate')
                    : __('Install FluentCRM', 'fluent-affiliate'),
            ],
            'fluent_smtp'    => [
                'title'        => __('Fluent SMTP', 'fluent-affiliate'),
                'description'  => __('The ultimate SMTP and SES plugin for WordPress. Connect with any SMTP service including SendGrid, Mailgun, SES, and more.', 'fluent-affiliate'),
                'logo'         => Utility::asset('images/integrations/fluentsmtp.svg'),
                'repo_slug'    => 'fluent-smtp',
                'plugin_file'  => 'fluent-smtp/fluent-smtp.php',
                'is_repo'      => true,
                'is_installed' => defined('FLUENTMAIL'),
                'settings_url' => admin_url('options-general.php?page=fluent-mail#/'),
                'action_text'  => self::isPluginInstalled('fluent-smtp/fluent-smtp.php')
                    ? __('Activate Fluent SMTP', 'fluent-affiliate')
                    : __('Install Fluent SMTP', 'fluent-affiliate'),
            ],
            'paymattic'      => [
                'title'        => __('Paymattic', 'fluent-affiliate'),
                'description'  => __('Accept payments, donations, and subscriptions with ease. Track affiliate commissions on every payment form submission.', 'fluent-affiliate'),
                'logo'         => Utility::asset('images/integrations/paymattic.svg'),
                'repo_slug'    => 'wp-payment-form',
                'plugin_file'  => 'wp-payment-form/wp-payment-form.php',
                'is_repo'      => true,
                'is_installed' => defined('WPPAYFORM_VERSION'),
                'settings_url' => admin_url('admin.php?page=wppayform.php#/'),
                'action_text'  => self::isPluginInstalled('wp-payment-form/wp-payment-form.php')
                    ? __('Activate Paymattic', 'fluent-affiliate')
                    : __('Install Paymattic', 'fluent-affiliate'),
            ]
        ];

        return $addons;
    }

    /**
     * Check if a plugin file exists on disk (installed but possibly not active).
     *
     * @param string $plugin Plugin file path relative to plugins directory.
     * @return bool
     */
    private static function isPluginInstalled($plugin)
    {
        return file_exists(WP_PLUGIN_DIR . '/' . $plugin);
    }

    /**
     * Check if a specific addon plugin is installed and active.
     *
     * @param string $addonKey
     * @return bool
     */
    public static function isAddonActive($addonKey)
    {
        $checks = [
            'fluent_cart'    => 'FLUENTCART_VERSION',
            'fluent_booking' => 'FLUENT_BOOKING_DIR',
            'paymattic'      => 'WPPAYFORM_VERSION',
            'fluentform'     => 'FLUENTFORM',
            'fluent_smtp'    => 'FLUENTMAIL',
            'fluent_crm'     => 'FLUENTCRM',
        ];

        if (!isset($checks[$addonKey])) {
            return false;
        }

        return defined($checks[$addonKey]);
    }

    /**
     * Install one or more addons by key.
     *
     * @param array $addonKeys e.g. ['fluent_cart', 'paymattic']
     * @return array Results per addon key
     */
    public static function installAddons(array $addonKeys)
    {
        if (!current_user_can('install_plugins')) {
            return [
                'success' => false,
                'message' => __('You do not have permission to install plugins.', 'fluent-affiliate'),
            ];
        }

        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS) {
            return [
                'success' => false,
                'message' => __('Plugin installation is disabled on this site.', 'fluent-affiliate'),
            ];
        }

        $addons = self::getAddons();
        $results = [];

        foreach ($addonKeys as $key) {
            if (!isset($addons[$key])) {
                $results[$key] = [
                    'success' => false,
                    'message' => __('Unknown addon.', 'fluent-affiliate'),
                ];
                continue;
            }

            $addon = $addons[$key];

            if (!$addon['is_repo']) {
                $results[$key] = [
                    'success' => false,
                    'message' => __('This addon cannot be installed automatically.', 'fluent-affiliate'),
                ];
                continue;
            }

            try {
                self::backgroundInstaller([
                    'name'      => $addon['repo_slug'],
                    'repo-slug' => $addon['repo_slug'],
                    'file'      => basename($addon['plugin_file']),
                ]);

                $results[$key] = [
                    'success' => true,
                    'message' => __('Plugin installed and activated successfully.', 'fluent-affiliate'),
                ];
            } catch (\Exception $e) {
                $results[$key] = [
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Install and activate a plugin from the WordPress.org repository.
     *
     * Follows the same approach as FluentCommunity's OnboardingService::backgroundInstaller().
     *
     * @param array $plugin_to_install Plugin descriptor with 'repo-slug' and 'file' keys.
     * @throws \Exception On installation or activation failure.
     */
    private static function backgroundInstaller($plugin_to_install)
    {
        if (empty($plugin_to_install['repo-slug'])) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        WP_Filesystem();

        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \WP_Upgrader($skin);
        $installed_plugins = array_keys(\get_plugins());
        $plugin_slug = $plugin_to_install['repo-slug'];
        $plugin_file = isset($plugin_to_install['file']) ? $plugin_to_install['file'] : $plugin_slug . '.php';
        $installed = false;
        $activate = false;

        // Check if plugin is already installed.
        if (in_array($plugin_slug . '/' . $plugin_file, $installed_plugins)) {
            $installed = true;
            $activate = !is_plugin_active($plugin_slug . '/' . $plugin_file);
        }

        // Install if not already present.
        if (!$installed) {
            ob_start();

            try {
                $plugin_information = plugins_api(
                    'plugin_information',
                    [
                        'slug'   => $plugin_slug,
                        'fields' => [
                            'short_description' => false,
                            'sections'          => false,
                            'requires'          => false,
                            'rating'            => false,
                            'ratings'           => false,
                            'downloaded'        => false,
                            'last_updated'      => false,
                            'added'             => false,
                            'tags'              => false,
                            'homepage'          => false,
                            'donate_link'       => false,
                            'author_profile'    => false,
                            'author'            => false,
                        ],
                    ]
                );

                if (is_wp_error($plugin_information)) {
                    throw new \Exception(wp_kses_post($plugin_information->get_error_message()));
                }

                $package = $plugin_information->download_link;
                $download = $upgrader->download_package($package);

                if (is_wp_error($download)) {
                    throw new \Exception(wp_kses_post($download->get_error_message()));
                }

                $working_dir = $upgrader->unpack_package($download, true);

                if (is_wp_error($working_dir)) {
                    throw new \Exception(wp_kses_post($working_dir->get_error_message()));
                }

                $result = $upgrader->install_package(
                    [
                        'source'                      => $working_dir,
                        'destination'                 => WP_PLUGIN_DIR,
                        'clear_destination'           => false,
                        'abort_if_destination_exists' => false,
                        'clear_working'               => true,
                        'hook_extra'                  => [
                            'type'   => 'plugin',
                            'action' => 'install',
                        ],
                    ]
                );

                if (is_wp_error($result)) {
                    throw new \Exception(wp_kses_post($result->get_error_message()));
                }

                $activate = true;

            } catch (\Exception $e) {
                ob_end_clean();
                throw new \Exception(esc_html($e->getMessage()));
            }

            ob_end_clean();
        }

        wp_clean_plugins_cache();

        // Activate the plugin.
        if ($activate) {
            $result = activate_plugin($plugin_slug . '/' . $plugin_file);

            if (is_wp_error($result)) {
                throw new \Exception(esc_html($result->get_error_message()));
            }
        }
    }
}
