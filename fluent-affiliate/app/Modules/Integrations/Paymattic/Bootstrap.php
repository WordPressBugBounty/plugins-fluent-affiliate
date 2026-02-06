<?php

namespace FluentAffiliate\App\Modules\Integrations\Paymattic;

use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\App\Modules\Integrations\BaseConnector;

class Bootstrap extends BaseConnector
{
    protected $provider = 'paymattic';

    public function register()
    {
        if (!$this->isEnabled()) {
            return;
        }

        add_action('wppayform/after_transaction_data_insert', [$this, 'addPeningReferral'], 10, 2);
        add_action('wppayform/after_payment_status_change', [$this, 'handlePaymentStatusChanged'], 10, 2);

        add_action('wppayform/form_payment_success', function ($submission) {
            $this->handlePaymentStatusChanged($submission->id, $submission->payment_status);
        }, 10, 1);

        add_action('fluent_affiliate/provider_reference_paymattic_url', [$this, 'getTransactionLink'], 10, 2);
    }

    public function addPeningReferral($transactionId, $transactionData)
    {
        $affiliate = $this->getCurrentAffiliate();
        if (!$affiliate) {
            return;
        }

        if ($this->getExistingReferral($transactionData['submission_id'])) {
            return; // Referral already exists for this payment
        }

        $payment = (new \WPPayForm\App\Models\Submission())->getSubmission($transactionData['submission_id'], ['tax_items', 'discount'])->toArray();

        $payment_total = Arr::get($payment, 'payment_total', 0);
        if (!$payment || !Arr::get($payment, 'id') || $payment_total <= 0) {
            return; // Ensure payment exists
        }

        $formattedData = $this->getFormattedOrderData($payment);

        $customerData = $formattedData['customer'];
        if ($this->isSelfReferred($affiliate, $customerData)) {
            return; // Do not create referral for self-referrals
        }
        $customerData['by_affiliate_id'] = $affiliate->id;
        $createdCustomer = $this->addOrUpdateCustomer($customerData);

        $visit = $this->getCurrentVisit($affiliate);
        $commission = $this->calculateFinalCommissionAmount($affiliate, $formattedData);

        $referralData = [
            'affiliate_id' => $affiliate->id,
            'customer_id'  => $createdCustomer->id,
            'visit_id'     => $visit ? $visit->id : null,
            'description'  => Arr::get($payment, 'post_title', ''),
            'status'       => Arr::get($payment, 'payment_status') == 'paid' ? 'unpaid' : 'pending',
            'type'         => 'sale',
            'amount'       => $commission,
            'order_total'  => $formattedData['referral_order_total'],
            'currency'     => Arr::get($formattedData, 'currency'),
            'utm_campaign' => $visit ? $visit->utm_campaign : null,
            'provider'     => $this->provider,
            'provider_id'  => $transactionData['submission_id'],
            'products'     => $formattedData['items'],
        ];

        $this->recordReferral($referralData);
    }

    public function handlePaymentStatusChanged($submissionId, $newStatus)
    {
        $referral = $this->getExistingReferral($submissionId);
        if (!$referral) {
            return; // Referral already exists for this payment
        }

        $paidStatuses = ['paid', 'processing'];
        $refundStatuses = ['refunded', 'revoked', 'failed'];

        if (in_array($newStatus, $paidStatuses)) {
            $this->markReferralAsUnpaid($referral);
        } else if (in_array($newStatus, $refundStatuses)) {
            $this->rejectReferral($referral);
        }

    }

    public function getTransactionLink($link, $referral)
    {

        $submission = (new \WPPayForm\App\Models\Submission())->find($referral->provider_id);

        if (!$submission) {
            return $link; // Return original link if submission not found
        }

        return "admin.php?page=wppayform.php#/edit-form/{$submission->form_id}/entries/{$submission->id}/view";
    }

    private function getFormattedOrderData($payment)
    {
        $taxAmount = $this->getTotalTaxAmount($payment);
        $discountAmount = $this->getTotalDiscountAmount($payment);

        $data = [
            'id'       => Arr::get($payment, 'id'),
            // Payment total with tax amount  is in cents
            'total'    => Arr::get($payment, 'payment_total', 0) / 100,
            'subtotal' => (Arr::get($payment, 'payment_total', 0) - $discountAmount - $taxAmount) / 100,
            'tax'      => $taxAmount / 100,
            'discount' => $discountAmount / 100,
            'status'   => Arr::get($payment, 'payment_status'),
            'currency' => Arr::get($payment, 'currency', ''),
            'customer' => array_filter([
                'email'      => Arr::get($payment, 'customer_email', ''),
                'first_name' => Arr::get($payment, 'customer_name', ''),
                'last_name'  => '',
                'ip'         => Arr::get($payment, 'ip_address', ''),
                'user_id'    => Arr::get($payment, 'user_id', '')
            ]),
            'items'    => [
                [
                    'item_id'  => Arr::get($payment, 'form_id'),
                    'title'    => Arr::get($payment, 'post_title', ''),
                    'subtotal' => (Arr::get($payment, 'payment_total', 0) - $discountAmount - $taxAmount) / 100,
                    'tax'      => $taxAmount / 100,
                    'shipping' => 0,
                    'total'    => Arr::get($payment, 'payment_total', 0) / 100,
                ]
            ]
        ];

        $data['referral_order_total'] = $this->calculateOrderTotal($data);

        return $data;
    }

    private function getTotalTaxAmount($payment)
    {
        $taxAmount = 0;

        if (!Arr::get($payment, 'tax_items', [])) {
            return 0;
        }

        foreach (Arr::get($payment, 'tax_items', []) as $taxItem) {
            $taxAmount += Arr::get($taxItem, 'line_total', 0);
        }

        return $taxAmount;
    }

    private function getTotalDiscountAmount($payment)
    {
        if (!Arr::get($payment, 'discounts', [])) {
            return 0;
        }
        return Arr::get($payment, 'discounts.total', 0);
    }

}
