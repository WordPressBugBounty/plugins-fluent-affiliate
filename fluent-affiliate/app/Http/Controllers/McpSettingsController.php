<?php

namespace FluentAffiliate\App\Http\Controllers;

use FluentAffiliate\App\Modules\MCP\AbilitiesRegistrar;
use FluentAffiliate\App\Modules\MCP\McpGate;
use FluentAffiliate\App\Modules\MCP\MCPInit;
use FluentAffiliate\Framework\Http\Request\Request;
use FluentAffiliate\Framework\Support\Arr;

/**
 * The "MCP for AI Agents" settings screen. Read by users with Manage All Data
 * (AdminPolicy); the switch and FluentHub's turn-on change who can reach the
 * site's data, so they need a site administrator.
 */
class McpSettingsController extends Controller
{
    const ADAPTER_PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';

    const TOOLKIT_PLUGIN_FILE = 'fluent-toolkit/fluent-toolkit.php';

    /** Where a person downloads FluentHub by hand. */
    const TOOLKIT_DOWNLOAD_URL = 'https://github.com/WPManageNinja/fluent-toolkit';

    public function get(Request $request)
    {
        return $this->screenData();
    }

    public function save(Request $request)
    {
        if (!current_user_can('manage_options')) {
            return $this->sendError([
                'message' => __('Only a site administrator can turn MCP on or off.', 'fluent-affiliate'),
            ], 403);
        }

        $settings = (array) $request->get('settings', []);

        $this->validate($settings, [
            'enabled' => 'required|in:yes,no',
        ]);

        McpGate::setEnabled($settings['enabled'] === 'yes');

        return array_merge($this->screenData(), [
            'message' => $settings['enabled'] === 'yes'
                ? __('MCP is on. AI agents can now connect.', 'fluent-affiliate')
                : __('MCP is off. AI agents can no longer connect.', 'fluent-affiliate'),
        ]);
    }

    /**
     * The one-click "Install FluentHub" / "Activate FluentHub" button.
     *
     * This plugin never downloads, installs or activates a plugin itself. It
     * asks the shared fluent_toolkit/* hooks, which an active Fluent Pro plugin
     * answers (FluentAffiliate Pro's ToolkitInstaller among them). Without one,
     * the screen shows the manual download link.
     */
    public function installToolkit(Request $request)
    {
        if (!current_user_can('install_plugins')) {
            return $this->sendError([
                'message' => __('Sorry, you do not have permission to install plugins.', 'fluent-affiliate'),
            ], 403);
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's shared hook
        if (!apply_filters('fluent_toolkit/can_auto_install', false)) {
            return $this->sendError([
                'message'              => __('Automatic install needs an active Fluent Pro plugin. Install and activate FluentHub, then reload this page.', 'fluent-affiliate'),
                'toolkit_download_url' => self::TOOLKIT_DOWNLOAD_URL,
            ], 422);
        }

        // the installer says why it failed through this action
        $failure = null;
        $onFailure = function ($error) use (&$failure) {
            $failure = $error;
        };
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's shared hook
        add_action('fluent_toolkit/auto_install_failed', $onFailure);

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's shared hook
        do_action('fluent_toolkit/do_auto_install');

        remove_action('fluent_toolkit/auto_install_failed', $onFailure);
        wp_clean_plugins_cache();

        $status = $this->adapterStatus();

        // a reported failure wins: FluentHub can be active and still lack a working MCP adapter
        if (is_wp_error($failure) || !$status['toolkit_installed'] || !$status['toolkit_active']) {
            $reason = is_wp_error($failure) ? wp_strip_all_tags($failure->get_error_message()) : '';

            return $this->sendError([
                'message'              => trim(__('FluentHub could not be installed or activated automatically.', 'fluent-affiliate') . ' ' . $reason . ' ' . __('Install and activate it by hand, then reload this page.', 'fluent-affiliate')),
                'toolkit_download_url' => self::TOOLKIT_DOWNLOAD_URL,
            ], 422);
        }

        return array_merge($this->screenData(), [
            'message' => __('FluentHub is installed and active. Reload the page if the MCP status does not update.', 'fluent-affiliate'),
        ]);
    }

    /**
     * Turns on the FluentHub MCP server (target "hub") or the MCP of another
     * Fluent plugin on the site (target = its slug), through FluentHub's own
     * call, which checks manage_options and runs that plugin's own switch.
     * Nothing can be turned off from this screen.
     */
    public function enableHub(Request $request)
    {
        if (!current_user_can('manage_options')) {
            return $this->sendError([
                'message' => __('Only a site administrator can turn this on.', 'fluent-affiliate'),
            ], 403);
        }

        $target = sanitize_key((string) $request->get('target', 'hub'));
        if ($target === '') {
            $target = 'hub';
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's hook
        $result = apply_filters('fluent_kit/mcp_hub_enable', null, MCPInit::SERVER_ID, $target);
        $status = is_array($result) ? Arr::get($result, 'status') : null;
        $reason = is_array($result) ? (string) Arr::get($result, 'reason') : '';

        if ($status === 'done') {
            return array_merge($this->screenData(), [
                'message' => $target === 'hub'
                    ? __('The FluentHub MCP server is on.', 'fluent-affiliate')
                    : __('On. Your AI app sees it the next time it loads its tools.', 'fluent-affiliate'),
            ]);
        }

        if ($status === 'forbidden') {
            $refusal = $reason === 'product_refused' ? sanitize_text_field((string) Arr::get($result, 'message', '')) : '';

            return $this->sendError([
                'message' => $refusal ? $refusal : __('Only a site administrator can turn this on.', 'fluent-affiliate'),
            ], 403);
        }

        $messages = [
            'adapter_missing'  => __('FluentHub needs a newer MCP adapter before this can be turned on.', 'fluent-affiliate'),
            'adapter_outdated' => __('FluentHub needs a newer MCP adapter before this can be turned on.', 'fluent-affiliate'),
            'unknown_product'  => __('FluentHub does not know this plugin.', 'fluent-affiliate'),
            'not_active'       => __('This plugin is not active on the site.', 'fluent-affiliate'),
            'no_handler'       => __('This plugin cannot be turned on from here. Use its own MCP settings.', 'fluent-affiliate'),
        ];

        return $this->sendError([
            'message' => isset($messages[$reason]) ? $messages[$reason] : __('FluentHub is not active on this site.', 'fluent-affiliate'),
        ], 422);
    }

    private function screenData()
    {
        $definitions = AbilitiesRegistrar::getDefinitions();
        $proCount = 0;
        foreach ($definitions as $definition) {
            if (!empty($definition['pro'])) {
                ++$proCount;
            }
        }

        $user = wp_get_current_user();

        return [
            'settings'           => McpGate::settings(),
            'endpoint_url'       => MCPInit::endpointUrl(),
            'server_name'        => MCPInit::clientServerName(),
            'adapter'            => $this->adapterStatus(),
            'hub'                => static::hubState(),
            'tools'              => [
                'total' => count($definitions),
                'pro'   => $proCount,
            ],
            'can_manage'         => current_user_can('manage_options'),
            'user_can_connect'   => McpGate::userMayConnect($user),
            'app_passwords_url'  => admin_url('profile.php#application-passwords-section'),
            'plugins_url'        => admin_url('plugins.php'),
            'current_user_login' => $user ? $user->user_login : '',
            'is_local_dev'       => $this->isLocalDev(),
        ];
    }

    /**
     * FluentHub's one-URL server and its sign-in, as FluentHub reports them
     * (fluent-toolkit docs/mcp-product-connect.md). FluentHub supplies the
     * per-app instructions too, so both screens use one wording.
     *
     * @param array|null $state For tests: a state in place of the filter's answer.
     * @return array|null Null when FluentHub is not active or speaks another contract version.
     */
    public static function hubState($state = null)
    {
        if ($state === null) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's hook
            $state = apply_filters('fluent_kit/mcp_hub_state', null, MCPInit::SERVER_ID);
        }

        if (!is_array($state) || (int) Arr::get($state, 'version') !== 1 || empty($state['hub']) || empty($state['sign_in']) || empty($state['product'])) {
            return null;
        }

        $clients = [];
        foreach ((array) Arr::get($state, 'clients', []) as $client) {
            if (!is_array($client) || empty($client['key']) || empty($client['snippet'])) {
                continue;
            }

            $clients[] = [
                'key'     => sanitize_key($client['key']),
                'label'   => sanitize_text_field((string) Arr::get($client, 'label', $client['key'])),
                'note'    => sanitize_text_field((string) Arr::get($client, 'note', '')),
                'snippet' => (string) $client['snippet'],
            ];
        }

        $products = [];
        foreach ((array) Arr::get($state, 'products', []) as $product) {
            if (!is_array($product) || empty($product['slug'])) {
                continue;
            }

            $products[] = [
                'slug'        => sanitize_key($product['slug']),
                'name'        => sanitize_text_field((string) Arr::get($product, 'name', $product['slug'])),
                'served'      => !empty($product['served']),
                'can_enable'  => !empty($product['can_enable']),
                'tools_count' => (int) Arr::get($product, 'tools_count', 0),
            ];
        }

        return [
            'products'         => $products,
            'ready'            => !empty($state['ready']) && $clients,
            'endpoint_url'     => esc_url_raw((string) Arr::get($state, 'hub.endpoint_url', '')),
            'hub_reason'       => sanitize_key((string) Arr::get($state, 'hub.reason', '')),
            'sign_in_reason'   => sanitize_key((string) Arr::get($state, 'sign_in.reason', '')),
            'included'         => !empty($state['product']['included']),
            'user_can_connect' => !empty($state['user_can_connect']),
            'can_manage'       => !empty($state['can_manage']),
            'manage_url'       => esc_url_raw((string) Arr::get($state, 'manage_url', '')),
            'profile_url'      => esc_url_raw((string) Arr::get($state, 'profile_url', '')),
            'clients'          => $clients,
        ];
    }

    /**
     * @return array active, provider (toolkit|plugin|other|''), version, installed, abilities_api, download_url
     */
    private function adapterStatus()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $toolkitLoaded = defined('FLUENT_TOOLKIT_VERSION');
        $active = MCPInit::hasAdapter();

        $provider = '';
        $version = null;
        if ($active && is_plugin_active(self::ADAPTER_PLUGIN_FILE)) {
            $provider = 'plugin';
            $version = isset($plugins[self::ADAPTER_PLUGIN_FILE]['Version']) ? $plugins[self::ADAPTER_PLUGIN_FILE]['Version'] : null;
        } elseif ($active && $toolkitLoaded) {
            $provider = 'toolkit';
            $version = (string) constant('FLUENT_TOOLKIT_VERSION');
        } elseif ($active) {
            $provider = 'other';
            $version = MCPInit::adapterVersion();
        }

        return [
            'installed'            => $toolkitLoaded || isset($plugins[self::TOOLKIT_PLUGIN_FILE]) || isset($plugins[self::ADAPTER_PLUGIN_FILE]),
            'active'               => $active,
            'provider'             => $provider,
            'version'              => $version,
            'abilities_api'        => function_exists('wp_register_ability'),
            'toolkit_installed'    => $toolkitLoaded || isset($plugins[self::TOOLKIT_PLUGIN_FILE]),
            'toolkit_active'       => $toolkitLoaded || is_plugin_active(self::TOOLKIT_PLUGIN_FILE),
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's shared hook
            'can_auto_install'     => current_user_can('install_plugins') && (bool) apply_filters('fluent_toolkit/can_auto_install', false),
            'toolkit_download_url' => self::TOOLKIT_DOWNLOAD_URL,
            'download_url'         => self::TOOLKIT_DOWNLOAD_URL,
        ];
    }

    /**
     * A local site usually has a self-signed certificate, which the Claude
     * Desktop proxy refuses unless the snippet says otherwise.
     */
    private function isLocalDev()
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        foreach (['.test', '.lab', '.local', '.localhost', '.docker'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        return (bool) filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
