<?php

namespace FluentAffiliate\App\Modules\Auth;

use FluentAffiliate\App\Helper\Utility;
use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\User;
use FluentAffiliate\Framework\Support\Arr;

class AuthHandler
{

    public function register()
    {
        add_action('fluent_affiliate/render_login_form', [$this, 'renderLoginForm']);
        add_action('fluent_affiliate/render_signup_form', [$this, 'renderSignupForm']);

        add_shortcode('fluent_affiliate_register_form', function () {
            ob_start();
            $this->renderSignupForm();
            return ob_get_clean();
        });

        add_action('wp_ajax_fluent_affiliate_user_login', [$this, 'handleUserLogin']);
        add_action('wp_ajax_nopriv_fluent_affiliate_user_login', [$this, 'handleUserLogin']);

        add_action('wp_ajax_fluent_affiliate_user_registration', [$this, 'handleExistingUserRegistration']);
        add_action('wp_ajax_nopriv_fluent_affiliate_user_registration', [$this, 'handleUserRegistration']);
    }

    public function handleUserLogin()
    {
        if (is_user_logged_in()) {
            $user = get_user_by('ID', get_current_user_id());
            return $this->handleUserLoginSuccess($user);
        }

        if (AuthHelper::isFluentAuthAvailable()) {
            wp_send_json([
                'message' => __('This form cannot be used to log in. Please reload the page and try again.', 'fluent-affiliate')
            ], 422);
        }

        $app = Utility::getApp();
        $request = $app->make('request');

        $data = $request->all();

        $validator = $app->make('validator')->make($data, [
            'log' => 'required',
            'pwd' => 'required'
        ], [
            'log.required' => __('Email is required', 'fluent-affiliate'),
            'pwd.required' => __('Password is required', 'fluent-affiliate')
        ]);

        if ($validator->fails()) {
            wp_send_json([
                'message' => __('Please fill all the required fields correctly', 'fluent-affiliate'),
                'errors'  => $validator->errors()
            ], 422);
        }

        $rateLimit = AuthHelper::isAuthRateLimit();
        if (is_wp_error($rateLimit)) {
            wp_send_json([
                'message' => $rateLimit->get_error_message()
            ], 422);
        }

        $user = wp_authenticate($data['log'], $data['pwd']);

        if (is_wp_error($user)) {
            wp_send_json([
                'message' => $user->get_error_message()
            ], 422);
        }

        AuthHelper::makeLogin($user);

        $this->handleUserLoginSuccess($user);
    }

    public function handleExistingUserRegistration()
    {
        $user = get_user_by('ID', get_current_user_id());
        $affiliate = Affiliate::where('user_id', get_current_user_id())->first();
        if ($affiliate) {
            return $this->handleUserLoginSuccess($user);
        }

        $app = Utility::getApp();
        $request = $app->make('request');
        $fields = AuthHelper::getRegistrationFormFields(null, 'view', true);

        $keys = array_keys($fields);
        $data = Arr::only($request->all(), $keys);

        $validationRules = AuthHelper::getValidationRules($user, 'view');

        $validator = $app->make('validator')->make($data, $validationRules['rules'], $validationRules['messages']);

        if ($validator->fails()) {
            wp_send_json([
                'message' => __('Please fill in all required fields correctly.', 'fluent-affiliate'),
                'errors'  => $validator->errors()
            ], 422);
        }

        $payoutMethod = Utility::getReferralSetting('payout_method', 'paypal');
        $paymentEmail = Arr::get($data, 'payment_email');
        $bankDetails = Arr::get($data, 'bank_details', '');

        $userUrl = Arr::get($data, 'url', '');

        if ($userUrl) {
            $userUrl = sanitize_url($userUrl);
        }

        $settings = [];
        if ($payoutMethod === 'bank_transfer' && $bankDetails) {
            $settings['bank_details'] = sanitize_textarea_field($bankDetails);
        }

        $formattedData = array_filter([
            'note'          => sanitize_text_field(Arr::get($data, 'note')),
            'payment_email' => ($payoutMethod === 'paypal' && $paymentEmail) ? sanitize_email($paymentEmail) : '',
            'settings'      => $settings
        ]);

        if ($userUrl && filter_var($userUrl, FILTER_VALIDATE_URL)) {
            // update user url
            wp_update_user([
                'ID'       => $user->ID,
                'user_url' => $userUrl
            ]);
        }

        return $this->handleSignupCompleted($user->ID, $formattedData);
    }

    public function handleUserRegistration()
    {
        if (is_user_logged_in()) {
            $user = get_user_by('ID', get_current_user_id());
            return $this->handleUserLoginSuccess($user);
        }

        if (!AuthHelper::isRegistrationEnabled()) {
            wp_send_json([
                'message' => __('Registration is disabled for this site', 'fluent-affiliate')
            ], 422);
        }

        $app = Utility::getApp();
        $request = $app->make('request');
        $fields = AuthHelper::getRegistrationFormFields(null, 'view', true);

        $keys = array_keys($fields);
        $data = Arr::only($request->all(), $keys);

        if (isset($data['username'])) {
            $data['username'] = sanitize_user(strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $data['username'])));
        }

        $validationRules = AuthHelper::getValidationRules();

        if (!empty($requiredFields['username'])) {
            // remove space and special characters from username
            if (empty($data['username'])) {
                wp_send_json([
                    'message' => __('Username is not valid', 'fluent-affiliate'),
                    'errors'  => [
                        'username' => __('Please provide a valid username', 'fluent-affiliate')
                    ]
                ], 422);
            }

            if (strlen($data['username']) < 4) {
                wp_send_json([
                    'message' => __('Username must be at least 4 characters long', 'fluent-affiliate'),
                    'errors'  => [
                        'username' => __('Username must be at least 4 characters long', 'fluent-affiliate')
                    ]
                ], 422);
            }

            if (!AuthHelper::isUsernameAvailable($data['username'])) {
                wp_send_json([
                    'message' => __('Username is already taken or reserved', 'fluent-affiliate'),
                    'errors'  => [
                        'username' => __('Username is already taken or reserved. Please use a different username', 'fluent-affiliate')
                    ]
                ], 422);
            }
        }

        $data['email'] = sanitize_email($data['email']);

        $validator = $app->make('validator')->make($data, $validationRules['rules'], $validationRules['messages']);

        if ($validator->fails()) {
            wp_send_json([
                'message' => __('Please fill in all required fields correctly.', 'fluent-affiliate'),
                'errors'  => $validator->errors()
            ], 422);
        }

        foreach ($data as $key => $value) {
            // let's sanitize the data
            $callBack = $fields[$key]['sanitize_callback'] ?? null;
            if ($callBack) {
                $data[$key] = call_user_func($callBack, $value);
            }
        }

        // let's extract the full_name and set the first_name and last_name
        if (!empty($data['full_name'])) {
            $nameParts = explode(' ', $data['full_name']);
            $data['first_name'] = $nameParts[0];
            $data['last_name'] = implode(' ', array_slice($nameParts, 1));
            unset($data['full_name']);
            $data = array_filter($data);
        }

        $rateLimit = AuthHelper::isAuthRateLimit();

        if (is_wp_error($rateLimit)) {
            wp_send_json([
                'message' => $rateLimit->get_error_message()
            ], 422);
        }

        // We need two-factor authentication here
        if (AuthHelper::isTwoFactorEnabled()) {
            // Check if Two Factor code is given
            $verificationToken = $request->get('__two_fa_signed_token');
            if ($verificationToken) {
                $code = $request->get('_email_verification_code');
                if (!$code) {
                    wp_send_json([
                        'message' => __('Verification code is required', 'fluent-affiliate')
                    ], 422);
                }

                $validated = AuthHelper::validateVerificationCode($code, $verificationToken, $data);
                if (is_wp_error($validated)) {
                    wp_send_json([
                        'message' => $validated->get_error_message()
                    ], 422);
                }
            } else {
                // Let's send the verification code
                $htmlForm = AuthHelper::get2FaRegistrationCodeForm($data);
                wp_send_json([
                    'verifcation_html' => $htmlForm
                ]);
            }
        }

        $userId = AuthHelper::registerUser($data);

        if (is_wp_error($userId)) {
            wp_send_json([
                'message' => $userId->get_error_message()
            ], 422);
        }
        
        $settings = [];
        $payoutMethod = Utility::getReferralSetting('payout_method', 'paypal');
        if ($payoutMethod === 'bank_transfer' && !empty($data['bank_details'])) {
            $settings['bank_details'] = sanitize_textarea_field($data['bank_details']);
        }

        $this->handleSignupCompleted($userId, array_filter([
            'note'          => Arr::get($data, 'note', ''),
            'payment_email' => Arr::get($data, 'payment_email', ''),
            'settings'      => $settings
        ]));
    }

    private function handleUserLoginSuccess($user, $redirectUrl = null)
    {
        if (!$redirectUrl) {
            $redirectUrl = Utility::getPortalPageUrl();
        }

        $redirectUrl = apply_filters('fluent_affiliate/auth/after_login_redirect_url', $redirectUrl, $user);
        $btnText = __('Continue to the Affiliate Dashboard', 'fluent-affiliate');

        $html = '<div class="fa_completed"><div class="fa_complted_header"><h2>' . __('Welcome back!', 'fluent-affiliate') . '</h2>';
        $html .= '<p>' . __('You have successfully logged in to the affiliate dashboard', 'fluent-affiliate') . '</p></div>';
        $html .= '<a href="' . $redirectUrl . '" class="fa_btn fa_btn_success">' . $btnText . '</a>';
        $html .= '</div>';

        wp_send_json([
            'success_html' => $html,
            'redirect_url' => $redirectUrl
        ]);
    }

    private function handleSignupCompleted($userId, $extraData = [])
    {
        // We have the user now let's set the community membership
        $user = User::find($userId);

        $request = Utility::getApp()->make('request');

        if (!$user->affiliate) {
            $user->syncAffiliateProfile($extraData);
        }

        $redirectUrl = Utility::getPortalPageUrl();

        $redirectUrl = apply_filters('fluent_affiliate/auth/after_signup_redirect_url', $redirectUrl, $user, $request->all());
        $btnText = __('Continue to the Dashboard', 'fluent-affiliate');

        $html = '<div class="fa_completed"><div class="fa_complted_header"><h2>' . __('Congratulations!', 'fluent-affiliate') . '</h2>';
        $html .= '<p>' . __('You have successfully applied for an affiliate account', 'fluent-affiliate') . '</p></div>';
        $html .= '<a href="' . $redirectUrl . '" class="fa_btn fa_btn_success">' . $btnText . '</a>';
        $html .= '</div>';

        if (!get_current_user_id()) {
            $wpUser = get_user_by('ID', $userId);
            AuthHelper::makeLogin($wpUser);
        }

        wp_send_json([
            'success_html' => $html,
            'redirect_url' => $redirectUrl
        ]);
    }

    public function renderSignupForm()
    {
        $userId = get_current_user_id();

        //check if the registration is disabled
        if (!AuthHelper::isRegistrationEnabled()) {
            echo '<div class="fa_completed"><div class="fa_complted_header"><h4>' . esc_html__('Registration is disabled', 'fluent-affiliate') . '</h4>';
            return;
        }

        $this->enqueueScripts();

        $user = get_user_by('ID', $userId);


        $fields = AuthHelper::getRegistrationFormFields($user);

        $fields = array_filter($fields, function ($field) use ($user) {
            if ($user) {
                return Arr::get($field, 'enabled') !== 'no' && Arr::get($field, 'public_only') !== 'yes';
            }
            return Arr::get($field, 'enabled') !== 'no';
        });

        $hiddenFields = [
            'action' => 'fluent_affiliate_user_registration',
        ];

        global $wp;
        $currentUrl = home_url($wp->request);

        ?>
        <div style="max-width: 600px; margin: 0 auto;" class="fa_auth_warp fa_register_wrap">
            <div style="margin-bottom: 20px;" class="fa_auth_form_header">
                <h3><?php esc_html_e('Affiliate Registration ', 'fluent-affiliate'); ?></h3>
            </div>
            <div id="fa_user_onboard_wrap" class="fluent_aff_signup fa_onboard_form">
                <form method="post" id="fa_user_registration_form">
                    <?php
                    foreach ($hiddenFields as $name => $value) {
                        echo "<input type='hidden' name='" . esc_attr($name) . "' value='" . esc_attr($value) . "'>";
                    }
                    ?>
                    <div class="fa_form_main_fields">
                        <?php
                            $formBuilder = new FormBuilder($fields);
                            $formBuilder->render();
                        ?>
                        <div class="fa_form-group">
                            <div class="fa_form_input">
                                <button type="submit" class="fa_btn fa_btn_primary has_svg_loader fa_btn_submit">
                                    <svg version="1.1" class="fa_loading_svg" x="0px" y="0px" width="40px" height="20px" viewBox="0 0 50 50" style="enable-background:new 0 0 50 50;" xml:space="preserve">
                                        <path fill="currentColor" d="M43.935,25.145c0-10.318-8.364-18.683-18.683-18.683c-10.318,0-18.683,8.365-18.683,18.683h4.068c0-8.071,6.543-14.615,14.615-14.615c8.072,0,14.615,6.543,14.615,14.615H43.935z">
                                            <animateTransform attributeType="xml" attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="0.6s" repeatCount="indefinite"/>
                                        </path>
                                    </svg>
                                    <span>
                                        <?php esc_html_e('Register', 'fluent-affiliate'); ?>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="fa_auth_footer">
                <?php if (!is_user_logged_in()): ?>
                    <p>
                        <?php esc_html_e('Already have an account?', 'fluent-affiliate'); ?>
                        <a href="<?php echo esc_url(add_query_arg(['fa_form' => 'login'], $currentUrl)); ?>"
                           class="fa_btn fa_btn_secondary"><?php esc_html_e('Login', 'fluent-affiliate'); ?></a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function renderLoginForm()
    {
        global $wp;
        $currentUrl = home_url($wp->request);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Form display parameter, not data submission
        if (isset($_REQUEST['fa_form']) && sanitize_text_field(wp_unslash($_REQUEST['fa_form'])) === 'register') {
            $this->renderSignupForm();
            return;
        }

        ?>
        <div style="max-width: 600px; margin: 0 auto;" class="fa_auth_warp fa_onboard_form">
            <div style="margin-bottom: 20px;" class="fa_auth_form_header">
                <h3 style="margin-bottom: 0;"><?php esc_html_e('Login to your Affiliate Dashboard', 'fluent-affiliate'); ?></h3>
                <p><?php esc_html_e('Please enter your email and password to log in.', 'fluent-affiliate'); ?></p>
            </div>
            <div class="fa_native_login">
                <?php
                if (AuthHelper::isFluentAuthAvailable()) {
                    echo do_shortcode('[fluent_auth_login redirect_to="' . esc_url($currentUrl) . '"]');
                } else {
                    $this->enqueueScripts();
                    AuthHelper::nativeLoginForm([
                        'redirect' => $currentUrl,
                        'form_id'  => 'fluent_aff_user_login_form'
                    ], [
                        'is_fluent_affiliate' => 'yes',
                        'action'              => 'fluent_affiliate_user_login',
                    ]);
                }
                ?>
            </div>

            <div class="fa_auth_footer">
                <p>
                    <?php esc_html_e('Don\'t have an account?', 'fluent-affiliate'); ?>
                    <a href="<?php echo esc_url(add_query_arg(['fa_form' => 'register'], $currentUrl)); ?>"
                       class="fa_btn fa_btn_secondary"><?php esc_html_e('Register Now', 'fluent-affiliate'); ?></a>
                </p>
            </div>

        </div>
        <?php
    }

    public function enqueueScripts()
    {
        wp_enqueue_script('fluent-affiliate-auth', FLUENT_AFFILIATE_URL . 'assets/public/user_auth.js', [], FLUENT_AFFILIATE_VERSION, true);
        wp_localize_script('fluent-affiliate-auth', 'fluentAuthPublic', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('fa_auth_nonce'),
        ]);
    }
}
