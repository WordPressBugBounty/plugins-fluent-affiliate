<?php

namespace FluentAffiliate\App\Modules\Integrations\FluentCart;

use FluentAffiliate\App\Helper\Helper;
use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\App\Modules\Integrations\BaseConnector;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Services\URL;
use FluentAffiliate\App\Services\AffiliateService;

class Bootstrap extends BaseConnector
{
    protected $provider = 'fluent_cart';

    public function register()
    {
        if (!$this->isEnabled()) {
            return;
        }

        add_action('fluent_cart/order_created', array($this, 'addPendingReferral'), 99999, 1);
        add_action('fluent_cart/order_paid_done', array($this, 'updateReferralStatus'), 99, 1);
        add_action('fluent_cart/order_refunded', array($this, 'updateReferralStatus'), 99, 1);

        /*
         * Internal
         */
        add_filter('fluent_affiliate/provider_reference_fluent_cart_url', [$this, 'getOrderLink'], 10, 2);
        add_filter('fluent_cart/widgets/single_coupon_page', [$this, 'getCouponWidgets'], 10, 2);
        add_filter('fluent_cart/get_dynamic_search_affiliate', [$this, 'getDynamicSearchAffiliate'], 10, 2);
        add_action('fluent_cart/coupon_created', [$this, 'updateCouponAffiliate'], 10, 1);
        add_action('fluent_cart/coupon_updated', [$this, 'updateCouponAffiliate'], 10, 1);
    }

    public function updateCouponAffiliate($data)
    {
        $coupon = Arr::get($data, 'coupon');
        $affiliateId = Arr::get($data, 'data.metaValue.fa_affiliate_form.name');

        if (!$affiliateId || !$coupon instanceof Coupon) {
            return;
        }

        $affiliate = Affiliate::find($affiliateId);

        if ($affiliate && $affiliate->status === 'active') {
            $coupon->updateMeta('_fa_affiliate_id', $affiliate->id);
        }
    }

    public function getDynamicSearchAffiliate($affiliate, $data)
    {
        $search = Arr::get($data, 'searchBy');

        return AffiliateService::getAffiliatesOptions($search, [], 20, 'value', 'label');
    }

    public function getCouponWidgets($widgets, $data)
    {
        $couponId = Arr::get($data, 'coupon_id');

        $affiliateId = null;
        $coupon = Coupon::find($couponId);
        if ($coupon) {
            $affiliateId = $coupon->getMeta('_fa_affiliate_id');
        }

        $options = AffiliateService::getAffiliatesOptions('', [], 20, 'value', 'label');

        $widgets[] = [
            'title'     => __('Fluent Affiliate', 'fluent-affiliate'),
            'sub_title' => __('Select Affiliate you want to add to cart', 'fluent-affiliate'),
            'type'      => 'form',
            'form_name' => 'fa_affiliate_form',
            'name'      => 'affiliate',
            'schema'    => [
                'name' => [
                    'wrapperClass' => 'col-span-2 flex items-start flex-col',
                    'remote_key'   => 'affiliate',
                    'label'        => __('Select Affiliate', 'fluent-affiliate'),
                    'type'         => 'remote_select',
                    'options'      => $options,
                ],
            ],
            'values'    => [
                'name' => $affiliateId
            ]
        ];

        return $widgets;
    }

    public function addPendingReferral($data = [])
    {
        $order = $data['order'];

        if ($this->getSetting('disable_on_upgrades') === 'yes' && Arr::get($order->config, 'upgraded_from', '')) {
            return;
        }

        if ($order->type === 'renewal' || $this->getExistingReferral($order->id)) {
            return;
        }

        $affiliate = $this->getAffiliateByNewOrder($order);
        if (!$affiliate || $affiliate->status != 'active') {
            return;
        }

        $orderData = $this->getFormattedOrderData($order);

        if (!$orderData) {
            return;
        }

        $customer = $order->customer;

        $customerData = [
            'user_id'    => $customer->user_id,
            'email'      => $customer->email,
            'first_name' => $customer->first_name,
            'last_name'  => $customer->last_name,
            'ip'         => $order->ip_address
        ];

        if ($this->isSelfReferred($affiliate, $customerData)) {
            return;
        }

        $customerData['by_affiliate_id'] = $affiliate->id;
        $affiliatedCustomer = $this->addOrUpdateCustomer($customerData);

        $visit = $this->getCurrentVisit($affiliate);
        $orderData['order_total'] = $this->calculateOrderTotal($orderData);
        $commission = $this->calculateFinalCommissionAmount($affiliate, $orderData, 'product-categories');

        $commission = apply_filters('fluent_affiliate/commission', $commission, [
            'affiliate'    => $affiliate,
            'order_data'   => $orderData,
            'provider'     => $this->provider,
            'vendor_order' => $order,
        ]);

        $formattedItems = Arr::get($orderData, 'items');

        // create a description for the order
        $description = $formattedItems[0]['title'] ?? 'Order';
        if (count($formattedItems) > 1) {
            $description .= ' and ' . (count($formattedItems) - 1) . ' more items';
        }

        $status = 'pending';
        if ($order->payment_status == 'paid') {
            $status = 'unpaid';
        }

        $referralData = [
            'affiliate_id' => $affiliate->id,
            'customer_id'  => $affiliatedCustomer->id,
            'visit_id'     => ($visit) ? $visit->id : null,
            'description'  => $description,
            'status'       => $status,
            'type'         => 'sale',
            'amount'       => $commission,
            'order_total'  => $orderData['order_total'],
            'currency'     => $order->currency,
            'utm_campaign' => ($visit) ? $visit->utm_campaign : '',
            'provider'     => $this->provider,
            'provider_id'  => $order->id,
            'products'     => $formattedItems
        ];

        $referral = $this->recordReferral($referralData);

        if (!$referral) {
            return;
        }

        $affiliateName = $affiliate->user ? $affiliate->user->display_name : 'unknown';
        $amountLink = '<a target="_blank" rel="noopener" href="' . Utility::getAdminPageUrl('referrals/' . $referral->id . '/view') . '">' . Helper::formatMoney($referral->amount, $referral->currency) . '</a>';

        // translators: %1$s is the affiliate name, %2$s is the referral amount with link
        $description = sprintf(__('Referral has been created for affiliate: %1$s. Amount: %2$s', 'fluent-affiliate'), $affiliateName, $amountLink);

        $order->addLog(__('Fluent Affiliate referral created.', 'fluent-affiliate'), $description);
    }

    public function updateReferralStatus($data = [])
    {
        $order = $data['order'];
        $referral = Referral::where('provider', $this->provider)->where('provider_id', $order->id)->first();

        if (!$referral || $referral->status == 'paid' || $referral->status == 'rejected') {
            return;
        }

        $status = $order->payment_status;

        if ($status == 'paid') {
            $this->markReferralAsUnpaid($referral);
        } else if ($status == 'refunded') {
            $this->rejectReferral($referral);
            $children = Referral::where('parent_id', $referral->id)
                ->whereIn('status', ['pending', 'unpaid'])
                ->get();
            foreach ($children as $child) {
                $this->rejectReferral($child);
            }
        } else if ($status == 'partially_refunded') {
            $this->updateReferralCommission($referral, $order);
        }
    }

    public function getOrderLink($link, $referral)
    {
        return URL::getDashboardUrl('orders/' . $referral->provider_id . '/view');
    }

    private function getAffiliateByNewOrder(Order $order)
    {
        if (!$order->coupon_discount_total) {
            return $this->getCurrentAffiliate();
        }

        foreach ($order->usedCoupons as $coupon) {
            $affiliateId = $coupon->getMeta('_fa_affiliate_id');
            if ($affiliateId) {
                $affiliate = Affiliate::find($affiliateId);
                if ($affiliate && $affiliate->status == 'active') {
                    return $affiliate;
                }
            }
        }

        return $this->getCurrentAffiliate();
    }

    protected function getFormattedOrderData(Order $order)
    {
        $formattedItems = [];
        foreach ($order->order_items as $item) {
            if (!$item->post_id) {
                continue;
            }

            $formattedItems[] = [
                'item_id'  => $item->post_id,
                'title'    => $item->post_title,
                'subtotal' => $this->centsToDecimal($item->subtotal, $order->currency),
                'tax'      => $this->centsToDecimal($item->tax_amount, $order->currency),
                'discount' => $this->centsToDecimal($item->discount_total, $order->currency),
                'total'    => $this->centsToDecimal($item->line_total, $order->currency)
            ];
        }

        $data = [
            'id'       => $order->id,
            'status'   => $order->payment_status,
            'subtotal' => $this->centsToDecimal($order->subtotal, $order->currency),
            'tax'      => $this->centsToDecimal($order->tax_total, $order->currency),
            'discount' => $this->centsToDecimal(($order->discount_total + $order->coupon_discount_total), $order->currency),
            'shipping' => $this->centsToDecimal($order->shipping_total, $order->currency),
            'total'    => $this->centsToDecimal($order->total_amount, $order->currency),
            'items'    => $formattedItems
        ];

        $data['referral_order_total'] = $this->calculateOrderTotal($data);

        $data = apply_filters('fluent_affiliate/formatted_order_data_by_' . $this->provider, $data, $order);


        return $data;
    }
}
