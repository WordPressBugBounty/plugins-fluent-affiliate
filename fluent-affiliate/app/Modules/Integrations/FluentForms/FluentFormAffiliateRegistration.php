<?php

namespace FluentAffiliate\App\Modules\Integrations\FluentForms;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\User;
use FluentAffiliate\App\Modules\Auth\AuthHelper;
use FluentAffiliate\Framework\Support\Arr;
use \FluentForm\App\Http\Controllers\IntegrationManagerController;
use \FluentForm\App\Modules\Form\FormFieldsParser;
use \FluentForm\App\Helpers\Helper;

class FluentFormAffiliateRegistration extends IntegrationManagerController
{
    public $disableGlobalSettings = 'yes';
    public $hasGlobalMenu = false;



    public function __construct()
    {
        parent::__construct(
            wpFluentForm(),
            'Fluent Affiliate Registration',
            'fluent_affiliate_registration',
            '_ff_affiliate_registration_settings',
            'ff_affiliate_registration_feed',
            15
        );

        $this->logo = esc_url(Utility::asset('images/FluentAffiliate.svg'));
        $this->description = __('Create affiliate accounts automatically when forms are submitted.', 'fluent-affiliate');
        $this->category = 'crm';

        $this->registerAdminHooks();

        add_filter(
            'fluentform/get_integration_values_' . $this->integrationKey,
            [$this, 'resolveIntegrationSettings'],
            10,
            3
        );

        add_filter(
            'fluentform/save_integration_value_' . $this->integrationKey,
            [$this, 'validate'],
            10,
            3
        );

        add_filter('fluentform/notifying_async_fluent_affiliate_registration', '__return_false');
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        $listId = sanitize_text_field($this->app->request->get('serviceId', 'new_user'));
        $name = sanitize_text_field($this->app->request->get('serviceName', ''));

        return [
            'name'                    => $name,
            'list_id'                 => $listId,
            'email'                   => '',
            'full_name'               => '',
            'username'                => '',
            'password'                => '',
            'payment_email'           => '',
            'note'                    => '',
            'website'                 => '',
            'auto_approve'            => 'default', // default, yes, no
            'conditionals'            => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all',
            ],
            'enabled'                 => true,
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        $fieldSettings = [
            'fields'              => [
                [
                    'key'         => 'name',
                    'label'       => __('Integration Name', 'fluent-affiliate'),
                    'required'    => true,
                    'placeholder' => __('Your Integration Name', 'fluent-affiliate'),
                    'component'   => 'text',
                    'value'       => Arr::get($settings, 'name', '')
                ],
                [
                    'key'       => 'list_id',
                    'label'     => __('User Type', 'fluent-affiliate'),
                    'required'  => true,
                    'component' => 'refresh',
                    'options'   => [
                        'new_user'      => __('Create New WordPress User + Affiliate', 'fluent-affiliate'),
                        'existing_user' => __('Create Affiliate for Existing User (must be logged in)',
                            'fluent-affiliate')
                    ],
                    'value'     => Arr::get($settings, 'list_id')
                ],
            ],
            'button_require_list' => false,
            'integration_title'   => $this->title
        ];

        $userType = $this->app->request->get('serviceId', Arr::get($settings, 'list_id'));

        if ($userType) {
            $conditionalFields = $this->getConditionalFields($userType, $formId, $settings);
            $fieldSettings['fields'] = array_merge($fieldSettings['fields'], $conditionalFields);
        }

        // Add common fields at the end
        $fieldSettings['fields'] = array_merge($fieldSettings['fields'], [
            [
                'require_list' => false,
                'key'          => 'conditionals',
                'label'        => __('Conditional Logics', 'fluent-affiliate'),
                'tips'         => __('Allow this integration conditionally based on your submission values',
                    'fluent-affiliate'),
                'component'    => 'conditional_block'
            ],
            [
                'require_list'   => false,
                'key'            => 'enabled',
                'label'          => __('Status', 'fluent-affiliate'),
                'component'      => 'checkbox-single',
                'checkbox_label' => __('Enable this integration', 'fluent-affiliate'),
                'value'          => Arr::get($settings, 'enabled', true)
            ]
        ]);

        return $fieldSettings;
    }

    private function getConditionalFields($userType, $formId, $settings = [])
    {
        $mapFields = $this->getAffiliateMapFields($userType);

        $fields = [
            [
                'key'                => 'CustomFields',
                'require_list'       => false,
                'label'              => __('Map Fields', 'fluent-affiliate'),
                'tips'               => __('Associate your affiliate fields to the appropriate Fluent Forms fields by selecting the appropriate form field from the list.',
                    'fluent-affiliate'),
                'component'          => 'map_fields',
                'field_label_remote' => __('Affiliate Field', 'fluent-affiliate'),
                'field_label_local'  => __('Form Field', 'fluent-affiliate'),
                'primary_fileds'     => $mapFields
            ],
            [
                'key'       => 'auto_approve',
                'label'     => __('Auto Approve Affiliate', 'fluent-affiliate'),
                'component' => 'radio_choice',
                'options'   => [
                    'default' => __('Use Global Settings', 'fluent-affiliate'),
                    'yes'     => __('Yes, Auto Approve', 'fluent-affiliate'),
                    'no'      => __('No, Keep Pending', 'fluent-affiliate')
                ],
                'value'     => Arr::get($settings, 'auto_approve', 'default')
            ]
        ];

        return $fields;
    }

    private function getAffiliateMapFields($userType)
    {
        if ($userType === 'new_user') {
            return [
                [
                    'key'           => 'email',
                    'label'         => __('Email Address', 'fluent-affiliate'),
                    'input_options' => 'emails',
                    'required'      => true,
                ],
                [
                    'key'      => 'full_name',
                    'label'    => __('Full Name', 'fluent-affiliate'),
                    'required' => true,
                ],
                [
                    'key'      => 'username',
                    'label'    => __('Username', 'fluent-affiliate'),
                    'required' => true,
                ],
                [
                    'key'      => 'password',
                    'label'    => __('Password', 'fluent-affiliate'),
                    'required' => true,
                ],
                [
                    'key'           => 'payment_email',
                    'label'         => __('Payment Email', 'fluent-affiliate'),
                    'input_options' => 'emails',
                    'required'      => true,
                ],
                [
                    'key'      => 'note',
                    'label'    => __('Promotion Note', 'fluent-affiliate'),
                    'required' => false,
                ],
                [
                    'key'      => 'website',
                    'label'    => __('Website URL', 'fluent-affiliate'),
                    'required' => false,
                ]
            ];
        } else {
            // existing_user
            return [
                [
                    'key'           => 'payment_email',
                    'label'         => __('Payment Email', 'fluent-affiliate'),
                    'input_options' => 'emails',
                    'required'      => true,
                ],
                [
                    'key'      => 'note',
                    'label'    => __('Promotion Note', 'fluent-affiliate'),
                    'required' => false,
                ],
                [
                    'key'      => 'website',
                    'label'    => __('Website URL', 'fluent-affiliate'),
                    'required' => false,
                ]
            ];
        }
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return [];
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        $feedSettings = $feed['settings'];

        if (!Arr::get($feedData, 'enabled', true)) {
            return;
        }

        try {
            if ($feedData['list_id'] === 'existing_user') {
                $this->handleExistingUserAffiliate($feedData, $formData, $entry, $form);
            } else {
                $this->handleNewUserAffiliate($feedData, $formData, $entry, $form);
            }
        } catch (\Exception $e) {
            $logData = [
                'title'            => 'Affiliate Registration Failed',
                'status'           => 'failed',
                'description'      => $e->getMessage(),
                'parent_source_id' => $form->id,
                'source_id'        => $entry->id,
                'component'        => $this->integrationKey,
                'source_type'      => 'submission_item'
            ];

            do_action('fluentform/log_data', $logData);
        }
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                   => $this->title . ' Integration',
            'logo'                    => $this->logo,
            'is_active'               => $this->isConfigured(),
            'disable_global_settings' => 'yes',

        ];
        return $integrations;
    }

    public function isConfigured()
    {
        return true;
    }

    public function isEnabled()
    {
        return true;
    }

    private function handleNewUserAffiliate($feedData, $formData, $entry, $form)
    {
        // Extract and sanitize user data
        $userData = $this->extractUserData($feedData, $formData, 'new_user');
        $affiliateData = $this->extractAffiliateData($feedData, $formData);

        // Validate required fields for new user registration
        if (empty($userData['email']) || empty($affiliateData['payment_email'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for error handling
            throw new \Exception(__('Required fields (email, payment_email) are missing for new user affiliate registration',
                'fluent-affiliate'));
        }


        if (email_exists($userData['email']) || username_exists($userData['username'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for error handling
            throw new \Exception(__('User with this email or username already exists', 'fluent-affiliate'));
        }


        // Create WordPress user
        $userId = AuthHelper::registerNewUser($userData['username'], $userData['email'], $userData['password'], [
            'first_name' => $userData['first_name'],
            'last_name'  => $userData['last_name'],
            'role'       => get_option('default_role', 'subscriber'),
            'url'   => $userData['website']
        ]);


        if (is_wp_error($userId)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message from WP_Error for error handling
            throw new \Exception($userId->get_error_message());
        }

        // Create affiliate profile
        $this->createAffiliateProfile($userId, $affiliateData, $feedData);

        Helper::setSubmissionMeta($entry->id, '__created_user_id', $userId);
        do_action('fluentform/created_user', $userId, $feedData, $entry, $form);

        $logData = [
            'title'            => 'New Affiliate User Created',
            'status'           => 'success',
            'description'      => __('Affiliate user created successfully. User ID: ', 'fluent-affiliate') . $userId,
            'parent_source_id' => $form->id,
            'source_id'        => $entry->id,
            'component'        => $this->integrationKey,
            'source_type'      => 'submission_item'
        ];

        do_action('fluentform/log_data', $logData);
    }

    private function handleExistingUserAffiliate($feedData, $formData, $entry, $form)
    {
        $userId = get_current_user_id();

        if (!$userId) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for error handling
            throw new \Exception(__('User must be logged in to create affiliate account', 'fluent-affiliate'));
        }

        // Check if user already has affiliate account
        $existingAffiliate =  Affiliate::query()->find($userId);
        if ($existingAffiliate) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for error handling
            throw new \Exception(__('User already has an affiliate account', 'fluent-affiliate'));
        }

        // Extract affiliate data
        $affiliateData = $this->extractAffiliateData($feedData, $formData);
        $userData = $this->extractUserData($feedData, $formData, 'existing_user');

        // Validate required fields
        if (empty($affiliateData['payment_email'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for error handling
            throw new \Exception(__('Required field (payment_email) is missing for affiliate registration',
                'fluent-affiliate'));
        }

        // Create affiliate profile
        $this->createAffiliateProfile($userId, $affiliateData, $feedData);


        $logData = [
            'title'            => 'Existing User Affiliate Created',
            'status'           => 'success',
            'description'      => __('Affiliate account created successfully', 'fluent-affiliate'),
            'parent_source_id' => $form->id,
            'source_id'        => $entry->id,
            'component'        => $this->integrationKey,
            'source_type'      => 'submission_item'
        ];

        do_action('fluentform/log_data', $logData);
    }

    private function createAffiliateProfile($userId, $extraData, $feedData)
    {
        $user = User::find($userId);
        $affiliate = $user->syncAffiliateProfile($extraData);

        do_action('fluent_affiliate/affiliate_created_via_fluent_form', $affiliate, $user, $feedData);

        return $affiliate;
    }

    /**
     * Extract user data from processed values
     */
    private function extractUserData($feedData, $formData, $userType)
    {
        $fullName = sanitize_text_field($this->getFieldValue($feedData, $formData, 'full_name'));

        $firstName = '';
        $lastName = '';

        if ($fullName) {
            $nameParts = explode(' ', $fullName, 2);
            $firstName = sanitize_text_field($nameParts[0]);
            $lastName = isset($nameParts[1]) ? sanitize_text_field($nameParts[1]) : '';
        }

        return [
            'email'      => sanitize_email($this->getFieldValue($feedData, $formData, 'email')),
            'username'   => sanitize_user($this->getFieldValue($feedData, $formData, 'username')),
            'password'   => $this->getFieldValue($feedData, $formData, 'password'), // Don't sanitize passwords
            'full_name'  => $fullName,
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'website'    => esc_url_raw($this->getFieldValue($feedData, $formData, 'website', ''))
        ];
    }

    /**
     * Extract affiliate data from processed values
     */
    private function extractAffiliateData($feedData, $formData)
    {
        $statusSettings = Arr::get($feedData, 'auto_approve', 'default');

        $statusMap = [
            'yes'     => 'active',
            'no'      => 'pending',
            'default' => AuthHelper::getInitialAffiliateStatus()
        ];

        return array_filter([
            'payment_email' => sanitize_email($this->getFieldValue($feedData, $formData, 'payment_email')),
            'note'          => sanitize_textarea_field($this->getFieldValue($feedData, $formData, 'note')),
            'status'        => $statusMap[$statusSettings] ?? 'pending',
            'settings'      => []
        ]);
    }

    private function getFieldValue($feedData, $formData, $fieldKey, $default = '')
    {
        // Get the field mapping from feedData
        $fieldMapping = Arr::get($feedData, $fieldKey, '');

        if (empty($fieldMapping)) {
            return $default;
        }

        // Other fields can be used directly from processedValues
        if ($fieldKey === 'email' || $fieldKey === 'payment_email') {
            return Arr::get($formData, $fieldMapping, $default);
        }

        return $fieldMapping;
    }


    public function resolveIntegrationSettings($settings, $feed, $formId)
    {
        $serviceName = sanitize_text_field($this->app->request->get('serviceName', ''));
        $serviceId = sanitize_text_field($this->app->request->get('serviceId', ''));

        if ($serviceName) {
            $settings['name'] = $serviceName;
        }

        if ($serviceId) {
            $settings['list_id'] = $serviceId;
        }

        return $this->prepareIntegrationFeed($settings, $feed, $formId);
    }

    public function validate($settings, $integrationId, $formId)
    {
        $settingsFields = $this->getSettingsFields($settings, $formId);

        foreach (Arr::get($settingsFields, 'fields', []) as $field) {
            if (Arr::get($field, 'key') != 'CustomFields') {
                continue;
            }

            $errors = [];

            foreach (Arr::get($field, 'primary_fileds', []) as $primaryField) {
                if (!empty(Arr::get($primaryField, 'required'))) {
                    $fieldKey = Arr::get($primaryField, 'key');
                    if (empty(Arr::get($settings, $fieldKey))) {
                        $errors[$fieldKey] = Arr::get($primaryField, 'label') . ' is required.';
                    }
                }
            }

            if ($errors) {
                wp_send_json_error([
                    'message' => array_shift($errors),
                    'errors'  => $errors
                ], 422);
            }
        }

        return $settings;
    }



}
