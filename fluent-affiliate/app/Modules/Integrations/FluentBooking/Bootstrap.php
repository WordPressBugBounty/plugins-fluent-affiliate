<?php

namespace FluentAffiliate\App\Modules\Integrations\FluentBooking;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Modules\Integrations\BaseConnector;
use FluentAffiliate\App\Modules\Tracker\Track;
use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\CalendarSlot;
use FluentBookingPro\App\Models\Order;
use FluentAffiliate\App\Vite;

class Bootstrap extends BaseConnector
{

    protected $provider = 'fluent_booking';

    public function register()
    {
        if (!$this->isEnabled()) {
            return;
        }

        add_action('fluent_booking/after_draft_order_created', [$this, 'storeBookingReference'], 10, 4);
        add_action('fluent_booking/payment/status_changed', [$this, 'updateReferralStatus'], 10, 3);
        add_action('fluent_affiliate/provider_reference_fluent_booking_url', [$this, 'getBookingLink'], 10, 2);

        // add js to the frameless landing pages
        add_filter('fluent_booking/host_view_page_vars', [$this, 'addJsToLandingPage'], 10, 1);
        add_filter('fluent_booking/event_landing_page_vars', [$this, 'addJsToLandingPage'], 10, 1);
    }

    public function addJsToLandingPage($vars)
    {

        if (!apply_filters('fluent_affiliate/will_load_tracker_js', true)) {
            return $vars;
        }

        $vars['js_files']['fluent_aff_js'] = Vite::scriptUrl('fluent_aff');
        $vars['js_vars']['fluent_aff_vars'] = (new Track())->getFluentAffVars();

        return $vars;
    }

    public function storeBookingReference(Order $order, Booking $booking, CalendarSlot $calendarSlot, $bookingData)
    {
        $affiliate = $this->getCurrentAffiliate([
            'user_id' => $booking->person_user_id,
            'email'   => $booking->email,
        ]);
        if ((!$affiliate || $affiliate->status != 'active') || $this->getExistingReferral($booking->id)) {
            return;
        }

        $customerData = [
            'user_id'    => $booking->person_user_id,
            'email'      => $booking->email,
            'first_name' => $booking->first_name,
            'last_name'  => $booking->last_name,
            'ip'         => $order->ip_address
        ];

        $orderTotal = $this->centsToDecimal($order->total_amount, $order->currency);

        $description = $booking->calendar->title . '(' . $calendarSlot->title . ')';
        $formattedItems = [
            [
                'item_id'  => $calendarSlot->id,
                'title'    => $description,
                'subtotal' => $orderTotal,
                'tax'      => 0,
                'discount' => 0,
                'total'    => $orderTotal
            ]
        ];

        $orderData = [
            'id'                   => $order->id,
            'items'                => $formattedItems,
            'currency'             => $order->currency,
            'referral_order_total' => $orderTotal,
            'description'          => $description,
        ];

        $status = 'pending';
        if ($order->status == 'paid') {
            $status = 'unpaid';
        }

        $referral = $this->createReferralForOrder($affiliate, $customerData, $orderData, [
            'provider_id'  => $booking->id,
            'description'  => $booking->calendar->title,
            'status'       => $status,
            'type'         => 'sale',
            'vendor_order' => $order,
        ]);

        if (!$referral) {
            return;
        }

        $referralLink = Utility::getAdminPageUrl('referrals/' . $referral->id . '/view');

        $note = \sprintf(
        // translators: %1$s: referral link, %2$s: referral amount, %3$s: affiliate name, %4$d: affiliate id
            __('Referral %1$s for %2$s recorded for %3$s (ID: %4$d).', 'fluent-affiliate'),
            '<a href="' . $referralLink . '" target="_blank">' . $referral->id . '</a>',
            $order->currency . ' ' . $referral->amount,
            $affiliate->full_name,
            $affiliate->id
        );

        do_action('fluent_booking/log_booking_activity', [
            'status'      => 'info',
            'type'        => 'info',
            'title'       => __('[FluentAffiliate] Referral Created', 'fluent-affiliate'),
            'description' => $note,
            'booking_id'  => $booking->id
        ]);

    }

    public function updateReferralStatus(Order $order, Booking $booking, $newStatus)
    {
        $referral = $this->getExistingReferral($booking->id);
        if (!$referral) {
            return;
        }

        if (in_array($referral->status, ['paid', 'rejected'])) {
            return;
        }

        if ($newStatus == 'paid') {
            $this->markReferralAsUnpaid($referral);
        } else if ($newStatus == 'refunded') {
            $this->rejectReferral($referral);
        }

    }

    /**
     * Get the booking link
     *
     *
     * @return string
     */
    public function getBookingLink($link, $referral)
    {
        return admin_url('admin.php?page=fluent-booking#/scheduled-events?period=upcoming&booking_id=' . $referral->provider_id);
    }
}
