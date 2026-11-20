<?php

namespace FluentAffiliate\App\Modules\MCP;

use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Modules\MCP\Tools\AffiliateTools;
use FluentAffiliate\App\Modules\MCP\Tools\ContextTools;
use FluentAffiliate\App\Modules\MCP\Tools\PayoutTools;
use FluentAffiliate\App\Modules\MCP\Tools\ReferralTools;
use FluentAffiliate\App\Modules\MCP\Tools\ReportTools;
use FluentAffiliate\App\Modules\MCP\Tools\VisitTools;

/**
 * The FluentAffiliate MCP tools.
 *
 * Labels, descriptions, schema text and errors are plain English with no
 * translation call: an agent reads them, and a translated description makes the
 * tool worse on a non-English site (Fluent MCP standard, rule 6). The first
 * sentence of each description is complete within 160 characters; rules shared
 * by several tools live in MCPInit::serverInstructions().
 *
 * Definition keys beyond the Abilities API ones:
 *   requires  one of McpGate::REQUIREMENTS. Asked with no input, so the tool
 *             list can be cut per user (MCPInit::listToolsForUser()).
 *   pro       true for a tool FluentAffiliate Pro adds.
 *
 * Pro adds tools with the filter fluent_affiliate/mcp_tool_definitions; the
 * final list of names passes fluent_affiliate/mcp_ability_names.
 */
class AbilitiesRegistrar
{
    const CATEGORY = 'fluent-affiliate';

    private static $definitions = null;

    /** For tests: build the definitions again. */
    public static function reset()
    {
        self::$definitions = null;
    }

    public static function getDefinitions()
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $readOnly = ['readonly' => true, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false];

        $page = ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number, from 1.'];
        $perPage = ['type' => 'integer', 'minimum' => 1, 'description' => 'Rows per page. Default 20, max 100.'];
        $sortDir = ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'description' => 'Default DESC.'];
        $confirm = ['type' => 'string', 'description' => 'Leave it out to get a preview and a confirm value. Send that value back only after the person agreed to the preview.'];
        $hasPro = defined('FLUENT_AFFILIATE_PRO');

        $affiliateSchema = [
            'status'    => ['type' => 'string', 'enum' => ['pending', 'active', 'inactive'], 'description' => 'pending: applied, waiting for approval. inactive: cannot earn.'],
            'search'    => ['type' => 'string', 'description' => 'Part of the name, login, email, payment email, or the affiliate id.'],
            'has_unpaid' => ['type' => 'boolean', 'description' => 'true: only affiliates with unpaid earnings.'],
            'created_from' => ['type' => 'string', 'description' => 'Applied or added on or after this date (created_at). Site time unless it has an offset.'],
            'created_to'   => ['type' => 'string', 'description' => 'A bare date includes the whole day.'],
            'sort_by'   => ['type' => 'string', 'enum' => ['id', 'created_at', 'unpaid_earnings', 'total_earnings', 'referrals', 'visits'], 'description' => 'Default id.'],
            'sort_dir'  => $sortDir,
            'page'      => $page,
            'per_page'  => $perPage,
        ];
        if ($hasPro) {
            $affiliateSchema['group_id'] = ['type' => 'integer', 'description' => 'Affiliate group id, from get-affiliate-context with include=groups.'];
        }

        $termsSchema = [
            'rate_type' => ['type' => 'string', 'enum' => $hasPro ? ['default', 'group', 'flat', 'percentage'] : ['default', 'flat', 'percentage'], 'description' => 'default: the site rate. flat: a fixed amount per referral. percentage: a share of the order.' . ($hasPro ? ' group: the rate of group_id.' : '')],
            'rate'      => ['type' => 'number', 'minimum' => 0, 'description' => 'Required with rate_type flat or percentage. A percentage is 10 for 10%.'],
        ];
        if ($hasPro) {
            $termsSchema['group_id'] = ['type' => 'integer', 'description' => 'Required with rate_type group.'];
        }

        $definitions = [
            'fluent-affiliate/get-affiliate-context' => [
                'label'            => 'Get Affiliate Context',
                'description'      => 'START HERE: who you are, your permissions, the currency, the site timezone, and what needs attention (pending affiliates, unpaid referrals, payouts to settle). Call it once first.',
                // An empty array, not an object: WordPress core's validator indexes properties as an array.
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => $hasPro ? [
                        'include' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['groups']], 'description' => 'groups: the affiliate groups with their ids and rates.'],
                    ] : [],
                ],
                'execute_callback' => [ContextTools::class, 'getContext'],
                'annotations'      => ['readonly' => true, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false],
                'requires'         => 'any',
            ],
            'fluent-affiliate/list-affiliates' => [
                'label'            => 'List Affiliates',
                'description'      => 'List affiliates with status, commission rate, earnings and counts. Filter by status (status=pending lists applications to review), search or unpaid earnings.',
                'input_schema'     => ['type' => 'object', 'properties' => $affiliateSchema],
                'execute_callback' => [AffiliateTools::class, 'listAffiliates'],
                'annotations'      => $readOnly,
                'requires'         => 'affiliate_read',
            ],
            'fluent-affiliate/get-affiliate' => [
                'label'            => 'Get Affiliate',
                'description'      => 'Read one affiliate: profile, commission rate, earnings, referral link and note. include adds recent referrals and payout transactions, when your permissions allow them.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'id'      => ['type' => 'integer', 'description' => 'Affiliate id.'],
                        'include' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['recent_referrals', 'recent_transactions']], 'description' => 'The 10 newest of each.'],
                    ],
                    'required'   => ['id'],
                ],
                'execute_callback' => [AffiliateTools::class, 'getAffiliate'],
                'annotations'      => $readOnly,
                'requires'         => 'affiliate_read',
            ],
            'fluent-affiliate/list-referrals' => [
                'label'            => 'List Referrals',
                'description'      => 'List referrals (commissions) with affiliate, amount, order total, currency, status and source. Filter by affiliate, status, type, payout or date.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'affiliate_id' => ['type' => 'integer'],
                        'status'       => ['type' => 'string', 'enum' => ['pending', 'unpaid', 'processing', 'paid', 'rejected'], 'description' => 'pending: not approved yet. unpaid: earned and owed. processing: in a payout a gateway is sending. paid: in a manual payout from the moment it is created (its transaction says whether you paid), or paid by a gateway. rejected: no commission.'],
                        'type'         => ['type' => 'string', 'description' => 'sale, opt_in, or a type an integration writes such as recurring_sale.'],
                        'provider'     => ['type' => 'string', 'description' => 'Source integration, for example fluent_cart, woo or manual.'],
                        'payout_id'    => ['type' => 'integer', 'description' => 'Referrals paid in this payout.'],
                        'created_from' => ['type' => 'string', 'description' => 'Date or date and time, site time unless it has an offset.'],
                        'created_to'   => ['type' => 'string', 'description' => 'A bare date includes the whole day.'],
                        'search'       => ['type' => 'string', 'description' => 'Part of the description, or the order id.'],
                        'sort_by'      => ['type' => 'string', 'enum' => ['created_at', 'amount', 'id'], 'description' => 'Default created_at.'],
                        'sort_dir'     => $sortDir,
                        'page'         => $page,
                        'per_page'     => $perPage,
                    ],
                ],
                'execute_callback' => [ReferralTools::class, 'listReferrals'],
                'annotations'      => $readOnly,
                'requires'         => 'referral_read',
            ],
            'fluent-affiliate/get-referral' => [
                'label'            => 'Get Referral',
                'description'      => 'Read one referral: amount, order, customer, products, when it becomes payable, and its visit and payout when your permissions allow them. payable_from appears only while the referral is in the holding period.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'Referral id.']],
                    'required'   => ['id'],
                ],
                'execute_callback' => [ReferralTools::class, 'getReferral'],
                'annotations'      => $readOnly,
                'requires'         => 'referral_read',
            ],
            'fluent-affiliate/list-visits' => [
                'label'            => 'List Visits',
                'description'      => 'List visits (clicks on referral links) with landing page, referrer, UTM tags and the referral they led to. Filter by affiliate, conversion or date.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'affiliate_id' => ['type' => 'integer'],
                        'converted'    => ['type' => 'boolean', 'description' => 'true: visits that led to a referral. false: visits that did not.'],
                        'created_from' => ['type' => 'string', 'description' => 'Date or date and time, site time unless it has an offset.'],
                        'created_to'   => ['type' => 'string', 'description' => 'A bare date includes the whole day.'],
                        'search'       => ['type' => 'string', 'description' => 'Part of the url, referrer, utm_campaign, utm_source or utm_medium; any of them may match.'],
                        'page'         => $page,
                        'per_page'     => $perPage,
                    ],
                ],
                'execute_callback' => [VisitTools::class, 'listVisits'],
                'annotations'      => $readOnly,
                'requires'         => 'visit_read',
            ],
            'fluent-affiliate/list-payouts' => [
                'label'            => 'List Payouts',
                'description'      => 'List payouts (payment runs) with method, status, total, currency and how many transactions are still to settle.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'status'       => ['type' => 'string', 'enum' => ['processing', 'sending', 'paid'], 'description' => 'processing: transactions to settle. sending: a gateway is sending it. paid: every transaction is paid.'],
                        'created_from' => ['type' => 'string', 'description' => 'Date or date and time, site time unless it has an offset.'],
                        'created_to'   => ['type' => 'string', 'description' => 'A bare date includes the whole day.'],
                        'page'         => $page,
                        'per_page'     => $perPage,
                    ],
                ],
                'execute_callback' => [PayoutTools::class, 'listPayouts'],
                'annotations'      => $readOnly,
                'requires'         => 'payout_read',
            ],
            'fluent-affiliate/get-payout' => [
                'label'            => 'Get Payout',
                'description'      => 'Read one payout with its transactions: one per affiliate, with amount, status and the affiliate\'s payment email.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'id'                 => ['type' => 'integer', 'description' => 'Payout id.'],
                        'transaction_status' => ['type' => 'string', 'enum' => ['processing', 'paid', 'failed']],
                        'page'               => $page,
                        'per_page'           => $perPage,
                    ],
                    'required'   => ['id'],
                ],
                'execute_callback' => [PayoutTools::class, 'getPayout'],
                'annotations'      => $readOnly,
                'requires'         => 'payout_read',
            ],
            'fluent-affiliate/get-report' => [
                'label'            => 'Get Report',
                'description'      => 'Affiliate program results for a date range: referrals and commissions by status, order value, visits, conversion, new affiliates and top affiliates. Counted by creation date. top_affiliates: the 5 largest commission totals (unpaid, processing and paid), one row per affiliate and currency. Conversion: visits that a referral points to, divided by all visits in the range, as a percent; 0 when there are no visits. paid_out: transactions marked paid in the range.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'Date, site time. Default 30 days ago.'],
                        'to'   => ['type' => 'string', 'description' => 'Date, site time, included. Default today.'],
                    ],
                ],
                'execute_callback' => [ReportTools::class, 'getReport'],
                'annotations'      => $readOnly,
                'requires'         => 'any',
            ],
            'fluent-affiliate/create-affiliate' => [
                'label'            => 'Create Affiliate',
                'description'      => 'Make an existing WordPress user an affiliate. It does not create WordPress users. It acts at once: status=active emails the new affiliate as an approval would.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => array_merge([
                        'user_id'       => ['type' => 'integer', 'description' => 'WordPress user id.'],
                        'status'        => ['type' => 'string', 'enum' => ['pending', 'active', 'inactive']],
                        'payment_email' => ['type' => 'string', 'description' => 'Where payouts go. Required when the site pays by PayPal or by hand (the default).'],
                        'bank_details'  => ['type' => 'string', 'description' => 'Required when the site pays by bank transfer.'],
                    ], $termsSchema),
                    'required'   => ['user_id', 'status'],
                ],
                'execute_callback' => [AffiliateTools::class, 'createAffiliate'],
                'annotations'      => ['readonly' => false, 'destructive' => false, 'idempotent' => false, 'openWorldHint' => true],
                'requires'         => 'affiliate_write',
            ],
            'fluent-affiliate/update-affiliate' => [
                'label'            => 'Update Affiliate',
                'description'      => 'Approve, deactivate or change an affiliate: status, commission rate and note. It acts at once, with no preview; a status change emails the affiliate. Only the fields you pass change. A new rate applies to referrals created from now on.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => array_merge([
                        'id'            => ['type' => 'integer', 'description' => 'Affiliate id.'],
                        'status'        => ['type' => 'string', 'enum' => ['pending', 'active', 'inactive'], 'description' => 'active approves the affiliate; inactive stops new commissions.'],
                        'note'          => ['type' => 'string', 'description' => 'Internal note, shown to staff only. Replaces the note.'],
                        'payment_email' => ['type' => 'string', 'description' => 'Refused: where an affiliate is paid changes only in the admin screen.'],
                        'bank_details'  => ['type' => 'string', 'description' => 'Refused: where an affiliate is paid changes only in the admin screen.'],
                    ], $termsSchema),
                    'required'   => ['id'],
                ],
                'execute_callback' => [AffiliateTools::class, 'updateAffiliate'],
                'annotations'      => ['readonly' => false, 'destructive' => false, 'idempotent' => false, 'openWorldHint' => true],
                'requires'         => 'affiliate_write',
            ],
            'fluent-affiliate/save-referral' => [
                'label'            => 'Save Referral',
                'description'      => 'Create a manual referral (affiliate_id), or change one (id): amount, status, type and description. Two calls: the first returns a preview and a confirm value. A paid, processing or payout-held referral cannot change (get-referral shows can_edit); to correct one, take its transaction out with remove-transaction first. Money already sent is not taken back. A bonus is type sale with a description.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'id'           => ['type' => 'integer', 'description' => 'Referral to change. Leave out to create one.'],
                        'affiliate_id' => ['type' => 'integer', 'description' => 'Affiliate the new referral is for. Only when creating.'],
                        'amount'       => ['type' => 'number', 'minimum' => 0, 'description' => 'Commission, a decimal in the site currency. Required when creating.'],
                        'status'       => ['type' => 'string', 'enum' => ['pending', 'unpaid', 'rejected'], 'description' => 'unpaid: owed, goes into the next payout. pending: waits for approval. rejected: no commission. Required when creating.'],
                        'type'         => ['type' => 'string', 'enum' => ['sale', 'opt_in'], 'description' => 'Default sale. A referral an integration wrote keeps its own type.'],
                        'description'  => ['type' => 'string', 'description' => 'What the commission is for.'],
                        'confirm'      => $confirm,
                    ],
                ],
                'execute_callback' => [ReferralTools::class, 'saveReferral'],
                'annotations'      => ['readonly' => false, 'destructive' => false, 'idempotent' => false, 'openWorldHint' => true],
                'requires'         => 'referral_write',
            ],
            'fluent-affiliate/update-referrals' => [
                'label'            => 'Update Referrals',
                'description'      => 'Set the status of up to 100 referrals at once, for example approve pending ones (status=unpaid) or reject them. Two calls: preview, then confirm. Paid, processing or payout-held referrals are refused, and the error lists them.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'ids'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 100, 'description' => 'Referral ids, from list-referrals.'],
                        'status'  => ['type' => 'string', 'enum' => ['unpaid', 'rejected', 'pending'], 'description' => 'unpaid: approved and owed, goes into the next payout. rejected: no commission. pending: back to waiting for approval.'],
                        'confirm' => $confirm,
                    ],
                    'required'   => ['ids', 'status'],
                ],
                'execute_callback' => [ReferralTools::class, 'updateReferrals'],
                'annotations'      => ['readonly' => false, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false],
                'requires'         => 'referral_write',
            ],
            'fluent-affiliate/create-payout' => [
                'label'            => 'Create Payout',
                'description'      => 'Pay affiliates their unpaid referrals in one payout run. Two calls: the first changes nothing and returns who gets how much, what the holding period keeps back, and a confirm value; the second creates the payout. Only unpaid referrals are paid.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'title'                 => ['type' => 'string', 'description' => 'For example "November 2026 commissions".'],
                        'description'           => ['type' => 'string'],
                        'start_date'            => ['type' => 'string', 'description' => 'YYYY-MM-DD, site time. Referrals created on or after this day. Default: no lower limit.'],
                        'end_date'              => ['type' => 'string', 'description' => 'YYYY-MM-DD, site time, included. Default: now.'],
                        'affiliate_ids'         => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Pay only these affiliates, including inactive and pending ones. Use only affiliates the person named. Default: every active affiliate.'],
                        'min_payout'            => ['type' => 'number', 'minimum' => 0, 'description' => 'Leave out affiliates owed this amount or less.'],
                        'ignore_holding_period' => ['type' => 'boolean', 'description' => 'true: also pay referrals still in the holding period (refund window).'],
                        'payout_method'         => ['type' => 'string', 'enum' => array_keys(Helper::getPayoutMethods()), 'description' => 'Default manual: you pay outside the site.'],
                        'confirm'               => $confirm,
                    ],
                    'required'   => ['title', 'description'],
                ],
                'execute_callback' => [PayoutTools::class, 'createPayout'],
                'annotations'      => ['readonly' => false, 'destructive' => false, 'idempotent' => false, 'openWorldHint' => true],
                'requires'         => 'payout_write',
            ],
            'fluent-affiliate/update-transaction' => [
                'label'            => 'Update Transaction',
                'description'      => 'Record that you paid an affiliate: mark one payout transaction, or all processing ones of a payout (all_processing=true), as paid, or one back to processing. Two calls. A payment that bounced: if it is paid, set it to processing, then call remove-transaction; its referrals become unpaid again. A manual payout keeps no failed record; failed is set only by a payout gateway.',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'payout_id'      => ['type' => 'integer'],
                        'transaction_id' => ['type' => 'integer', 'description' => 'One transaction of the payout. Required unless all_processing is true.'],
                        'all_processing' => ['type' => 'boolean', 'description' => 'true with status=paid and no transaction_id: mark every processing transaction of the payout paid.'],
                        'status'         => ['type' => 'string', 'enum' => ['paid', 'processing']],
                        'confirm'        => $confirm,
                    ],
                    'required'   => ['payout_id', 'status'],
                ],
                'execute_callback' => [PayoutTools::class, 'setTransactionStatus'],
                'annotations'      => ['readonly' => false, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => true],
                'requires'         => 'payout_write',
            ],
            'fluent-affiliate/remove-transaction' => [
                'label'            => 'Remove Transaction',
                'description'      => 'Take one unpaid transaction out of a payout: it is deleted and its referrals become unpaid again, for a later payout. Two calls. A paid transaction must go back to processing first (update-transaction).',
                'input_schema'     => [
                    'type'       => 'object',
                    'properties' => [
                        'payout_id'      => ['type' => 'integer'],
                        'transaction_id' => ['type' => 'integer', 'description' => 'A transaction of that payout, from get-payout.'],
                        'confirm'        => $confirm,
                    ],
                    'required'   => ['payout_id', 'transaction_id'],
                ],
                'execute_callback' => [PayoutTools::class, 'removeTransaction'],
                'annotations'      => ['readonly' => false, 'destructive' => true, 'idempotent' => false, 'openWorldHint' => false],
                'requires'         => 'payout_write',
            ],
        ];

        /**
         * Tools a companion plugin adds to the FluentAffiliate MCP server. Each
         * definition takes the keys above; requires is one of McpGate::REQUIREMENTS.
         *
         * @param array $definitions Keyed by ability name, fluent-affiliate/...
         */
        $extra = apply_filters('fluent_affiliate/mcp_tool_definitions', []);

        foreach ((array) $extra as $name => $definition) {
            if (self::isValidDefinition($name, $definition) && !isset($definitions[$name])) {
                $definitions[$name] = $definition;
            }
        }

        self::$definitions = $definitions;

        return self::$definitions;
    }

    private static function isValidDefinition($name, $definition)
    {
        return is_string($name) && strpos($name, self::CATEGORY . '/') === 0
            && is_array($definition)
            && !empty($definition['label']) && !empty($definition['description'])
            && isset($definition['input_schema']['type'])
            && isset($definition['execute_callback']) && is_callable($definition['execute_callback'])
            && isset($definition['annotations']) && is_array($definition['annotations'])
            && isset($definition['requires']) && in_array($definition['requires'], McpGate::REQUIREMENTS, true);
    }

    public static function register()
    {
        // A public callback: anything can call it. The Abilities API arrives in WordPress 6.9.
        if (!function_exists('wp_register_ability')) {
            return;
        }

        foreach (self::getDefinitions() as $name => $definition) {
            if (function_exists('wp_has_ability') && wp_has_ability($name)) {
                continue;
            }

            wp_register_ability($name, [
                'label'               => $definition['label'],
                'description'         => $definition['description'],
                'category'            => self::CATEGORY,
                'input_schema'        => $definition['input_schema'],
                'execute_callback'    => function ($params = null) use ($name) {
                    return self::run($name, $params);
                },
                // the real checks run in run(), so a refusal explains itself
                'permission_callback' => 'is_user_logged_in',
                'meta'                => [
                    'show_in_rest' => false,
                    'mcp'          => ['public' => true],
                    'annotations'  => $definition['annotations'],
                ],
            ]);
        }
    }

    /**
     * Tool names, for this site or for the current user.
     *
     * @param bool $forCurrentUser True leaves out the tools the current user cannot run.
     * @return string[]
     */
    public static function exposedNames($forCurrentUser = false)
    {
        $names = [];

        foreach (self::getDefinitions() as $name => $definition) {
            if (!$forCurrentUser || McpGate::can($definition['requires'])) {
                $names[] = $name;
            }
        }

        /**
         * The FluentAffiliate MCP tool names.
         *
         * @param string[] $names Ability names, fluent-affiliate/...
         */
        $names = (array) apply_filters('fluent_affiliate/mcp_ability_names', $names);
        $known = self::getDefinitions();

        return array_values(array_filter($names, function ($name) use ($known) {
            return is_string($name) && isset($known[$name]);
        }));
    }

    /**
     * @param string $name Ability name.
     * @return bool
     */
    public static function hasTool($name)
    {
        $definitions = self::getDefinitions();

        return isset($definitions[$name]);
    }

    /**
     * Runs one tool. Every check a tool needs is here, so a refusal reaches the
     * agent as the error envelope instead of the adapter's plain "Permission denied".
     *
     * @param string $name   Ability name.
     * @param mixed  $params
     * @return array|\WP_Error
     */
    public static function run($name, $params)
    {
        $definitions = self::getDefinitions();

        if (!isset($definitions[$name])) {
            return McpHelper::error('not_found', 'Unknown tool.');
        }

        $definition = $definitions[$name];
        McpHelper::resetWarnings();

        $gate = McpGate::transport();
        if (is_wp_error($gate)) {
            return McpHelper::error('forbidden', $gate->get_error_message());
        }

        if (!McpGate::can($definition['requires'])) {
            return McpHelper::error('forbidden', McpGate::refusal($definition['requires']));
        }

        $params = McpHelper::validateParams($params, $definition['input_schema']);
        if (is_wp_error($params)) {
            return $params;
        }

        try {
            return call_user_func($definition['execute_callback'], $params);
        } catch (\Throwable $exception) {
            return McpHelper::error('internal_error', 'The tool failed on the site. Nothing more is known; check the site error log.', array_filter([
                'retryable' => false,
                'hint'      => defined('WP_DEBUG') && WP_DEBUG ? get_class($exception) : null,
            ]));
        }
    }
}
