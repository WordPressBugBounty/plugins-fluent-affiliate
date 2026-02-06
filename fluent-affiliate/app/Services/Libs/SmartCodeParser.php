<?php

namespace FluentAffiliate\App\Services\Libs;

use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Referral;

class SmartCodeParser
{
    public function parse($templateString, $data)
    {
        $result = [];
        $isSingle = false;

        if (!is_array($templateString)) {
            $isSingle = true;
        }

        foreach ((array)$templateString as $key => $string) {
            $result[$key] = $this->parseShortcode($string, $data);
        }

        if ($isSingle) {
            return reset($result);
        }

        return $result;
    }


    public function parseShortcode($string, $data)
    {

        if (!$string) {
            return '';
        }

        // check if the string contains any smartcode
        if (strpos($string, '{{') === false && strpos($string, '##') === false) {
            return $string;
        }

        return preg_replace_callback('/({{|##)+(.*?)(}}|##)/', function ($matches) use ($data) {
            return $this->replace($matches, $data);
        }, $string);
    }

    protected function replace($matches, $data)
    {
        if (empty($matches[2])) {
            return apply_filters('fluent_affiliate/smartcode_fallback', $matches[0], $data);
        }

        $matches[2] = trim($matches[2]);

        $matched = explode('.', $matches[2]);

        if (count($matched) <= 1) {
            return apply_filters('fluent_affiliate/smartcode_fallback', $matches[0], $data);
        }

        $dataKey = trim(array_shift($matched));

        $valueKey = trim(implode('.', $matched));

        if (!$valueKey) {
            return apply_filters('fluent_affiliate/smartcode_fallback', $matches[0], $data);
        }

        $valueKeys = explode('|', $valueKey);

        $valueKey = $valueKeys[0];
        $defaultValue = '';
        $transformer = '';

        $valueCounts = count($valueKeys);

        if ($valueCounts >= 3) {
            $defaultValue = trim($valueKeys[1]);
            $transformer = trim($valueKeys[2]);
        } else if ($valueCounts === 2) {
            $defaultValue = trim($valueKeys[1]);
        }

        $value = '';
        switch ($dataKey) {
            case 'site':
                $value = $this->getWpValue($valueKey, $defaultValue, $data);
                break;
            case 'user':
                $user = $data['user'] ?? null;
                if (!$user) {
                    $value = $defaultValue;
                } else {
                    $value = $this->getUserValue($valueKey, $defaultValue, $user);
                }
                break;
            case 'affiliate':
                $affiliate = $data['affiliate'] ?? null;
                if (!$affiliate) {
                    $value = $defaultValue;
                } else {
                    $value = $this->getAffiliateValue($valueKey, $defaultValue, $affiliate);
                }
                break;
            case 'transaction':
                $transaction = $data['transaction'] ?? null;
                if (!$transaction) {
                    $value = $defaultValue;
                } else {
                    $value = $this->getTransactionValue($valueKey, $defaultValue, $transaction);
                }
                break;
            case 'referral':
                $referral = $data['referral'] ?? null;
                if (!$referral) {
                    $value = $defaultValue;
                } else {
                    $value = $this->getReferralValue($valueKey, $defaultValue, $referral);
                }
                break;
            default:
                $value = apply_filters('fluent_affiliate/smartcode_group_callback_' . $dataKey, $matches[0], $valueKey, $defaultValue, $data);
        }

        if ($transformer && is_string($transformer) && $value) {
            switch ($transformer) {
                case 'trim':
                    return trim($value);
                case 'ucfirst':
                    return ucfirst($value);
                case 'strtolower':
                    return strtolower($value);
                case 'strtoupper':
                    return strtoupper($value);
                case 'ucwords':
                    return ucwords($value);
                case 'concat_first': // usage: {{contact.first_name||concat_first|Hi
                    if (isset($valueKeys[3])) {
                        $value = trim($valueKeys[3] . ' ' . $value);
                    }
                    return $value;
                case 'concat_last': // usage: {{contact.first_name||concat_last|, => FIRST_NAME,
                    if (isset($valueKeys[3])) {
                        $value = trim($value . '' . $valueKeys[3]);
                    }
                    return $value;
                case 'show_if': // usage {{contact.first_name||show_if|First name exist
                    if (isset($valueKeys[3])) {
                        $value = $valueKeys[3];
                    }
                    return $value;
                default:
                    return $value;
            }
        }

        return $value;

    }

    protected function getWpValue($valueKey, $defaultValue, $data = [])
    {
        if ($valueKey == 'login_url') {
            return network_site_url('wp-login.php', 'login');
        }

        if ($valueKey == 'name') {
            return wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        }

        if ($valueKey == 'url') {
            return home_url('/');
        }

        if ($valueKey == 'portal_url') {
            return Utility::getPortalPageUrl();
        }

        if ($valueKey == 'admin_portal') {
            return Utility::getAdminPageUrl();
        }

        $value = get_bloginfo($valueKey);
        if (!$value) {
            return $defaultValue;
        }
        return $value;
    }

    protected function getUserValue($valueKey, $defaultValue, $userModel = null)
    {
        if (!$userModel || !$userModel instanceof \FluentAffiliate\App\Models\User) {
            return $defaultValue;
        }

        if ($valueKey == 'photo_html') {
            return '<img src="' . esc_url($userModel->photo) . '" alt="' . esc_attr($userModel->display_name) . '" class="fa_user_dynamic_photo" />';
        }

        $wpUser = $userModel->getWpUser();

        if ($valueKey == 'roles') {
            $roles = (array)$wpUser->roles;
            if (empty($roles)) {
                return $defaultValue;
            }

            $roles = array_values($roles);
            // Return roles as a comma-separated string
            return implode(', ', $roles);
        }

        $valueKeys = explode('.', $valueKey);

        if (count($valueKeys) == 1) {
            $value = $wpUser->get($valueKey);
            if (!$value) {
                return $defaultValue;
            }

            if (!is_array($value) || !is_object($value)) {
                return $value;
            }

            return $defaultValue;
        }

        $customKey = $valueKeys[0];
        $customProperty = $valueKeys[1];

        if ($customKey == 'meta') {
            $metaValue = get_user_meta($wpUser->ID, $customProperty, true);
            if (!$metaValue) {
                return $defaultValue;
            }

            if (!is_array($metaValue) || !is_object($metaValue)) {
                return $metaValue;
            }

            return $defaultValue;
        }

        return $defaultValue;
    }

    protected function getAffiliateValue($valueKey, $defaultValue, $affilieteModel = null)
    {

        if (!$affilieteModel || !$affilieteModel instanceof \FluentAffiliate\App\Models\Affiliate) {
            return $defaultValue;
        }

        if ($valueKey == 'edit_url') {
            return Utility::getAdminPageUrl('affiliates/' . $affilieteModel->id);
        }

        if ($valueKey == 'affiliate_link') {
            $queryVar = Utility::getQueryVarName();
            $queryVarValue = Utility::getAffiliateParam($affilieteModel);
            return home_url() . '?' . $queryVar . '=' . $queryVarValue;
        }

        if ($valueKey == 'earning_total_30_days_formatted') {
            $earningTotal = Referral::query()->where('affiliate_id', $affilieteModel->id)
                ->whereIn('status', ['paid', 'unpaid'])
                ->where('created_at', '>=', gmdate('Y-m-d H:i:s', strtotime('-30 days')))
                ->sum('amount');

            /*
             * todo: move to helper
             */
            return Helper::formatMoney($earningTotal);
        }

        if ($valueKey == 'lifetime_earning_formatted') {
            return Helper::formatMoney($affilieteModel->total_earnings);
        }

        if ($valueKey == 'unpaid_earning_formatted') {
            return Helper::formatMoney($affilieteModel->unpaid_earnings);
        }

        $fillables = $affilieteModel->getFillable();

        $fillables[] = 'id';
        $fillables[] = 'created_at';
        $fillables[] = 'updated_at';

        if (in_array($valueKey, $fillables)) {
            $value = $affilieteModel->{$valueKey};
            if (!$value) {
                return $defaultValue;
            }

            if ($valueKey == 'created_at') {
                return $affilieteModel->created_at->format('d M Y, H:i');
            }

            if ($valueKey == 'updated_at') {
                return $affilieteModel->updated_at->format('d M Y, H:i');
            }

            if (!is_array($value) && !is_object($value)) {
                return $value;
            }

            return $defaultValue;
        }

        return $defaultValue;
    }

    protected function getTransactionValue($valueKey, $defaultValue, $transactionModel = null)
    {
        if (!$transactionModel || !$transactionModel instanceof \FluentAffiliate\App\Models\Transaction) {
            return $defaultValue;
        }

        if ($valueKey == 'amount_formatted') {
            return Helper::formatMoney($transactionModel->total_amount);
        }

        if ($valueKey == 'created_at_formatted') {
            return $transactionModel->created_at->format('d M Y, H:i');
        }

        if ($valueKey == 'referrals_count') {
            return Referral::query()->where('payout_transaction_id', $transactionModel->id)
                ->count();
        }

        $fillables = $transactionModel->getFillable();

        $fillables[] = 'id';
        $fillables[] = 'created_at';
        $fillables[] = 'updated_at';

        if (in_array($valueKey, $fillables)) {
            $value = $transactionModel->{$valueKey};
            if (!$value) {
                return $defaultValue;
            }

            if ($valueKey == 'created_at') {
                return $transactionModel->created_at->format('d M Y, H:i');
            }

            if ($valueKey == 'updated_at') {
                return $transactionModel->updated_at->format('d M Y, H:i');
            }

            if (!is_array($value) && !is_object($value)) {
                return $value;
            }

            return $defaultValue;
        }

        return $defaultValue;
    }

    protected function getReferralValue($valueKey, $defaultValue, $referralModel = null)
    {
        if (!$referralModel || !$referralModel instanceof \FluentAffiliate\App\Models\Referral) {
            return $defaultValue;
        }

        if ($valueKey == 'amount_formatted') {
            return Helper::formatMoney($referralModel->amount);
        }

        if ($valueKey == 'created_at_formatted') {
            return $referralModel->created_at->format('d M Y, H:i');
        }

        $fillables = $referralModel->getFillable();

        $fillables[] = 'id';
        $fillables[] = 'created_at';
        $fillables[] = 'updated_at';

        if (in_array($valueKey, $fillables)) {
            $value = $referralModel->{$valueKey};
            if (!$value) {
                return $defaultValue;
            }

            if ($valueKey == 'created_at') {
                return $referralModel->created_at->format('d M Y, H:i');
            }

            if ($valueKey == 'updated_at') {
                return $referralModel->updated_at->format('d M Y, H:i');
            }

            if (!is_array($value) && !is_object($value)) {
                return $value;
            }

            return $defaultValue;
        }

        return $defaultValue;
    }
}
