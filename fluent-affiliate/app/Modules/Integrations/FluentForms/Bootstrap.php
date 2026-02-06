<?php

namespace FluentAffiliate\App\Modules\Integrations\FluentForms;

use FluentAffiliate\App\Models\Referral;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\Framework\Support\Arr;
use FluentAffiliate\App\Modules\Integrations\BaseConnector;
use FluentAffiliate\App\Helper\Utility;

class Bootstrap extends BaseConnector
{
    protected $provider = 'fluent_forms';

    public function register()
    {
        if (!$this->isEnabled()) {
            return;
        }

        add_action('fluentform/process_payment', array($this, 'markReferralAsPending'), 10, 3);

        add_action('fluentform/payment_failed', array($this, 'markReferralAsRevoked'), 10, 3);
        add_action('fluentform/payment_refunded', array($this, 'markReferralAsRevoked'), 10, 3);
        add_action('fluentform/payment_cancelled', array($this, 'markReferralAsRevoked'), 10, 3);

        add_action('fluentform/after_transaction_status_change', [$this, 'maybeMarkReferralComplete'], 10, 3);

        // add_action('fluentform/subscription_payment_success', array($this, 'markSubscriptionReferralAsComplete'), 99, 3);

        /*
         * Internal
         */
        add_filter('fluent_affiliate/provider_reference_fluent_forms_url', [$this, 'getSubmissionLink'], 10, 2);
    }

    public function maybeMarkReferralComplete($newStatus, $submission, $transactionId)
    {
        $referral = $this->getExistingReferral($submission->id);

        if (!$referral || in_array($referral->status, ['paid', 'rejected'])) {
            return;
        }

        if ($newStatus === 'paid') {
            $this->markReferralAsUnpaid($referral);
        } elseif (in_array($newStatus, ['failed', 'cancelled', 'refunded'])) {
            $this->rejectReferral($referral);
        }
    }

    public function markReferralAsPending($submissionId, $submission, $form)
    {
        if (!$form->has_payment) {
            return;
        }

        $referral = $this->getExistingReferral($submissionId);

        if ($referral) {
            if (!in_array($referral->status, ['paid', 'rejected'])) {
                $this->markReferralAsUnpaid($referral);
            }
            return;
        }

        $this->addPaymentReferral($submissionId, $submission, $form);
    }

    public function markReferralAsRevoked($submissionId, $submission, $form)
    {
        $referral = $this->getExistingReferral($submissionId);

        if ($referral) {
            $this->rejectReferral($referral);
        }
    }

    protected function addPaymentReferral($submissionId, $submission, $form, $type = 'payment')
    {
        $affiliate = $this->getCurrentAffiliate();

        if (!$affiliate) {
            return;
        }

        if ($this->getExistingReferral($submissionId)) {
            return; // Referral already exists
        }

        $formattedData = $this->getFormattedPaymentData($submissionId, $submission, $form);
        $customerData = Arr::get($formattedData, 'customer', []);

        if ($this->isSelfReferred($affiliate, $customerData)) {
            return; // Do not create referral for self-referrals
        }

        $customerData['by_affiliate_id'] = $affiliate->id;
        $createdCustomer = $this->addOrUpdateCustomer($customerData);
        $visit = $this->getCurrentVisit($affiliate);
        $commission = $this->calculateFinalCommissionAmount($affiliate, $formattedData);

        if ($commission <= 0) {
            return;
        }

        $description = $form->title;

        $referralData = [
            'affiliate_id' => $affiliate->id,
            'customer_id'  => $createdCustomer->id,
            'visit_id'     => $visit ? $visit->id : null,
            'description'  => $description,
            'status'       => ($formattedData['status'] === 'paid') ? 'unpaid' : 'pending',
            'type'         => 'payment',
            'amount'       => $commission,
            'order_total'  => $formattedData['referral_order_total'],
            'currency'     => Arr::get($formattedData, 'currency'),
            'utm_campaign' => $visit ? $visit->utm_campaign : null,
            'provider'     => $this->provider,
            'provider_id'  => $submissionId,
            'products'     => $formattedData['items'],
        ];

        $this->recordReferral($referralData);
    }

    protected function getFormattedPaymentData($submissionId, $submission, $form)
    {
        $customerData = $this->getCustomerDataFromSubmission($submission, $form);
        $paymentTotal = floatval($submission['payment_total'] ?? 0);
        $paymentTotal = round($paymentTotal / 100, 2);
        $currency = $this->getFormCurrency($form->id);

        return [
            'id'                   => $submissionId,
            'total'                => $paymentTotal,
            'subtotal'             => $paymentTotal,
            'referral_order_total' => $paymentTotal,
            'tax'                  => 0,
            'discount'             => 0,
            'status'               => $submission['payment_status'] ?? 'pending',
            'currency'             => $currency,
            'customer'             => $customerData,
            'items'                => [
                [
                    'item_id'  => $form->id,
                    'title'    => $form->title,
                    'subtotal' => $paymentTotal,
                    'tax'      => 0,
                    'discount' => 0,
                    'total'    => $paymentTotal
                ]
            ],
        ];
    }

    /**
     * Get customer data using submission array structure
     */
    protected function getCustomerDataFromSubmission($submission, $form)
    {
        $customerData = [];

        // Extract data directly from submission array since we have all the data
        $response = Arr::get($submission, 'response', []);

        if (!empty($submission['user_id'])) {
            $user = get_user_by('ID', $submission['user_id']);
            if ($user) {
                return [
                    'user_id'    => $user->ID,
                    'email'      => $user->user_email,
                    'first_name' => $user->first_name,
                    'last_name'  => $user->last_name,
                    'ip'         => $submission['ip'],
                ];
            }
        }

        // Extract email
        if (!empty($response['email'])) {
            $customerData['email'] = sanitize_email($response['email']);
        }

        // Extract name from names array
        if (!empty($response['names']) && is_array($response['names'])) {
            if (!empty($response['names']['first_name'])) {
                $customerData['first_name'] = sanitize_text_field($response['names']['first_name']);
            }
            if (!empty($response['names']['last_name'])) {
                $customerData['last_name'] = sanitize_text_field($response['names']['last_name']);
            }
        }

        // Add IP address
        if (!empty($submission['ip'])) {
            $customerData['ip'] = sanitize_text_field($submission['ip']);
        }

        if (empty($customerData['email'])) {
            foreach ($response as $key => $value) {
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $customerData['email'] = sanitize_email($value);
                    break; // Stop after finding the first valid email
                }
            }
        }

        return array_filter($customerData);
    }


    protected function getFormCurrency($formId)
    {
        if (class_exists('\FluentForm\App\Modules\Payments\PaymentHelper')) {
            return \FluentForm\App\Modules\Payments\PaymentHelper::getFormCurrency($formId);
        }
        return Utility::getCurrency();
    }

    protected function getPaymentReferralStatus($submission, $settings)
    {
        if (isset($submission['payment_status']) && $submission['payment_status'] === 'paid') {
            return 'unpaid';
        }

        return 'pending';
    }

    public function getSubmissionLink($url, $referral)
    {
        if ($referral->provider !== $this->provider) {
            return $url;
        }

        // Get the submission to find the form_id
        $submission = \FluentForm\App\Models\Submission::find($referral->provider_id);

        if (!$submission) {
            return $url;
        }

        return admin_url(sprintf(
            'admin.php?page=fluent_forms&form_id=%d&route=entries#/entries/%d',
            $submission->form_id,
            $referral->provider_id
        ));
    }
}
