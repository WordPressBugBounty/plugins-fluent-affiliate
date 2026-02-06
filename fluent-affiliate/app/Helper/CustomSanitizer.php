<?php

namespace FluentAffiliate\App\Helper;

use FluentAffiliate\Framework\Support\Arr;

class CustomSanitizer
{
    public static function sanitizeReferralConfig($data)
    {
        $validKeys = array_keys(Utility::defaultReferralSettings());

        $data = Arr::only($data, $validKeys);

        $fieldTypes = apply_filters('fluent_affiliate/referral_config_field_types', [
            'referral_variable'        => 'text',
            'currency'                 => 'text',
            'rate'                     => 'number',
            'cookie_duration'          => 'number',
            'dashboard_page_id'        => 'number',
            'referral_format'          => ['id', 'username'],
            'rate_type'                => ['percentage', 'fixed'],
            'payout_method'            => ['paypal', 'bank_transfer'],
            'number_format'            => ['comma_separated', 'dot_separated'],
            'currency_symbol_position' => ['left', 'right'],
            'credit_last_referrer'     => ['yes', 'no'],
            'exclude_shipping'         => ['yes', 'no'],
            'exclude_tax'              => ['yes', 'no'],
            'exclude_discount'         => ['yes', 'no'],
            'self_referral_disabled'   => ['yes', 'no']
        ]);

        foreach ($fieldTypes as $field => $type) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];

            if ($type === 'number') {
                $data[$field] = is_numeric($value) ? intval($value) : 0;
            } elseif (is_array($type)) {
                $data[$field] = in_array($value, $type, true) ? $value : $type[0];
            } else {
                $data[$field] = is_string($value) ? sanitize_text_field($value) : '';
            }
        }

        return $data;
    }
}
