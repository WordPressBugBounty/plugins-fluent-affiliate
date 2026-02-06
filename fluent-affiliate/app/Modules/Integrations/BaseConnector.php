<?php

namespace FluentAffiliate\App\Modules\Integrations;

use FluentAffiliate\App\Helper\Action;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Customer;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\Framework\Support\Arr;

class BaseConnector
{
    protected $provider = '';

    public function getCurrentVisit($affiliate = null)
    {
        $visit = Utility::getCurrentCookieVisit();

        if ($visit && $affiliate) {
            if ($visit->affiliate_id != $affiliate->id) {
                return null;
            }
        }

        return $visit;
    }

    public function getCurrentAffiliate()
    {
        return Utility::getCurrentCookieAffiliate();
    }

    public function addOrUpdateCustomer($data = [])
    {
        if (!isset($data['user_id'])) {
            if ($user = get_user_by('user_email', $data['email'])) {
                $data['user_id'] = $user->ID;
            }
        }

        $exist = Customer::where('email', $data['email'])
            ->when(!empty($data['user_id']), function ($query) use ($data) {
                return $query->orWhere('user_id', $data['user_id']);
            })
            ->first();

        if ($exist) {
            unset($data['by_affiliate_id']);
            $exist->fill($data);
            $exist->save();
            return $exist;
        }

        $customer = new Customer;
        $customer->fill($data);
        $customer->save();
        return $customer;
    }

    public function getExistingReferral($providerId)
    {
        return Referral::where('provider', $this->provider)->where('provider_id', $providerId)->first();
    }

    /**
     * Records a new referral.
     * @return Referral
     */
    public function recordReferral($data)
    {
        $amount = Arr::get($data, 'amount', 0);
        $type = Arr::get($data, 'type', 'sale');

        if ($amount <= 0 && in_array($type, ['sale', 'recurring_sale']) && apply_filters('fluent_affiliate/ignore_zero_amount_referral', true, $data)) {
            return null;
        }

        $referral = new Referral;
        $referral->fill($data);
        $referral->save();

        $affiliate = Affiliate::find($referral->affiliate_id);

        if (!$affiliate) {
            return $referral;
        }

        if ($referral->status == 'unpaid') {
            $affiliate->recountEarnings();
            do_action('fluent_affiliate/referral_marked_unpaid', $referral);
        } else {
            $affiliate->increase('referrals');
            do_action('fluent_affiliate/referral_created', $referral);
        }

        return $referral;
    }

    /**
     * @return null|Referral
     *
     * @action fluent_affiliate/referral_rejected
     */
    public function rejectReferral($referral, $reason = '')
    {
        $validStatuses = ['pending', 'unpaid'];
        if (in_array($referral->status, $validStatuses)) {
            $referral->status = 'rejected';
            $referral->save();

            $affiliate = Affiliate::find($referral->affiliate_id);
            $affiliate && $affiliate->recountEarnings();

            do_action('fluent_affiliate/referral_marked_rejected', $referral);
        }

        return $referral;
    }

    public function markReferralAsUnpaid($referral)
    {
        $validStatuses = ['pending'];
        if (in_array($referral->status, $validStatuses)) {
            $referral->status = 'unpaid';
            $referral->save();

            $affiliate = Affiliate::find($referral->affiliate_id);
            $affiliate && $affiliate->recountEarnings();

            do_action('fluent_affiliate/referral_marked_unpaid', $referral);
        }

        return $referral;
    }

    public function updateReferralCommission($referral, $order)
    {
        $validStatuses = ['unpaid'];
        if (!in_array($referral->status, $validStatuses)) {
            return $referral;
        }

        $formattedOrderData = [
            'subtotal' => $this->centsToDecimal($order->subtotal, $order->currency),
            'tax'      => $this->centsToDecimal($order->tax_total, $order->currency),
            'discount' => $this->centsToDecimal(($order->discount_total + $order->coupon_discount_total), $order->currency),
            'shipping' => $this->centsToDecimal($order->shipping_total, $order->currency),
        ];

        $totalRefund = $this->centsToDecimal($order->total_refund, $order->currency);

        $currentOrderTotal = $referral->order_total;
        $updatedOrderTotal = $this->calculateOrderTotal($formattedOrderData) - $totalRefund;

        $reductionRatio = $updatedOrderTotal / $currentOrderTotal;
        $updatedCommission = round($referral->amount * $reductionRatio, 2);

        $referral->amount = max(0, $updatedCommission);
        $referral->order_total = max(0, $updatedOrderTotal);
        $referral->save();

        $affiliate = Affiliate::find($referral->affiliate_id);
        $affiliate && $affiliate->recountEarnings();

        do_action('fluent_affiliate/referral_commission_updated', $referral);

        return $referral;
    }

    /**
     * @todo: need to be refactored after Marzan't settings
     * @return mixed|string[]
     */
    public function getConfig()
    {
        return Utility::getOption('_' . $this->provider . '_connector_config', []);
    }

    public function getSetting($key)
    {
        return Arr::get($this->getConfig(), $key);
    }

    public function isEnabled()
    {
        return Utility::isConnectorEnabled($this->provider);
    }

    public function isSelfReferred(Affiliate $affiliate, $customerData = [])
    {
        if (Utility::getReferralSetting('self_referral_disabled') !== 'no') {
            return false;
        }

        $email = Arr::get($customerData, 'email', '');
        if ($email == $affiliate->payment_email || $email == $affiliate->user->user_email) {
            return true;
        }

        if (!empty($customerData['user_id']) && $customerData['user_id'] == $affiliate->user_id) {
            return true;
        }

        return false;
    }

    public function calculateOrderTotal($totals = [])
    {
        $defaults = [
            'subtotal' => 0,
            'tax'      => 0,
            'shipping' => 0,
            'discount' => 0,
        ];

        $totals = wp_parse_args($totals, $defaults);

        $refSettings = Utility::getReferralSettings();

        $total = $totals['subtotal'];

        if ($refSettings['exclude_shipping'] == 'no') {
            $total += $totals['shipping'];
        }

        if ($refSettings['exclude_tax'] == 'no') {
            $total += $totals['tax'];
        }

        $total -= $totals['discount'];

        return max(0, $total);
    }

    public function centsToDecimal($cents, $currency = '')
    {
        $zeroDecimalCurrencies = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

        $currency = strtoupper($currency);

        if (in_array($currency, $zeroDecimalCurrencies)) {
            return $cents;
        }

        return number_format($cents / 100, 2, '.', '');
    }

    function getPostTermsMaps($postIds, $taxonomy)
    {
        $db = Utility::getApp('db');
        $items = $db->table('term_relationships')
            ->select(['term_taxonomy.term_id', 'term_relationships.object_id'])
            ->whereIn('term_relationships.object_id', $postIds)
            ->join('term_taxonomy', 'term_taxonomy.term_taxonomy_id', '=', 'term_relationships.term_taxonomy_id')
            ->where('term_taxonomy.taxonomy', $taxonomy)
            ->get();

        $maps = [];

        foreach ($items as $item) {
            if (!isset($maps[$item->object_id])) {
                $maps[$item->object_id] = [];
            }

            $maps[$item->object_id][] = $item->term_id;
        }

        return $maps;
    }

    protected function calculateCustomCommission($total, $config = [])
    {
        $rate = Arr::get($config, 'rate');
        $rateType = Arr::get($config, 'type', 'percentage');

        if ($rateType == 'percentage') {
            $commission = ($total * $rate) / 100;
        } else {
            $commission = $rate;
        }

        return $commission < 0 ? 0 : $commission;
    }

    public function hasCustomConfigRate()
    {
        $config = $this->getConfig();
        return (Arr::get($config, 'custom_affiliate_rate') === 'yes') && (Arr::get($config, 'watched_product_ids') || Arr::get($config, 'watched_cat_ids'));
    }

    public function calculateFinalCommissionAmount($affiliate, $orderData, $taxonomy = '')
    {
        $referralOrderTotal = Arr::get($orderData, 'referral_order_total');

        $config = $this->getConfig();

        $hasCalculatedRate = (Arr::get($config, 'custom_affiliate_rate') === 'yes') && (Arr::get($config, 'watched_product_ids') || Arr::get($config, 'watched_cat_ids'));

        if (!$hasCalculatedRate) {
            return $affiliate->getCommission($referralOrderTotal, 'sale');
        }

        $orderedProductIds = array_map(function ($item) {
            return $item['item_id'];
        }, $orderData['items']);


        $orderedProductIds = array_filter($orderedProductIds);

        if (!$orderedProductIds) {
            return $affiliate->getCommission($referralOrderTotal, 'sale');
        }

        $watchedCatIds = Arr::get($config, 'watched_cat_ids', []);

        if ($watchedCatIds && $taxonomy) {
            $termMaps = $this->getPostTermsMaps($orderedProductIds, $taxonomy);
        } else {
            $termMaps = [];
        }

        $keyedCustomRates = [];
        $customRates = Arr::get($config, 'custom_affiliate_rates', []);

        foreach ($customRates as $rate) {
            if ($rate['object_type'] == 'product') {
                $intersectedIds = array_intersect($rate['object_ids'], $orderedProductIds);
                if (!$intersectedIds) {
                    continue;
                }
                foreach ($intersectedIds as $productId) {
                    if (isset($keyedCustomRates[$productId])) {
                        continue; // Skip if already set for this product
                    }
                    $keyedCustomRates[$productId] = [
                        'rate' => $rate['rate'],
                        'type' => $rate['rate_type']
                    ];
                }
            } else if ($rate['object_type'] == 'category') {
                $catObjects = Arr::get($rate, 'object_ids', []);
                foreach ($termMaps as $productId => $termsIds) {
                    if (isset($keyedCustomRates[$productId])) {
                        continue; // Skip if already set for this product
                    }

                    $intersectedTerms = array_intersect($termsIds, $catObjects);
                    if (!$intersectedTerms) {
                        continue;
                    }

                    $keyedCustomRates[$productId] = [
                        'rate' => $rate['rate'],
                        'type' => $rate['rate_type']
                    ];
                }
            }
        }

        $byRulesCommissionTotal = 0;

        foreach ($orderData['items'] as $item) {
            $itemId = (string)Arr::get($item, 'item_id');
            if (!isset($keyedCustomRates[$itemId])) {
                continue;
            }

            $rateConfig = $keyedCustomRates[$itemId];

            $itemTotal = $this->calculateOrderTotal($item);

            $byRulesCommissionTotal += $this->calculateCustomCommission($itemTotal, $rateConfig);
            $referralOrderTotal -= $itemTotal;
        }

        if ($referralOrderTotal <= 0) {
            return $byRulesCommissionTotal;
        }

        return $affiliate->getCommission($referralOrderTotal, 'sale') + $byRulesCommissionTotal;
    }

    protected function isCouponMapEnabled()
    {
        $config = $this->getConfig();
        return (Arr::get($config, 'affiliate_on_discount_product') === 'yes');
    }
}
