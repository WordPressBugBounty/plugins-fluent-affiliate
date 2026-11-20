<?php

namespace FluentAffiliate\App\Modules\MCP;

use FluentAffiliate\App\Services\PermissionManager;

/**
 * Who may use the FluentAffiliate MCP server, and the switch that turns it on.
 *
 * One rule for the endpoint, the FluentHub one-URL sign-in and the tool list:
 * the MCP switch is on, and the user is a site administrator or an affiliate
 * manager with at least one FluentAffiliate permission (Pro's Managers screen).
 * Each tool then checks its own permission through PermissionManager.
 *
 * Affiliates themselves (portal users) are not admitted.
 */
class McpGate
{
    const OPTION = '_fa_mcp_settings';

    /**
     * Tool requirement => the PermissionManager check for the current user.
     */
    const REQUIREMENTS = [
        'any',
        'affiliate_read',
        'affiliate_write',
        'referral_read',
        'referral_write',
        'visit_read',
        'payout_read',
        'payout_write',
    ];

    public static function settings()
    {
        $settings = get_option(self::OPTION, []);

        return wp_parse_args(is_array($settings) ? $settings : [], [
            'enabled' => 'no',
        ]);
    }

    public static function isEnabled()
    {
        return self::settings()['enabled'] === 'yes';
    }

    /**
     * The switch. Off by default. The caller checks who may change it: the
     * settings route (site administrators) or FluentHub's toggle (manage_options).
     *
     * @param bool $enabled
     * @return bool The new state.
     */
    public static function setEnabled($enabled)
    {
        $settings = self::settings();
        $settings['enabled'] = $enabled ? 'yes' : 'no';
        update_option(self::OPTION, $settings, true);

        return (bool) $enabled;
    }

    /**
     * Whether a user may connect: a site administrator, or a user with at
     * least one FluentAffiliate permission. Asked about any user, so FluentHub
     * can ask before that user is the current one.
     *
     * @param \WP_User|int|null $user Null asks about the current user.
     * @return bool
     */
    public static function userMayConnect($user = null)
    {
        if ($user === null) {
            $user = wp_get_current_user();
        } elseif (is_numeric($user)) {
            $user = get_user_by('ID', (int) $user);
        }

        if (!$user instanceof \WP_User || !$user->exists()) {
            return false;
        }

        if (user_can($user, 'manage_options')) {
            return true;
        }

        return (bool) array_intersect(
            (array) PermissionManager::getUserPermissions($user),
            array_keys(PermissionManager::allPermissionSets())
        );
    }

    /**
     * The transport gate of create_server(): runs on every MCP request.
     *
     * @param mixed $request The adapter passes the request.
     * @return true|\WP_Error
     */
    public static function transport($request = null) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the adapter passes the request
    {
        if (!self::isEnabled()) {
            return new \WP_Error('fluent_affiliate_mcp_disabled', 'The FluentAffiliate MCP server is switched off.', ['status' => 403]);
        }

        if (!is_user_logged_in()) {
            return new \WP_Error('fluent_affiliate_mcp_unauthorized', 'Authentication is required to use the FluentAffiliate MCP server.', ['status' => 401]);
        }

        if (!self::userMayConnect()) {
            return new \WP_Error('fluent_affiliate_mcp_forbidden', 'Only site administrators and affiliate managers can use the FluentAffiliate MCP server.', ['status' => 403]);
        }

        return true;
    }

    /**
     * Whether the current user meets a tool's requirement. Every check is the
     * one the admin REST routes use, so a tool reaches what the screen reaches.
     *
     * @param string $requirement One of self::REQUIREMENTS.
     * @return bool
     */
    public static function can($requirement)
    {
        switch ($requirement) {
            case 'affiliate_read':
                return PermissionManager::hasAffiliateAccess(true);
            case 'affiliate_write':
                return PermissionManager::hasAffiliateAccess();
            case 'referral_read':
                return PermissionManager::hasReferralAccess(true);
            case 'referral_write':
                return PermissionManager::hasReferralAccess();
            case 'visit_read':
                return PermissionManager::hasVisitAccess();
            case 'payout_read':
                return PermissionManager::hasPayoutAccess(true);
            case 'payout_write':
                return PermissionManager::hasPayoutAccess();
            case 'any':
                return PermissionManager::hasAnyPermission();
        }

        return false;
    }

    /**
     * The sentence a refused tool call returns.
     *
     * @param string $requirement
     * @return string
     */
    public static function refusal($requirement)
    {
        $needs = [
            'affiliate_read'  => 'read access to all affiliates',
            'affiliate_write' => 'read and write access to all affiliates',
            'referral_read'   => 'read access to all referrals',
            'referral_write'  => 'read and write access to all referrals',
            'visit_read'      => 'read access to all visits',
            'payout_read'     => 'read access to all payouts',
            'payout_write'    => 'read and write access to all payouts',
            'any'             => 'a FluentAffiliate permission',
        ];

        return 'Your account cannot use this tool: it needs ' . (isset($needs[$requirement]) ? $needs[$requirement] : 'another permission')
            . '. A site administrator gives managers their permissions in FluentAffiliate under Settings > Permission Management.';
    }
}
