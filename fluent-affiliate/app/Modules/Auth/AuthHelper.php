<?php
namespace FluentAffiliate\App\Modules\Auth;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Services\Libs\Mailer;
use FluentAffiliate\App\Services\VisitService;
use FluentAffiliate\Framework\Http\Cookie;
use FluentAffiliate\Framework\Support\Arr;

class AuthHelper
{
    public static function getRegrationSettings()
    {
        $defaults = [
            'enabled'          => 'yes',
            'require_approval' => 'yes',
        ];

        $settings = Utility::getOption('_registration_settings', []);
        $settings = wp_parse_args($settings, $defaults);
        return $settings;
    }

    public static function getInitialAffiliateStatus()
    {
        $settings = self::getRegrationSettings();
        return Arr::get($settings, 'require_approval', 'yes') === 'yes' ? 'pending' : 'active';
    }

    public static function registerNewUser($user_login, $user_email, $user_pass = '', $extraData = [])
    {
        $errors = new \WP_Error();

        $sanitized_user_login = sanitize_user($user_login);

        $user_email = apply_filters('user_registration_email', $user_email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook

        // Check the username.
        if ('' === $sanitized_user_login) {
            $errors->add('empty_username', __('<strong>Error</strong>: Please enter a username.', 'fluent-affiliate'));
        } elseif (! validate_username($user_login)) {
            $errors->add('invalid_username', __('<strong>Error</strong>: This username is invalid because it uses illegal characters. Please enter a valid username.', 'fluent-affiliate'));
            $sanitized_user_login = '';
        } elseif (username_exists($sanitized_user_login)) {
            $errors->add('username_exists', __('<strong>Error</strong>: This username is already registered. Please choose another one.', 'fluent-affiliate'));
        } else {
            /** This filter is documented in wp-includes/user.php */
            $illegal_user_logins = (array) apply_filters('illegal_user_logins', []); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook
            if (in_array(strtolower($sanitized_user_login), array_map('strtolower', $illegal_user_logins), true)) {
                $errors->add('invalid_username', __('<strong>Error</strong>: Sorry, that username is not allowed.', 'fluent-affiliate'));
            }
        }

        // Check the email address.
        if ('' === $user_email) {
            $errors->add('empty_email', __('<strong>Error</strong>: Please type your email address.', 'fluent-affiliate'));
        } elseif (! is_email($user_email)) {
            $errors->add('invalid_email', __('<strong>Error</strong>: The email address is not correct.', 'fluent-affiliate'));
            $user_email = '';
        } elseif (email_exists($user_email)) {
            $errors->add(
                'email_exists',
                __('<strong>Error:</strong> This email address is already registered. Please login or try resetting your password.', 'fluent-affiliate')
            );
        }

        do_action('register_post', $sanitized_user_login, $user_email, $errors); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook

        if ($errors->has_errors()) {
            return $errors;
        }

        if (! $user_pass) {
            $user_pass = wp_generate_password(8, false);
        }

        $data = [
            'user_login' => wp_slash($sanitized_user_login),
            'user_email' => wp_slash($user_email),
            'user_pass'  => $user_pass,
        ];

        if (! empty($extraData['first_name'])) {
            $data['first_name'] = sanitize_text_field($extraData['first_name']);
        }

        if (! empty($extraData['last_name'])) {
            $data['last_name'] = sanitize_text_field($extraData['last_name']);
        }

        if (! empty($extraData['full_name']) && empty($extraData['first_name']) && empty($extraData['last_name'])) {
            $extraData['full_name'] = sanitize_text_field($extraData['full_name']);
            // extract the names
            $fullNameArray      = explode(' ', $extraData['full_name']);
            $data['first_name'] = array_shift($fullNameArray);
            if ($fullNameArray) {
                $data['last_name'] = implode(' ', $fullNameArray);
            } else {
                $data['last_name'] = '';
            }
        }

        if (! empty($extraData['description'])) {
            $data['description'] = sanitize_textarea_field($extraData['description']);
        }

        if (! empty($extraData['user_url']) && filter_var($extraData['user_url'], FILTER_VALIDATE_URL)) {
            $data['url'] = sanitize_url($extraData['user_url']);
        }

        if (! empty($extraData['role'])) {
            $data['role'] = $extraData['role'];
        }

        $user_id = wp_insert_user($data);

        if (! $user_id || is_wp_error($user_id)) {
            $errors->add('registerfail', __('<strong>Error</strong>: Could not register you. Please contact the site admin!', 'fluent-affiliate')
            );
            return $errors;
        }

        if (Cookie::get('wp_lang')) {
            $wp_lang = sanitize_text_field(Cookie::get('wp_lang'));
            if (in_array($wp_lang, get_available_languages(), true)) {
                update_user_meta($user_id, 'locale', $wp_lang); // Set user locale if defined on registration.
            }
        }

        do_action('register_new_user', $user_id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook

        return $user_id;
    }

    public static function makeLogin($user)
    {
        wp_clear_auth_cookie();
        wp_set_current_user($user->ID, $user->user_login);
        wp_set_auth_cookie($user->ID, true, is_ssl());

        $user = get_user_by('ID', $user->ID);

        if ($user) {
            do_action('wp_login', $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook
        }

        return $user;
    }

    public static function isFluentAuthAvailable()
    {
        if (defined('FLUENT_AUTH_VERSION') && FLUENT_AUTH_VERSION) {
            return (new \FluentAuth\App\Hooks\Handlers\CustomAuthHandler())->isEnabled();
        }

        return false;
    }

    public static function getTermsText()
    {
        $policyUrl = self::getAffiliateTermsPageUrl();

        $termsText = __('I agree to the terms and conditions', 'fluent-affiliate');
        if ($policyUrl) {
            // translators: %1$s is the opening anchor tag, %2$s is the closing anchor tag
            $termsText = sprintf(__('I agree to the %1$s terms and conditions %2$s', 'fluent-affiliate'), '<a rel="noopener" href="' . esc_url($policyUrl) . '" target="_blank">', '</a>');
        }

        return $termsText;
    }

    private static function getAffiliateTermsPageUrl()
    {
        return apply_filters('fluent_affiliate/terms_policy_url', get_privacy_policy_url());
    }

    public static function getRegistrationFormFields($user = null, $scope = 'view', $keyed = false)
    {
        $userFullName = '';
        if ($user) {
            $userFullName = trim($user->first_name . ' ' . $user->last_name);
        }

        $defaults = [
            [
                'name'              => 'full_name',
                'label'             => __('Full name', 'fluent-affiliate'),
                'placeholder'       => __('Your first & last name', 'fluent-affiliate'),
                'type'              => 'text',
                'required'          => 'yes',
                'value'             => $userFullName,
                'readonly'          => 'no',
                'sanitize_callback' => 'sanitize_text_field',
                'system_defined'    => 'yes',
                'disable_alter'     => 'yes',
                'enabled'           => 'yes'
            ],
            [
                'name'              => 'email',
                'email'             => 'email',
                'type'              => 'email',
                'placeholder'       => __('Your email address', 'fluent-affiliate'),
                'label'             => __('Email Address', 'fluent-affiliate'),
                'required'          => $user ? 'no' : 'yes',
                'value'             => $user ? $user->user_email : '',
                'readonly'          => $user ? 'yes' : 'no',
                'sanitize_callback' => 'sanitize_email',
                'system_defined'    => 'yes',
                'disable_alter'     => 'yes',
                'enabled'           => 'yes'
            ],
            [
                'name'              => 'username',
                'type'              => 'text',
                'placeholder'       => __('No space or special characters', 'fluent-affiliate'),
                'label'             => __('Username', 'fluent-affiliate'),
                'required'          => $user ? 'no' : 'yes',
                'value'             => $user ? $user->user_login : '',
                'readonly'          => $user ? 'yes' : 'no',
                'sanitize_callback' => 'sanitize_user',
                'system_defined'    => 'yes',
                'disable_alter'     => 'yes',
                'enabled'           => 'yes',
                'public_only'       => 'yes' // Only show this field if the user is not logged in
            ],
            [
                'name'              => 'password',
                'type'              => 'password',
                'placeholder'       => __('Password', 'fluent-affiliate'),
                'label'             => __('Account Password', 'fluent-affiliate'),
                'required'          => $user ? 'no' : 'yes',
                'sanitize_callback' => 'sanitize_text_field',
                'system_defined'    => 'yes',
                'is_hidden'         => 'no',
                'disable_alter'     => 'yes',
                'enabled'           => $user ? 'no' : 'yes',
                'public_only'       => 'yes' // Only show this field if the user is not logged in
            ],
            [
                'name'              => 'conf_password',
                'type'              => 'password',
                'placeholder'       => __('Password Confirmation', 'fluent-affiliate'),
                'label'             => __('Re-type Account Password', 'fluent-affiliate'),
                'required'          => $user ? 'no' : 'yes',
                'sanitize_callback' => 'sanitize_text_field',
                'system_defined'    => 'yes',
                'is_hidden'         => 'no',
                'disable_alter'     => 'yes',
                'enabled'           => $user ? 'no' : 'yes',
                'public_only'       => 'yes' // Only show this field if the user is not logged in
            ],
            [
                'name'          => 'raw_html',
                'type'          => 'raw_html',
                'is_hidden'     => 'no',
                'disable_alter' => 'no',
                'required'      => 'no',
                'enabled'       => 'yes',
                'html'          => '<div class="fa_about"><h3>' . __('About Yourself', 'fluent-affiliate') . '</h3></div>',
            ],
            [
                'name'              => 'note',
                'type'              => 'textarea',
                'label'             => __('How will you promote us?', 'fluent-affiliate'),
                'required'          => 'yes',
                'placeholder'       => __('Please provide details on how you plan to promote our products or services.', 'fluent-affiliate'),
                'value'             => '',
                'system_defined'    => 'yes',
                'is_hidden'         => 'no',
                'disable_alter'     => 'no',
                'enabled'           => 'yes',
                'sanitize_callback' => 'sanitize_textarea_field'
            ],
            [
                'name'              => 'url',
                'type'              => 'url',
                'placeholder'       => __('Your website URL', 'fluent-affiliate'),
                'label'             => __('Website URL', 'fluent-affiliate'),
                'required'          => 'no',
                'value'             => '',
                'system_defined'    => 'yes',
                'is_hidden'         => 'no',
                'disable_alter'     => 'no',
                'enabled'           => 'yes',
                'sanitize_callback' => 'sanitize_url',
                'help_text'         => __('If you have a website or blog, please provide the URL. This helps us understand your online presence.', 'fluent-affiliate')
            ],
            [
                'name'           => 'terms_condition',
                'type'           => 'inline_checkbox',
                'inline_label'   => self::getTermsText(),
                'required'       => 'yes',
                'disable_alter'  => 'yes',
                'enabled'        => 'yes',
                'system_defined' => 'yes'
            ]
        ];

        $payoutMethod = Utility::getReferralSetting('payout_method', 'paypal');

        if ($payoutMethod === 'bank_transfer') {
            $defaults[] = [
                'name'              => 'bank_details',
                'type'              => 'textarea',
                'placeholder'       => __('Enter your bank account details', 'fluent-affiliate'),
                'label'             => __('Bank Details', 'fluent-affiliate'),
                'required'          => 'yes',
                'value'             => '',
                'system_defined'    => 'yes',
                'is_hidden'         => 'no',
                'disable_alter'     => 'no',
                'enabled'           => 'yes',
                'help_text'         => __('Enter your bank account details for payouts', 'fluent-affiliate'),
                'sanitize_callback' => 'sanitize_textarea_field',
            ];
        } else {
            $defaults[] = [
                'name'              => 'payment_email',
                'type'              => 'email',
                'placeholder'       => __('Your PayPal email address', 'fluent-affiliate'),
                'label'             => __('Payment Email Address', 'fluent-affiliate'),
                'required'          => 'yes',
                'value'             => '',
                'system_defined'    => 'yes',
                'is_hidden'         => 'no',
                'disable_alter'     => 'no',
                'enabled'           => 'yes',
                'help_text'         => __('Enter your PayPal Email Address (so we can pay you)', 'fluent-affiliate'),
                'sanitize_callback' => 'sanitize_email'
            ];
        }

        $savedFields = Utility::getOption('_registration_fields', []);

        $keyedSaved = [];
        foreach ($savedFields as $field) {
            $keyedSaved[$field['name']] = $field;
        }

        $keyedDefaults = [];
        foreach ($defaults as $field) {
            $keyedDefaults[$field['name']] = $field;
        }

        $formattedItems = [];
        $pushedKeys = [];
        foreach ($keyedSaved as $key => $saved) {
            $defaultItem  = Arr::get($keyedDefaults, $key, []);
            $pushedKeys[] = $key;
            if (
                ($payoutMethod === 'bank_transfer' && $key === 'payment_email') ||
                ($payoutMethod === 'paypal' && $key === 'bank_details')
            ) {
                continue;
            }

            if (!$defaultItem) {
                $formattedItems[] = $saved;
                continue;
            }

            $isSystemRequired = Arr::get($defaultItem, 'system_defined', 'no') === 'yes' && Arr::get($defaultItem, 'disable_alter', 'no') === 'yes';

            $keys = ['type', 'required', 'value', 'readonly', 'sanitize_callback', 'system_defined', 'disable_alter', 'public_only'];

            if ($isSystemRequired) {
                $keys[] = 'enabled';
            }

            $defaultValues = Arr::only($defaultItem, $keys);
            $saved = wp_parse_args($saved, $defaultValues);
            $formattedItems[] = $saved;
        }

        // leftout items
        $leftoutItems = Arr::except($keyedDefaults, $pushedKeys);

        foreach ($leftoutItems as $key => $value) {
            $formattedItems[] = $value;
        }

        $fields = apply_filters('fluent_affiliate/auth/signup_fields', $formattedItems, $user);


        if ($user) {
            $fields = array_filter($fields, function ($field) {
                return ! isset($field['public_only']) || ! $field['public_only'];
            });
        }

        if ($keyed) {
            $formattedFields = [];

            foreach ($fields as $filed) {
                $fieldName = (string) Arr::get($filed, 'name');
                if (! $fieldName) {
                    continue;
                }
                $formattedFields[$fieldName] = $filed;
            }

            return $formattedFields;
        }

        return $fields;
    }

    public static function getValidationRules($user = null, $scope = 'view')
    {
        $fields = self::getRegistrationFormFields($user, $scope);

        $requiredFields = [];

        foreach ($fields as $field) {
            if (
                Arr::get($field, 'required') !== 'yes' ||
                Arr::get($field, 'enabled') !== 'yes' ||
                Arr::get($field, 'disabled') === 'yes' ||
                Arr::get($field, 'readonly') === 'yes'
            ) {
                continue;
            }

            $requiredFields[$field['name']] = $field;
        }

        $validationRules = [
            'full_name' => 'required|max:100|string',
            'email'     => 'required|email|unique:users,user_email',
        ];

        if (! empty($requiredFields['password'])) {
            $validationRules['password'] = 'required|max:50|string|min:6';
            if (! empty($requiredFields['conf_password'])) {
                $validationRules['conf_password'] = 'required|same:password';
            }
        }

        foreach ($requiredFields as $key => $field) {
            if (! isset($validationRules[$key])) {
                $validationRules[$key] = 'required';
            }
        }

        if ($user) {
            unset($validationRules['email']);
        }

        return [
            'rules'    => $validationRules,
            'messages' => [
                'username.required'        => __('Username is required', 'fluent-affiliate'),
                'username.unique'          => __('Username is already taken', 'fluent-affiliate'),
                'email.required'           => __('Email is required', 'fluent-affiliate'),
                'email.email'              => __('Email is not valid', 'fluent-affiliate'),
                'email.unique'             => __('Email is already taken', 'fluent-affiliate'),
                'payment_email.required'   => __('Payment email is required', 'fluent-affiliate'),
                'password.required'        => __('Password is required', 'fluent-affiliate'),
                'password.max'             => __('Password must be at most 50 characters long', 'fluent-affiliate'),
                'password.min'             => __('Password must be at least 6 characters long', 'fluent-affiliate'),
                'password.same'            => __('Password and confirmation password do not match', 'fluent-affiliate'),
                'conf_password.required'   => __('Password confirmation is required', 'fluent-affiliate'),
                'conf_password.same'       => __('Password and confirmation password do not match', 'fluent-affiliate'),
                'full_name.required'       => __('Full name is required', 'fluent-affiliate'),
                'terms_condition.required' => __('You must agree to the terms and conditions', 'fluent-affiliate'),
                'bank_details.required'     => __('Bank details are required', 'fluent-affiliate'),
            ],
        ];

        $payoutMethod = Utility::getReferralSetting('payout_method', 'paypal');

        if ($payoutMethod === 'bank_transfer') {
            unset($validationRules['payment_email']);
        } else {
            unset($validationRules['bank_details']);
        }

        return [
            'rules'    => $validationRules,
            'messages' => $messages,
        ];
    }

    public static function getLostPasswordUrl($redirectUrl = '')
    {
        $url = wp_lostpassword_url($redirectUrl);
        return apply_filters('fluent_affiliate/auth/lost_password_url', $url);
    }

    public static function isRegistrationEnabled()
    {
        $authSettings = self::getRegrationSettings();
        return Arr::get($authSettings, 'enabled') === 'yes';
    }

    public static function isTwoFactorEnabled()
    {
        return apply_filters('fluent_auth/verify_signup_email', true);
    }

    public static function get2FaRegistrationCodeForm($formData)
    {
        $generalSettings = [
            'site_title' => get_bloginfo('name'),
            'logo'       => '',
        ];

        try {
            $verifcationCode = str_pad(random_int(100123, 900987), 6, 0, STR_PAD_LEFT);
        } catch (\Exception $e) {
            $verifcationCode = str_pad(wp_rand(100123, 900987), 6, 0, STR_PAD_LEFT);
        }

        // Hash the code
        $codeHash = wp_hash_password($verifcationCode);

        // Create a token with the email and code hash
        $data = [
            'email'     => $formData['email'],
            'code_hash' => $codeHash,
            'expires'   => time() + 600, // 10 minutes expiry
        ];
        $token = base64_encode(json_encode($data));

        // Sign the token
        $signature   = hash_hmac('sha256', $token, SECURE_AUTH_KEY);
        $signedToken = $token . '.' . $signature;

        // translators: %s is the site title
        $mailSubject = apply_filters("fluent_affiliate/auth/signup_verification_mail_subject", sprintf(__('Your registration verification code for %s', 'fluent-affiliate'), Arr::get($generalSettings, 'site_title')));

        $pStart = '<p style="font-family: Arial, sans-serif; font-size: 16px; font-weight: normal; margin: 0; margin-bottom: 16px;">';

        // translators: %s is the user's first name
        $message = $pStart . sprintf(__('Hello %s,', 'fluent-affiliate'), Arr::get($formData, 'first_name')) . '</p>' .
        $pStart . __('Thank you for registering with us! To complete the setup of your account, please enter the verification code below on the registration page.', 'fluent-affiliate') . '</p>' .
        // translators: %s is the verification code
        $pStart . '<b>' . sprintf(__('Verification Code: %s', 'fluent-affiliate'), $verifcationCode) . '</b></p>' .
        '<br />' .
        $pStart . __('This code is valid for 10 minutes and is meant to ensure the security of your account. If you did not initiate this request, please ignore this email.', 'fluent-affiliate') . '</p>';

        $message = apply_filters('fluent_affiliate/auth/signup_verification_email_body', $message, $verifcationCode, $formData);

        $message = (string) Utility::getApp()->make('view')->make('email.template', [
            'logo'        => [
                'url' => $generalSettings['logo'],
                'alt' => $generalSettings['site_title'],
            ],
            'bodyContent' => $message,
            'pre_header'  => __('Activate your account', 'fluent-affiliate'),
            'footerLines' => [
                __('If you did not initiate this request, please ignore this email.', 'fluent-affiliate'),
                // translators: %1$s is the site title, %2$s is the site URL
                sprintf(__('This email has been sent from %1$s. Site: %2$s', 'fluent-affiliate'), Arr::get($generalSettings, 'site_title'), home_url()),
            ],
        ]);

        $mailer = new Mailer($formData['email'], $mailSubject, $message);

        if ($formData['first_name']) {
            $toName = trim(Arr::get($formData, 'first_name') . ' ' . Arr::get($formData, 'last_name'));
            $mailer = $mailer->to($formData['email'], $toName);
        }

        $mailer->send();

        ob_start();
        ?>
        <div class="fls_signup_verification">
            <input type="hidden" name="__two_fa_signed_token" value="<?php echo esc_attr($signedToken); ?>"/>
            <p><?php
                   // translators: %s is the user's email address
                       echo esc_html(sprintf(__('A verification code has been sent to %s. Please provide the code below: ', 'fluent-affiliate'), $formData['email']));
                       ?></p>
            <div class="fcom_form-group fcom_field_verification">
                <div class="fcom_form_label">
                    <label for="fcom_field_verification"><?php esc_html_e('Verification Code', 'fluent-affiliate'); ?></label>
                </div>
                <div class="fs_input_wrap">
                    <input type="text" id="fcom_field_verification"
                           placeholder="<?php esc_attr_e('2FA Code', 'fluent-affiliate'); ?>" name="_email_verification_code"
                           required/>
                </div>
            </div>
            <div class="fcom_form-group">
                <div class="fcom_form_input">
                    <button type="submit" class="fcom_btn has_svg_loader fcom_btn_primary">
                        <svg version="1.1" class="fa_loading_svg" x="0px" y="0px" width="40px" height="20px"
                             viewBox="0 0 50 50" style="enable-background:new 0 0 50 50;" xml:space="preserve">
                            <path fill="currentColor"
                                  d="M43.935,25.145c0-10.318-8.364-18.683-18.683-18.683c-10.318,0-18.683,8.365-18.683,18.683h4.068c0-8.071,6.543-14.615,14.615-14.615c8.072,0,14.615,6.543,14.615,14.615H43.935z">
                                <animateTransform attributeType="xml"
                                                  attributeName="transform"
                                                  type="rotate"
                                                  from="0 25 25"
                                                  to="360 25 25"
                                                  dur="0.6s"
                                                  repeatCount="indefinite"/>
                            </path>
                        </svg>
                        <span> <?php esc_html_e('Complete Signup', 'fluent-affiliate'); ?></span>
                    </button>
                </div>
            </div>
        </div>

        <?php
        return ob_get_clean();
    }

    public static function validateVerificationCode($code, $verificationToken, $formData)
    {
        list($data, $signature) = explode('.', $verificationToken, 2);
        $expectedSignature      = hash_hmac('sha256', $data, SECURE_AUTH_KEY);

        if (! hash_equals($expectedSignature, $signature)) {
            return new \WP_Error('invalid_token', __('Invalid verification token. Please try again', 'fluent-affiliate'));
        }

        $data = json_decode(base64_decode($data), true);
        if ($data['expires'] < time()) {
            return new \WP_Error('expired_token', __('Verification token has expired. Please try again.', 'fluent-affiliate'));
        }

        if ($data['email'] !== $formData['email']) {
            return new \WP_Error('invalid_email', __('Invalid email address. Please try again', 'fluent-affiliate'));
        }

        if (! wp_check_password($code, $data['code_hash'])) {
            return new \WP_Error('invalid_code', __('Invalid verification code. Please try again', 'fluent-affiliate'));
        }

        return true;
    }

    public static function isAuthRateLimit()
    {
        if (apply_filters('fluent_plugins/auth/disable_rate_limit', false)) {
            return true;
        }

        $transientKey = 'fluent_plugins_auth_rate_limit_' . md5(VisitService::getIp());
        $rateLimit    = get_transient($transientKey);

        if (! $rateLimit) {
            $rateLimit = 0;
        }

        if ($rateLimit >= 10) {
            return new \WP_Error('rate_limit', __('Too many requests. Please try again later', 'fluent-affiliate'));
        }

        $rateLimit = $rateLimit + 1;
        set_transient($transientKey, $rateLimit, 300); // per 5 minutes
        return true;
    }

    public static function nativeLoginForm($args = [], $hiddenFields = [])
    {
        $request  = Utility::getApp()->make('request');
        $defaults = [
            'echo'                 => true,
            'redirect'             => (is_ssl() ? 'https://' : 'http://') . $request->server('HTTP_HOST') . $request->server('REQUEST_URI'),
            'form_id'              => 'loginform',
            'label_username'       => __('Email Address', 'fluent-affiliate'),
            'label_password'       => __('Password', 'fluent-affiliate'),
            'label_remember'       => __('Remember Me', 'fluent-affiliate'),
            'label_log_in'         => __('Log In', 'fluent-affiliate'),
            'id_username'          => 'user_login',
            'id_password'          => 'user_pass',
            'id_remember'          => 'rememberme',
            'id_submit'            => 'wp-submit',
            'remember'             => true,
            'value_username'       => '',
            'username_placeholder' => __('Your account email address', 'fluent-affiliate'),
            'password_placeholder' => __('Your account password', 'fluent-affiliate'),
            'value_remember'       => false,
        ];

        $args = wp_parse_args($args, apply_filters('login_form_defaults', $defaults)); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook

        $login_form_top = apply_filters('login_form_top', '', $args); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook

        $login_form_middle = apply_filters('login_form_middle', '', $args); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook

        $login_form_bottom = apply_filters('login_form_bottom', '', $args); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook

        $actionUrl = esc_url(site_url('wp-login.php', 'login_post'));

        if (isset($args['action_url'])) {
            $actionUrl = esc_url($args['action_url']);
        }

        foreach ($hiddenFields as $key => $value) {
            $login_form_top .= \sprintf(
                '<input type="hidden" name="%1$s" value="%2$s" />',
                esc_attr($key),
                esc_attr($value)
            );
        }

        $form = \sprintf(
            '<form name="%1$s" id="%1$s" action="%2$s" method="post">',
            esc_attr($args['form_id']),
            $actionUrl
        ) .
        $login_form_top .
        \sprintf(
            '<p class="login-username fcom_form-group">
    <label for="%1$s">%2$s</label>
    <input type="text" name="log" id="%1$s" autocomplete="username" class="input" value="%3$s" placeholder="%4$s" size="20" />
</p>',
            esc_attr($args['id_username']),
            esc_html($args['label_username']),
            esc_attr($args['value_username']),
            esc_attr($args['username_placeholder']),
        ) .
        \sprintf(
            '<p class="login-password fcom_form-group">
    <label for="%1$s">%2$s</label>
    <input type="password" name="pwd" id="%1$s" autocomplete="current-password" placeholder="%3$s" class="input" value="" size="20" />
</p>',
            esc_attr($args['id_password']),
            esc_html($args['label_password']),
            esc_attr($args['password_placeholder'])
        ) .
        $login_form_middle .
        ($args['remember'] ?
            \sprintf(
                '<p class="login-remember fcom_form-group"><label><input name="rememberme" type="checkbox" id="%1$s" value="forever"%2$s /> %3$s</label></p>',
                esc_attr($args['id_remember']),
                ($args['value_remember'] ? ' checked="checked"' : ''),
                esc_html($args['label_remember'])
            ) : ''
        ) .
        \sprintf(
            '<p class="login-submit">
    <input type="submit" name="wp-submit" id="%1$s" class="button button-primary" value="%2$s" />
    <input type="hidden" name="redirect_to" value="%3$s" />
</p>',
            esc_attr($args['id_submit']),
            esc_attr($args['label_log_in']),
            esc_url($args['redirect'])
        ) .
            $login_form_bottom .
            '</form>';

        if ($args['echo']) {
            echo $form; // @phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            return $form;
        }
    }

    public static function getReservedUserNames()
    {
        return apply_filters('fluent_affiliate/reserved_usernames', [
            'admin', 'administrator', 'me', 'moderator', 'mod', 'superuser', 'root', 'system', 'official', 'staff', 'support', 'helpdesk', 'user', 'guest', 'anonymous', 'everyone', 'anybody', 'someone', 'webmaster', 'postmaster', 'hostmaster', 'abuse', 'security', 'ssl', 'firewall', 'no-reply', 'noreply', 'mail', 'email', 'mailer', 'smtp', 'pop', 'imap', 'ftp', 'sftp', 'ssh', 'ceo', 'cfo', 'cto', 'founder', 'cofounder', 'owner', 'president', 'vicepresident', 'director', 'manager', 'supervisor', 'executive', 'info', 'contact', 'sales', 'marketing', 'support', 'billing', 'accounting', 'finance', 'hr', 'humanresources', 'legal', 'compliance', 'it', 'itsupport', 'customerservice', 'customersupport', 'dev', 'developer', 'api', 'sdk', 'app', 'bot', 'chatbot', 'sysadmin', 'devops', 'infosec', 'security', 'test', 'testing', 'beta', 'alpha', 'staging', 'production', 'development', 'home', 'about', 'contact', 'faq', 'help', 'news', 'blog', 'forum', 'community', 'events', 'calendar', 'shop', 'store', 'cart', 'checkout', 'social', 'follow', 'like', 'share', 'tweet', 'post', 'status', 'privacy', 'terms', 'copyright', 'trademark', 'legal', 'policy', 'all', 'none', 'null', 'undefined', 'true', 'false', 'default', 'example', 'sample', 'demo', 'temporary', 'delete', 'remove', 'profanity', 'explicit', 'offensive', 'yourappname', 'yourbrandname', 'yourdomain',
        ]);
    }

    public static function isUsernameAvailable($userName, $targetUserId = null)
    {
        $userName = strtolower($userName);

        if (strlen($userName) < 3) {
            return false;
        }

        $reservedUserNames = self::getReservedUserNames();
        if (in_array($userName, $reservedUserNames)) {
            return false;
        }

        $user = get_user_by('login', $userName);

        if ($user) {
            if ($targetUserId && $user->ID != $targetUserId) {
                return false;
            }
        }

        return true;
    }

    public static function isAutoApproveAffiliates()
    {
        return apply_filters('fluent_affiliate/auth/auto_approve_affiliates', false);
    }

    /**
     * Register a new WordPress user
     */
    public static function registerUser(array $userData)
    {
        $email = $userData['email'];

        if (! is_email($email)) {
            return new \WP_Error('invalid_email', __('Invalid email address', 'fluent-affiliate'));
        }

        // Generate username if not provided
        if (empty($userData['username'])) {
            $userData['username'] = self::generateUniqueUsername($userData);
        }

        $autoGenerated = false;
        $password      = trim(Arr::get($userData, 'password', ''));
        if (empty($password)) {
            $password      = wp_generate_password(8);
            $autoGenerated = true;
        }

        $newUserData = [
            'role'       => $userData['user_role'],
            'user_email' => $email,
            'user_login' => $userData['username'] ?: $email,
            'user_pass'  => $password,
            'first_name' => Arr::get($userData, 'first_name', ''),
            'last_name'  => Arr::get($userData, 'last_name', ''),
        ];

        $newUserData['display_name'] = trim($newUserData['first_name'] . ' ' . $newUserData['last_name']);

        $userId = wp_insert_user($newUserData);

        if (is_wp_error($userId)) {
            return $userId;
        }

        if ($autoGenerated) {
            // add password nag
            update_user_meta($userId, 'default_password_nag', true);
        }

        return $userId;
    }

    private static function generateUniqueUsername($userData): string
    {
        $email = sanitize_email($userData['email'] ?? '');

        $userNameParts = explode('@', $email);
        $userName      = strtolower($userNameParts[0] ?? '');

        if (AuthHelper::isUsernameAvailable($userName)) {
            return $userName;
        }

        $firstName = sanitize_title(strtolower(Arr::get($userData, 'first_name', '')));
        $lastName  = sanitize_title(strtolower(Arr::get($userData, 'last_name', '')));

        if ($firstName && AuthHelper::isUsernameAvailable($firstName)) {
            return $firstName;
        }

        if ($lastName && AuthHelper::isUsernameAvailable($lastName)) {
            return $lastName;
        }

        $originalUsername = $userName;
        $count            = 1;

        while (! self::isUsernameAvailable($userName)) {
            $username = $originalUsername . '_' . $count;
            $count++;
        }

        return $username;
    }
}
