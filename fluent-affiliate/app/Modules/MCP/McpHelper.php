<?php

namespace FluentAffiliate\App\Modules\MCP;

use FluentAffiliate\App\Helper\Utility;

/**
 * The shapes every FluentAffiliate MCP tool shares: the result envelope, the
 * error envelope, input checks, dates, money and the confirm value.
 *
 * Text here is read by an AI agent, so it is plain English with no translation
 * call (Fluent MCP standard, rule 6).
 */
class McpHelper
{
    /**
     * Sent as meta.schema_version. Raise it when a result field is renamed,
     * removed or changes meaning; adding a field needs no change.
     */
    const SCHEMA_VERSION = 1;

    /** Minutes a confirm value stays valid: 15 to 30, by its 15-minute window. */
    const CONFIRM_WINDOW = 15;

    /** A written date, or date and time, with an optional offset. PHP's parser also reads words such as "Tuesday", so they are refused here. */
    const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /** @var string[] Things the running tool changed or left out; sent as meta.warnings. */
    private static $warnings = [];

    public static function resetWarnings()
    {
        self::$warnings = [];
    }

    /**
     * @param string $message
     */
    public static function warn($message)
    {
        self::$warnings[] = $message;
    }

    /**
     * @param string $summary One line an agent can quote.
     * @param mixed  $data
     * @param array  $meta    Extra meta keys, such as paging.
     * @return array
     */
    public static function envelope($summary, $data, $meta = [])
    {
        return [
            'summary' => $summary,
            'data'    => $data,
            'meta'    => array_merge([
                'schema_version' => self::SCHEMA_VERSION,
            ], $meta, self::$warnings ? ['warnings' => array_values(array_unique(self::$warnings))] : []),
        ];
    }

    /**
     * The MCP adapter forwards only the WP_Error message, so the structured
     * error is JSON inside it.
     *
     * @param string $code
     * @param string $message What to change, with the allowed values.
     * @param array  $details retryable, fields, allowed, and any data the agent needs next.
     * @return \WP_Error
     */
    public static function error($code, $message, $details = [])
    {
        $payload = array_merge(['code' => $code, 'message' => $message, 'retryable' => false, 'fields' => []], $details);

        return new \WP_Error('fluent_affiliate_mcp_' . $code, wp_json_encode(['error' => $payload]));
    }

    /**
     * @param string|string[] $fields
     * @param string          $message
     * @param array           $details
     * @return \WP_Error
     */
    public static function invalid($fields, $message, $details = [])
    {
        return self::error('invalid_param', $message, array_merge(['fields' => array_values((array) $fields)], $details));
    }

    /**
     * @param string $what For example "Affiliate 12".
     * @return \WP_Error
     */
    public static function notFound($what, $field = 'id')
    {
        return self::error('not_found', $what . ' does not exist.', ['fields' => [$field]]);
    }

    /**
     * Unknown parameters and unknown enum values fail instead of being dropped:
     * a dropped filter widens the result.
     *
     * @param mixed $params
     * @param array $schema The tool's input_schema.
     * @return array|\WP_Error The parameters, or an unknown_param or invalid_param error.
     */
    public static function validateParams($params, $schema)
    {
        if ($params === null || $params === '' || $params === []) {
            $params = [];
        }

        if (is_object($params)) {
            $params = json_decode(wp_json_encode($params), true);
        }

        if (!is_array($params)) {
            return self::error('invalid_param', 'Arguments must be an object.');
        }

        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];

        $unknown = array_values(array_diff(array_keys($params), array_keys($properties)));
        if ($unknown) {
            return self::error('unknown_param', 'This tool has no parameter named ' . implode(', ', $unknown) . '. Use the names in allowed.', [
                'fields'  => $unknown,
                'allowed' => array_keys($properties),
            ]);
        }

        $required = isset($schema['required']) ? (array) $schema['required'] : [];
        foreach ($required as $name) {
            if (!isset($params[$name]) || $params[$name] === '' || $params[$name] === []) {
                return self::invalid($name, $name . ' is required.');
            }
        }

        foreach ($params as $name => $value) {
            if ($value === null) {
                unset($params[$name]);
                continue;
            }

            $property = $properties[$name];
            $type = isset($property['type']) ? $property['type'] : null;

            if ($type === 'integer') {
                if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value)) && !(is_float($value) && floor($value) == $value)) {
                    return self::invalid($name, $name . ' must be a whole number.');
                }
                $params[$name] = $value = (int) $value;
                if (isset($property['minimum']) && $value < $property['minimum']) {
                    return self::invalid($name, $name . ' must be at least ' . $property['minimum'] . '.');
                }
            } elseif ($type === 'number') {
                if (!is_numeric($value)) {
                    return self::invalid($name, $name . ' must be a number.');
                }
                $params[$name] = $value = (float) $value;
                if (isset($property['minimum']) && $value < $property['minimum']) {
                    return self::invalid($name, $name . ' must be at least ' . $property['minimum'] . '.');
                }
            } elseif ($type === 'boolean') {
                // what WordPress's own schema check accepts (rest_is_boolean)
                $truthy = [true, 'true', '1', 1];
                $falsy = [false, 'false', '0', 0];
                if (in_array($value, $truthy, true)) {
                    $params[$name] = true;
                } elseif (in_array($value, $falsy, true)) {
                    $params[$name] = false;
                } else {
                    return self::invalid($name, $name . ' must be true or false.');
                }
            } elseif ($type === 'string') {
                if (!is_string($value)) {
                    return self::invalid($name, $name . ' must be a string.');
                }
            } elseif ($type === 'array') {
                if (!is_array($value) || ($value && array_keys($value) !== range(0, count($value) - 1))) {
                    return self::invalid($name, $name . ' must be a list.');
                }
                $itemType = isset($property['items']['type']) ? $property['items']['type'] : null;
                foreach ($value as $index => $item) {
                    if ($itemType === 'integer') {
                        if (!is_int($item) && !(is_string($item) && preg_match('/^\d+$/', $item))) {
                            return self::invalid($name, $name . ' must be a list of whole numbers.');
                        }
                        $params[$name][$index] = (int) $item;
                    } elseif ($itemType === 'string' && !is_string($item)) {
                        return self::invalid($name, $name . ' must be a list of strings.');
                    }
                }
                if (isset($property['maxItems']) && count($value) > $property['maxItems']) {
                    return self::invalid($name, $name . ' takes at most ' . $property['maxItems'] . ' items.');
                }
            }

            $allowed = isset($property['enum']) ? $property['enum'] : (isset($property['items']['enum']) ? $property['items']['enum'] : null);

            if ($allowed === null) {
                continue;
            }

            foreach ((array) $params[$name] as $item) {
                if (!in_array($item, $allowed, true)) {
                    return self::invalid($name, $name . ' must be one of: ' . implode(', ', $allowed) . '.', ['allowed' => $allowed]);
                }
            }
        }

        return $params;
    }

    /**
     * @param array $params
     * @param int   $defaultSize
     * @param int   $max
     * @return array{page: int, per_page: int}
     */
    public static function pagination($params, $defaultSize = 20, $max = 100)
    {
        $requested = (int) (isset($params['per_page']) ? $params['per_page'] : $defaultSize);

        if ($requested > $max) {
            self::warn(sprintf('per_page was lowered to %d, the maximum.', $max));
        }

        return [
            'page'     => max(1, (int) (isset($params['page']) ? $params['page'] : 1)),
            'per_page' => min($max, max(1, $requested)),
        ];
    }

    /**
     * @param int $total
     * @param array{page: int, per_page: int} $paging
     * @return array
     */
    public static function pagingMeta($total, $paging)
    {
        return [
            'paging' => [
                'page'     => $paging['page'],
                'per_page' => $paging['per_page'],
                'total'    => (int) $total,
                'has_more' => $paging['page'] * $paging['per_page'] < $total,
            ],
        ];
    }

    /**
     * The FluentAffiliate tables store site time. Agents get ISO 8601 with the site offset.
     *
     * @param string|\DateTimeInterface|null $mysqlDate
     * @return string|null
     */
    public static function iso($mysqlDate)
    {
        if ($mysqlDate instanceof \DateTimeInterface) {
            $mysqlDate = $mysqlDate->format('Y-m-d H:i:s');
        }

        if (!$mysqlDate || strpos((string) $mysqlDate, '0000-00-00') === 0) {
            return null;
        }

        $date = date_create((string) $mysqlDate, wp_timezone());

        return $date ? $date->format('c') : null;
    }

    /**
     * A date an agent sent, as a site-time MySQL datetime.
     *
     * @param mixed  $value    A written date or date and time. Without an offset it is site time.
     * @param string $field    The parameter name, for the error.
     * @param bool   $endOfDay A bare date means its last second (for an end of range).
     * @return string|\WP_Error
     */
    public static function siteDate($value, $field, $endOfDay = false)
    {
        $value = is_string($value) ? trim($value) : $value;

        if (!is_string($value) || !preg_match(self::DATE_PATTERN, $value, $parts)) {
            return self::invalid($field, $field . ' must be a written date (2026-11-20) or date and time (2026-11-20T09:00:00+06:00). Words such as "yesterday" are refused.');
        }

        // DateTime rolls 2031-02-30 over to 2 March without an error.
        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (isset($parts[4]) && $parts[4] !== '' && ((int) $parts[4] > 23 || (int) $parts[5] > 59 || (isset($parts[6]) && $parts[6] !== '' && (int) $parts[6] > 59)))
        ) {
            return self::invalid($field, $field . ' is not a date that exists: ' . $value . '.');
        }

        $hasTime = isset($parts[4]) && $parts[4] !== '';

        $date = date_create($value, wp_timezone());
        if (!$date) {
            return self::invalid($field, $field . ' could not be read as a date.');
        }

        $date = $date->setTimezone(wp_timezone());

        if (!$hasTime && $endOfDay) {
            return $date->format('Y-m-d') . ' 23:59:59';
        }

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * A date filter pair, checked and in order.
     *
     * @param array  $params
     * @param string $fromKey
     * @param string $toKey
     * @return array{0: string|null, 1: string|null}|\WP_Error
     */
    public static function dateRange($params, $fromKey, $toKey)
    {
        $from = null;
        $to = null;

        if (isset($params[$fromKey])) {
            $from = self::siteDate($params[$fromKey], $fromKey);
            if (is_wp_error($from)) {
                return $from;
            }
        }

        if (isset($params[$toKey])) {
            $to = self::siteDate($params[$toKey], $toKey, true);
            if (is_wp_error($to)) {
                return $to;
            }
        }

        if ($from && $to && $from > $to) {
            return self::invalid([$fromKey, $toKey], $fromKey . ' is after ' . $toKey . '. Send the earlier date as ' . $fromKey . '.');
        }

        return [$from, $to];
    }

    /**
     * A plain YYYY-MM-DD day, for the payout range (it covers whole days).
     *
     * @return string|\WP_Error
     */
    public static function day($value, $field)
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $parts)) {
            return self::invalid($field, $field . ' must be a day written as YYYY-MM-DD, for example 2026-11-20.');
        }

        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return self::invalid($field, $field . ' is not a date that exists: ' . trim($value) . '.');
        }

        return trim($value);
    }

    /**
     * A money amount: a decimal in major units. The currency goes beside it.
     *
     * @param mixed $amount
     * @return float
     */
    public static function money($amount)
    {
        return round((float) $amount, 2);
    }

    /**
     * @param string|null $currency A row's own currency, empty on manual rows.
     * @return string
     */
    public static function currency($currency = null)
    {
        $currency = is_string($currency) ? strtoupper(trim($currency)) : '';

        return $currency !== '' ? $currency : strtoupper((string) Utility::getCurrency());
    }

    /**
     * Amounts per currency as one phrase: "100.00 EUR" or "10.00 USD + 5.00 EUR".
     *
     * @param array<string, float> $byCurrency
     * @return string
     */
    public static function amountsText($byCurrency)
    {
        $parts = [];
        foreach ($byCurrency as $code => $sum) {
            $parts[] = number_format((float) $sum, 2, '.', '') . ' ' . $code;
        }

        return implode(' + ', $parts);
    }

    /**
     * The warning for referral amounts that a payout records in another
     * currency without converting them, or null when every amount is already
     * in that currency. A single foreign currency gets it too.
     *
     * @param array<string, float> $byCurrency Amounts per referral currency.
     * @param string               $currency   The currency the payout records.
     * @return string|null
     */
    public static function currencyNotice($byCurrency, $currency)
    {
        $foreign = array_diff(array_keys($byCurrency), [$currency]);
        if (!$foreign) {
            return null;
        }

        return 'Some payable referrals are not in ' . $currency . ' (' . self::amountsText($byCurrency) . '). The payout adds the amounts as they are and records every transaction in ' . $currency . ' without converting. Pay each currency in its own payout, or ask a person first.';
    }

    /**
     * A LIKE pattern that matches $term anywhere. % and _ in the term are
     * escaped here once; the query layer binds the value and escapes nothing.
     *
     * @param string $term
     * @return string
     */
    public static function likeAnywhere($term)
    {
        global $wpdb;

        return '%' . $wpdb->esc_like((string) $term) . '%';
    }

    /**
     * The value a write tool hands out with its preview and asks back in the
     * second call. It is bound to the tool, the record, the user, a fingerprint
     * of what the preview showed, and a 15-minute window, so it cannot be
     * guessed, reused for another record, or used after the record changed or
     * after half an hour at most. It needs no storage.
     *
     * @param string $action      Tool name.
     * @param string $key         The record or run the preview is about.
     * @param string $fingerprint What the preview showed.
     * @param int    $windowsBack 0 for the current window, 1 for the one before.
     * @return string
     */
    public static function confirmValue($action, $key, $fingerprint, $windowsBack = 0)
    {
        $window = (int) floor(time() / (self::CONFIRM_WINDOW * MINUTE_IN_SECONDS)) - (int) $windowsBack;

        return substr(hash_hmac(
            'sha256',
            implode('|', [$action, (string) $key, get_current_user_id(), (string) $fingerprint, $window]),
            wp_salt('auth')
        ), 0, 12);
    }

    /**
     * @param mixed $given The confirm parameter of the second call.
     * @return bool
     */
    public static function confirmMatches($given, $action, $key, $fingerprint)
    {
        if (!is_string($given) || $given === '') {
            return false;
        }

        return hash_equals(self::confirmValue($action, $key, $fingerprint), $given)
            || hash_equals(self::confirmValue($action, $key, $fingerprint, 1), $given);
    }

    /** Prefix of the options that mark a confirm value as used. */
    const USED_CONFIRM_PREFIX = '_fa_mcp_used_';

    /**
     * Mark a confirm value as used, once. A client that retries a confirmed
     * call after a timeout must not apply the write twice: two identical
     * manual referrals are two commissions. add_option() fails when the row
     * exists, so two calls racing on one value cannot both pass.
     *
     * @param string $action Tool name.
     * @param string $given  The confirm value.
     * @return true|\WP_Error
     */
    public static function useConfirm($action, $given)
    {
        if (add_option(self::usedConfirmName($action, $given), time(), '', false)) {
            return true;
        }

        return self::error('conflict', 'This confirm value was used already, so the change was not applied again. Read the record to see the result; for a new change, call ' . $action . ' without confirm.', ['fields' => ['confirm']]);
    }

    /**
     * Free a confirm value whose write was refused and changed nothing, so the
     * same confirmed call can run again once the cause is fixed. Only for an
     * error the write returned: after a thrown exception the write may have
     * landed, so the value stays used.
     *
     * @param string    $action Tool name.
     * @param string    $given  The confirm value.
     * @param \WP_Error $error  The error the write returned.
     * @return \WP_Error The same error.
     */
    public static function releaseConfirm($action, $given, \WP_Error $error)
    {
        delete_option(self::usedConfirmName($action, $given));

        return $error;
    }

    private static function usedConfirmName($action, $given)
    {
        return self::USED_CONFIRM_PREFIX . md5($action . '|' . get_current_user_id() . '|' . (string) $given);
    }

    /**
     * Remove used-confirm marks older than the longest confirm window. Runs daily.
     */
    public static function purgeUsedConfirms()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a cleanup of this plugin's own rows
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
            $wpdb->esc_like(self::USED_CONFIRM_PREFIX) . '%',
            time() - HOUR_IN_SECONDS
        ));
    }

    /**
     * A fingerprint of a preview: any change to it makes the confirm value stale.
     *
     * @param mixed $preview
     * @return string
     */
    public static function fingerprint($preview)
    {
        return md5(wp_json_encode($preview));
    }

    /**
     * The first call of a write that moves money: nothing is changed yet.
     *
     * @param string $action  Tool name, without the namespace.
     * @param array  $preview What the second call will do.
     * @param string $confirm The value to send back.
     * @param string $summary
     * @return array
     */
    public static function confirmationNeeded($action, $preview, $confirm, $summary)
    {
        return self::envelope($summary, [
            'changed'            => false,
            'needs_confirmation' => true,
            'preview'            => $preview,
            'confirm'            => $confirm,
            'next'               => 'Nothing is changed yet. Show the preview to the person. Only after they agree, call '
                . $action . ' again with the same arguments and this confirm value (valid for at least 15 minutes).',
        ]);
    }

    /**
     * The second call came with a confirm value that no longer matches: it
     * expired, or what the preview showed has changed. The new preview rides
     * in the error, so the agent can show it and ask again without a third call.
     *
     * @return \WP_Error
     */
    public static function confirmStale($action, $preview, $confirm)
    {
        return self::invalid('confirm', 'confirm is not the value ' . $action . ' gave for this preview: it expired, or what the preview showed has changed. Nothing was changed. Show the new preview to the person; after they agree, call ' . $action . ' again with the new confirm value.', [
            'preview' => $preview,
            'confirm' => $confirm,
        ]);
    }
}
