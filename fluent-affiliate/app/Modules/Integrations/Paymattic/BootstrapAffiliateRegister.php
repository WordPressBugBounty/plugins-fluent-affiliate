<?php

namespace FluentAffiliate\App\Modules\Integrations\Paymattic;

use FluentAffiliate\App\Models\User;
use FluentAffiliate\App\Modules\Auth\AuthHelper;
use FluentAffiliate\Framework\Support\Arr;
use \WPPayForm\App\Services\Integrations\IntegrationManager;
use \WPPayForm\Framework\Foundation\App;
use \WPPayForm\App\Models\Meta;

class BootstrapAffiliateRegister extends IntegrationManager
{
    public $hasGlobalMenu = false;
    public $disableGlobalSettings = 'yes';

    public function __construct()
    {
        parent::__construct(
            App::getInstance(),
            'Fluent Affiliate Registration',
            'fluent_affiliate',
            '_wppayform_fluent_affiliate_settings',
            'fluent_affiliate_registration',
            10
        );

        $this->logo = FLUENT_AFFILIATE_URL . 'assets/images/FluentAffiliateLogo.svg';
        $this->description = __('Connect Paymattic with Fluent Affiliate for Affiliate Registration', 'fluent-affiliate');

        $this->registerAdminHooks();
        add_filter('wppayform_notifying_async_fluent_affiliate', '__return_false');
    }

    public function getIntegrationDefaults($settings, $formId): array
    {
        $fields = [
            'rate_type'          => 'default',
            'affiliate_status'   => 'active',
            'user_id'            => '',
            'payment_email'      => '',
            'user_email'         => '',
            'note'               => '',
            'new_user_create'    => true,
            'password'           => '',
            'username'           => '',
            'full_name'          => '',
            'trigger_on_payment' => false,
            'conditionals'       => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'enabled'            => true
        ];

        return apply_filters('fluent_affiliate/wppayform__defaults', $fields, $formId);
    }

    public function getMergeFields($list, $listId, $formId): array
    {
        return [];
    }

    public function getSettingsFields($settings, $formId): array
    {
        return [
            'fields'              => [
                [
                    'key'         => 'affiliate_status',
                    'label'       => __('Affiliate Status', 'fluent-affiliate'),
                    'placeholder' => __('Choose options', 'fluent-affiliate'),
                    'required'    => true,
                    'component'   => 'radio_choice',
                    'tips'        => __('Select the status for the affiliate', 'fluent-affiliate'),
                    'options'     => [
                        'active'   => __('Active', 'fluent-affiliate'),
                        'pending'  => __('Pending', 'fluent-affiliate'),
                        'inactive' => __('Inactive', 'fluent-affiliate')
                    ],
                ],
                [
                    'key'       => 'note',
                    'required'  => false,
                    'label'     => __('Note', 'fluent-affiliate'),
                    'tips'      => __('Set the note for the affiliate', 'fluent-affiliate'),
                    'component' => 'text',
                ],
                [
                    'key'                => 'CustomFields1',
                    'require_list'       => false,
                    'label'              => __('Payment Email', 'fluent-affiliate'),
                    'tips'               => __('Merge the payment email field from the form', 'fluent-affiliate'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('Payment Email', 'fluent-affiliate'),
                    'field_label_local'  => __('Form Field', 'fluent-affiliate'),
                    'primary_fileds'     => [
                        [
                            'key'           => 'payment_email',
                            'label'         => __('Payment Email Address', 'fluent-affiliate'),
                            'required'      => true,
                            'input_options' => 'emails'
                        ],
                    ]
                ],
                $this->getMapFieldsField(),
                [
                    'key'            => 'trigger_on_payment',
                    'require_list'   => false,
                    'checkbox_label' => __('Create affiliate program on payment success', 'fluent-affiliate'),
                    'component'      => 'checkbox-single'
                ],
                [
                    'require_list' => false,
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'fluent-affiliate'),
                    'tips'         => __('Allow this integration conditionally based on your submission values', 'fluent-affiliate'),
                    'component'    => 'conditional_block'
                ]
            ],
            'button_require_list' => false,
            'integration_title'   => $this->title
        ];
    }

    /**
     * Get map fields configuration
     */
    private function getMapFieldsField(): array
    {
        return [
            'key'                => 'CustomFields',
            'require_list'       => false,
            'label'              => __('Map Fields for WP Users (if submitter is logged in, this data will be ignored)', 'fluent-affiliate'),
            'tips'               => __('A new user will be created if no user is found based on the provided email address, Associate your user registration fields to the appropriate Paymattic fields by selecting the appropriate form field from the list.', 'fluent-affiliate'),
            'component'          => 'map_fields',
            'field_label_remote' => __('User Registration Field', 'fluent-affiliate'),
            'field_label_local'  => __('Form Field', 'fluent-affiliate'),
            'primary_fileds'     => [
                [
                    'key'           => 'user_email',
                    'label'         => __('User Email Address', 'fluent-affiliate'),
                    'required'      => true,
                    'input_options' => 'emails'
                ],
                [
                    'key'       => 'username',
                    'label'     => __('User name', 'fluent-affiliate'),
                    'required'  => false,
                    'help_text' => __('Keep empty if you want the username and user email is the same', 'fluent-affiliate'),
                ],
                [
                    'key'   => 'full_name',
                    'label' => __('Full Name', 'fluent-affiliate')
                ],
                [
                    'key'       => 'password',
                    'label'     => __('Password', 'fluent-affiliate'),
                    'help_text' => __('Keep empty to be auto generated', 'fluent-affiliate'),
                ],
            ]
        ];
    }


    public function pushIntegration($integrations, $formId): array
    {
        $integrations[$this->integrationKey] = [
            'category'                => 'wp_core',
            'disable_global_settings' => 'yes',
            'logo'                    => $this->logo,
            // translators: %s is the integration name
            'title'                   => sprintf(__('%s Integration', 'fluent-affiliate'), 'Fluent Affiliate Registration'),
            'is_active'               => $this->isConfigured(),
            'enabled'                 => $this->isEnabled(),
        ];

        return $integrations;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Main notification handler - processes the affiliate registration
     */
    public function notify($feed, $formData, $entry, $formId)
    {
        $processedValues = Arr::get($feed, 'processedValues', []);
        // Validate required fields
        if (!$this->validateRequiredFields($processedValues, $entry, $formId)) {
            return false;
        }
        $userId = $entry->user_id ?: 0;
        if (!$userId) {
            $userData = $this->extractUserData($processedValues);
            $userId = $this->handleUserCreation($userData, $entry, $formId);
        }

        if (!$userId || is_wp_error($userId)) {
            $this->addLog(
                is_wp_error($userId) ? '[FluentAffiliate] ' . $userId->get_error_message() : __('[FluentAffiliate] User creation failed', 'fluent-affiliate'),
                $formId,
                $entry->id,
                'failed'
            );
            return false;
        }

        $user = User::query()->find($userId);

        if ($user->affiliate) {
            $this->addLog(
                __('[FluentAffiliate] Affiliate already exists for this user', 'fluent-affiliate'),
                $formId,
                $entry->id,
                'failed'
            );
            return false;
        }

        $affiliateData = $this->extractAffiliateData($processedValues);
        $createdAffiliate = $user->syncAffiliateProfile($affiliateData);

        // translators: %d is the affiliate ID
        $logMessage = sprintf(__('Affiliate created successfully. Affiliate ID: %d', 'fluent-affiliate'), $createdAffiliate->id);
        $this->addLog(
            $logMessage,
            $formId,
            $entry->id,
            'success'
        );

        return true;
    }

    /**
     * Validate required fields
     */
    private function validateRequiredFields(array $processedValues, $entry, $formId): bool
    {
        $userEmail = Arr::get($processedValues, 'user_email');
        $paymentEmail = Arr::get($processedValues, 'payment_email');

        if (!is_email($userEmail) && !$entry->user_id) {
            $this->addLog(
                __('[FluentAffiliate] Registration skipped because no email is given', 'fluent-affiliate'),
                $formId,
                $entry->id,
                'failed'
            );
            return false;
        }

        if (!is_email($paymentEmail)) {
            $this->addLog(
                __('[FluentAffiliate] Registration skipped because no payment email is given', 'fluent-affiliate'),
                $formId,
                $entry->id,
                'failed'
            );
            return false;
        }

        return true;
    }

    /**
     * Extract user data from processed values
     */
    private function extractUserData(array $processedValues): array
    {
        $fullName = Arr::get($processedValues, 'full_name', '');

        $firstName = '';
        $lastName = '';

        if ($fullName) {
            $nameParts = explode(' ', $fullName);
            $firstName = array_shift($nameParts);
            $lastName = implode(' ', $nameParts);
        }

        return [
            'email'      => sanitize_email(Arr::get($processedValues, 'user_email')),
            'username'   => sanitize_user(Arr::get($processedValues, 'username', '')),
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'password'   => Arr::get($processedValues, 'password', ''),
            'user_role'  => get_option('default_role', 'subscriber')
        ];
    }

    /**
     * Extract affiliate data from processed values
     */
    private function extractAffiliateData(array $processedValues): array
    {
        return array_filter([
            'rate_type'     => 'default',
            'payment_email' => sanitize_email(Arr::get($processedValues, 'payment_email')),
            'status'        => Arr::get($processedValues, 'affiliate_status', 'active'),
            'note'          => sanitize_textarea_field(Arr::get($processedValues, 'note', ''))
        ]);
    }

    /**
     * Handle user creation or retrieval
     */
    private function handleUserCreation(array $userData, $entry, $formId)
    {
        $existingUser = get_user_by('email', $userData['email']);
        if ($existingUser) {
            return $existingUser->ID;
        }

        $userId = AuthHelper::registerUser($userData);
        if (is_wp_error($userId)) {
            return $userId;
        }

        do_action('wppayform_created_user', $userId, [], $entry, $formId); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- others plugin hooks
        (new Meta())->updateOrderMeta('formSettings', $entry->id, '__created_user_id', $userId, $formId);

        return $userId;
    }

}
