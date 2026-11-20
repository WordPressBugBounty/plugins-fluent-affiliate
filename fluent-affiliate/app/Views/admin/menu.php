<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
?>
<div id="<?php echo esc_attr($slug); ?>-app" class="warp fconnector_app">
    <div class="fframe_app">
        <div id='fa_aff_menu' class='fa-navbar'>
            <div class="fa_mobile_menu_opener">
                <button aria-label="Open Menu" class="fa_menu_opener_btn" aria-disabled="false" type="button" onclick="toggleMobileMenu()">
                    <i class="el-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" color="currentColor" fill="none"> <path fill-rule="evenodd" clip-rule="evenodd" d="M3 4.5C3 3.94772 3.44772 3.5 4 3.5L20 3.5C20.5523 3.5 21 3.94772 21 4.5C21 5.05229 20.5523 5.5 20 5.5L4 5.5C3.44772 5.5 3 5.05228 3 4.5Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M3 14.5C3 13.9477 3.44772 13.5 4 13.5L20 13.5C20.5523 13.5 21 13.9477 21 14.5C21 15.0523 20.5523 15.5 20 15.5L4 15.5C3.44772 15.5 3 15.0523 3 14.5Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M3 9.5C3 8.94772 3.44772 8.5 4 8.5L20 8.5C20.5523 8.5 21 8.94772 21 9.5C21 10.0523 20.5523 10.5 20 10.5L4 10.5C3.44772 10.5 3 10.0523 3 9.5Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M3 19.5C3 18.9477 3.44772 18.5 4 18.5L20 18.5C20.5523 18.5 21 18.9477 21 19.5C21 20.0523 20.5523 20.5 20 20.5L4 20.5C3.44772 20.5 3 20.0523 3 19.5Z"fill="currentColor"/></svg>
                    </i>
                </button>
                <div class="fa-navbar__links" id="fa_mobile_menu_links">
                    <?php foreach ($menuItems as $fluentAffiliateMenuItem):?>
                        <div class="fa-navbar__link-wrapper" data-key="<?php echo esc_attr($fluentAffiliateMenuItem['key']); ?>">
                            <a class="fa-navbar__link" onclick="toggleMobileMenu()" href="<?php echo esc_url($fluentAffiliateMenuItem['permalink']); ?>">
                                <?php echo esc_attr($fluentAffiliateMenuItem['label']); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class='fa-navbar__logo'>
                <a href="<?php echo esc_url($baseUrl); ?>">
                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage ?>
                    <img class="fa-navbar__logo-img fa_light_logo" src="<?php echo esc_url($logo); ?>"
                         alt="<?php echo esc_attr__('Logo', 'fluent-affiliate'); ?>"/>
                    <img class="fa-navbar__logo-img fa_dark_logo" src="<?php echo esc_url($dark_logo); ?>"
                         alt="<?php echo esc_attr__('Logo', 'fluent-affiliate'); ?>"/>
                    <?php if (!empty($beta)): ?>
                        <span class="fa-navbar__beta-tag"><?php echo esc_html('beta'); ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <div class="fa-navbar__links fa_desktop_only">
                <?php foreach ($menuItems as $fluentAffiliateMenuItem):  ?>
                    <?php $fluentAffiliateHasSubMenu = !empty($fluentAffiliateMenuItem['sub_items']); ?>
                    <div class="fa-navbar__link-wrapper" data-key="<?php echo esc_attr($fluentAffiliateMenuItem['key']); ?>">
                        <a class="fa-navbar__link" href="<?php echo esc_url($fluentAffiliateMenuItem['permalink']); ?>">
                            <?php echo esc_attr($fluentAffiliateMenuItem['label']); ?>
                            <?php if ($fluentAffiliateHasSubMenu): ?>
                                <span class="fa-navbar__dropdown-icon dashicons dashicons-arrow-down-alt2"></span>
                            <?php endif; ?>
                        </a>

                        <?php if ($fluentAffiliateHasSubMenu): ?>
                            <div class="fa-navbar__dropdown">
                                <?php foreach ($fluentAffiliateMenuItem['sub_items'] as $fluentAffiliateSubMenuItem): ?>
                                    <a class="fa-navbar__dropdown-item"
                                       href="<?php echo esc_url($fluentAffiliateSubMenuItem['permalink']); ?>">
                                        <?php echo esc_attr($fluentAffiliateSubMenuItem['label']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="fa-navbar__settings">
                <div class="fa-navbar__link-wrapper fa_theme_switcher" id="fa_theme_switcher" data-key="color_mode">
                    <button type="button" class="fa_theme_trigger" aria-haspopup="menu" aria-expanded="false" aria-controls="fa_theme_menu"
                            aria-label="<?php echo esc_attr__('Color theme', 'fluent-affiliate'); ?>"
                            title="<?php echo esc_attr__('Color theme', 'fluent-affiliate'); ?>">
                        <span class="fa_theme_icon fa_theme_icon--light"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M10 14.5C8.80653 14.5 7.66193 14.0259 6.81802 13.182C5.97411 12.3381 5.5 11.1935 5.5 10C5.5 8.80653 5.97411 7.66193 6.81802 6.81802C7.66193 5.97411 8.80653 5.5 10 5.5C11.1935 5.5 12.3381 5.97411 13.182 6.81802C14.0259 7.66193 14.5 8.80653 14.5 10C14.5 11.1935 14.0259 12.3381 13.182 13.182C12.3381 14.0259 11.1935 14.5 10 14.5ZM10 13C10.7956 13 11.5587 12.6839 12.1213 12.1213C12.6839 11.5587 13 10.7956 13 10C13 9.20435 12.6839 8.44129 12.1213 7.87868C11.5587 7.31607 10.7956 7 10 7C9.20435 7 8.44129 7.31607 7.87868 7.87868C7.31607 8.44129 7 9.20435 7 10C7 10.7956 7.31607 11.5587 7.87868 12.1213C8.44129 12.6839 9.20435 13 10 13ZM9.25 1.75H10.75V4H9.25V1.75ZM9.25 16H10.75V18.25H9.25V16ZM3.63625 4.69675L4.69675 3.63625L6.2875 5.227L5.227 6.2875L3.63625 4.6975V4.69675ZM13.7125 14.773L14.773 13.7125L16.3638 15.3032L15.3032 16.3638L13.7125 14.773ZM15.3032 3.6355L16.3638 4.69675L14.773 6.2875L13.7125 5.227L15.3032 3.63625V3.6355ZM5.227 13.7125L6.2875 14.773L4.69675 16.3638L3.63625 15.3032L5.227 13.7125ZM18.25 9.25V10.75H16V9.25H18.25ZM4 9.25V10.75H1.75V9.25H4Z" fill="currentColor"/></svg></span>
                        <span class="fa_theme_icon fa_theme_icon--dark"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M8.5 6.25C8.49985 7.29298 8.81035 8.31237 9.39192 9.17816C9.97348 10.0439 10.7997 10.7169 11.7653 11.1112C12.7309 11.5055 13.7921 11.6032 14.8134 11.3919C15.8348 11.1807 16.7701 10.67 17.5 9.925V10C17.5 14.1423 14.1423 17.5 10 17.5C5.85775 17.5 2.5 14.1423 2.5 10C2.5 5.85775 5.85775 2.5 10 2.5H10.075C9.57553 2.98834 9.17886 3.57172 8.90836 4.21576C8.63786 4.8598 8.49902 5.55146 8.5 6.25ZM4 10C3.99945 11.3387 4.44665 12.6392 5.27042 13.6945C6.09419 14.7497 7.24723 15.4992 8.54606 15.8236C9.84489 16.148 11.2149 16.0287 12.4381 15.4847C13.6614 14.9407 14.6675 14.0033 15.2965 12.8215C14.1771 13.0852 13.0088 13.0586 11.9026 12.744C10.7964 12.4295 9.78888 11.8376 8.97566 11.0243C8.16244 10.2111 7.57048 9.20361 7.25596 8.09738C6.94144 6.99116 6.91477 5.82292 7.1785 4.7035C6.21818 5.2151 5.41509 5.97825 4.85519 6.91123C4.2953 7.84422 3.99968 8.91191 4 10Z" fill="currentColor"/></svg></span>
                        <span class="fa_theme_icon fa_theme_icon--system"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M6.875 4.375V6.25H4.375V7.5H6.875V9.375H8.125V4.375H6.875ZM9.375 7.5H15.625V6.25H9.375V7.5ZM13.125 10.625V12.5H15.625V13.75H13.125V15.625H11.875V10.625H13.125ZM10.625 13.75H4.375V12.5H10.625V13.75Z" fill="currentColor"/></svg></span>
                    </button>
                    <ul class="fa_theme_menu" id="fa_theme_menu" role="menu" aria-label="<?php echo esc_attr__('Color theme', 'fluent-affiliate'); ?>" hidden>
                        <li role="none">
                            <button type="button" role="menuitemradio" aria-checked="false" class="fa_theme_item" data-theme="light">
                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M10 14.5C8.80653 14.5 7.66193 14.0259 6.81802 13.182C5.97411 12.3381 5.5 11.1935 5.5 10C5.5 8.80653 5.97411 7.66193 6.81802 6.81802C7.66193 5.97411 8.80653 5.5 10 5.5C11.1935 5.5 12.3381 5.97411 13.182 6.81802C14.0259 7.66193 14.5 8.80653 14.5 10C14.5 11.1935 14.0259 12.3381 13.182 13.182C12.3381 14.0259 11.1935 14.5 10 14.5ZM10 13C10.7956 13 11.5587 12.6839 12.1213 12.1213C12.6839 11.5587 13 10.7956 13 10C13 9.20435 12.6839 8.44129 12.1213 7.87868C11.5587 7.31607 10.7956 7 10 7C9.20435 7 8.44129 7.31607 7.87868 7.87868C7.31607 8.44129 7 9.20435 7 10C7 10.7956 7.31607 11.5587 7.87868 12.1213C8.44129 12.6839 9.20435 13 10 13ZM9.25 1.75H10.75V4H9.25V1.75ZM9.25 16H10.75V18.25H9.25V16ZM3.63625 4.69675L4.69675 3.63625L6.2875 5.227L5.227 6.2875L3.63625 4.6975V4.69675ZM13.7125 14.773L14.773 13.7125L16.3638 15.3032L15.3032 16.3638L13.7125 14.773ZM15.3032 3.6355L16.3638 4.69675L14.773 6.2875L13.7125 5.227L15.3032 3.63625V3.6355ZM5.227 13.7125L6.2875 14.773L4.69675 16.3638L3.63625 15.3032L5.227 13.7125ZM18.25 9.25V10.75H16V9.25H18.25ZM4 9.25V10.75H1.75V9.25H4Z" fill="currentColor"/></svg>
                                <span class="fa_theme_label"><?php esc_html_e('Light', 'fluent-affiliate'); ?></span>
                                <svg class="fa_theme_check" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </li>
                        <li role="none">
                            <button type="button" role="menuitemradio" aria-checked="false" class="fa_theme_item" data-theme="dark">
                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M8.5 6.25C8.49985 7.29298 8.81035 8.31237 9.39192 9.17816C9.97348 10.0439 10.7997 10.7169 11.7653 11.1112C12.7309 11.5055 13.7921 11.6032 14.8134 11.3919C15.8348 11.1807 16.7701 10.67 17.5 9.925V10C17.5 14.1423 14.1423 17.5 10 17.5C5.85775 17.5 2.5 14.1423 2.5 10C2.5 5.85775 5.85775 2.5 10 2.5H10.075C9.57553 2.98834 9.17886 3.57172 8.90836 4.21576C8.63786 4.8598 8.49902 5.55146 8.5 6.25ZM4 10C3.99945 11.3387 4.44665 12.6392 5.27042 13.6945C6.09419 14.7497 7.24723 15.4992 8.54606 15.8236C9.84489 16.148 11.2149 16.0287 12.4381 15.4847C13.6614 14.9407 14.6675 14.0033 15.2965 12.8215C14.1771 13.0852 13.0088 13.0586 11.9026 12.744C10.7964 12.4295 9.78888 11.8376 8.97566 11.0243C8.16244 10.2111 7.57048 9.20361 7.25596 8.09738C6.94144 6.99116 6.91477 5.82292 7.1785 4.7035C6.21818 5.2151 5.41509 5.97825 4.85519 6.91123C4.2953 7.84422 3.99968 8.91191 4 10Z" fill="currentColor"/></svg>
                                <span class="fa_theme_label"><?php esc_html_e('Dark', 'fluent-affiliate'); ?></span>
                                <svg class="fa_theme_check" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </li>
                        <li role="none">
                            <button type="button" role="menuitemradio" aria-checked="false" class="fa_theme_item" data-theme="system">
                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M6.875 4.375V6.25H4.375V7.5H6.875V9.375H8.125V4.375H6.875ZM9.375 7.5H15.625V6.25H9.375V7.5ZM13.125 10.625V12.5H15.625V13.75H13.125V15.625H11.875V10.625H13.125ZM10.625 13.75H4.375V12.5H10.625V13.75Z" fill="currentColor"/></svg>
                                <span class="fa_theme_label"><?php esc_html_e('System', 'fluent-affiliate'); ?></span>
                                <svg class="fa_theme_check" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M3 8.5L6.5 12L13 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </li>
                    </ul>
                </div>
                <?php foreach ($rightMenuItems as $fluentAffiliateRightMenuitem): ?>
                    <div class="fa-navbar__link-wrapper" data-key="<?php echo esc_attr($fluentAffiliateRightMenuitem['key']); ?>">
                        <a href="<?php echo esc_url($fluentAffiliateRightMenuitem['permalink']); ?>" class="fa-navbar__link">
                            <span class="el-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path fill="currentColor" d="M600.704 64a32 32 0 0 1 30.464 22.208l35.2 109.376c14.784 7.232 28.928 15.36 42.432 24.512l112.384-24.192a32 32 0 0 1 34.432 15.36L944.32 364.8a32 32 0 0 1-4.032 37.504l-77.12 85.12a357.12 357.12 0 0 1 0 49.024l77.12 85.248a32 32 0 0 1 4.032 37.504l-88.704 153.6a32 32 0 0 1-34.432 15.296L708.8 803.904c-13.44 9.088-27.648 17.28-42.368 24.512l-35.264 109.376A32 32 0 0 1 600.704 960H423.296a32 32 0 0 1-30.464-22.208L357.696 828.48a351.616 351.616 0 0 1-42.56-24.64l-112.32 24.256a32 32 0 0 1-34.432-15.36L79.68 659.2a32 32 0 0 1 4.032-37.504l77.12-85.248a357.12 357.12 0 0 1 0-48.896l-77.12-85.248A32 32 0 0 1 79.68 364.8l88.704-153.6a32 32 0 0 1 34.432-15.296l112.32 24.256c13.568-9.152 27.776-17.408 42.56-24.64l35.2-109.312A32 32 0 0 1 423.232 64H600.64zm-23.424 64H446.72l-36.352 113.088-24.512 11.968a294.113 294.113 0 0 0-34.816 20.096l-22.656 15.36-116.224-25.088-65.28 113.152 79.68 88.192-1.92 27.136a293.12 293.12 0 0 0 0 40.192l1.92 27.136-79.808 88.192 65.344 113.152 116.224-25.024 22.656 15.296a294.113 294.113 0 0 0 34.816 20.096l24.512 11.968L446.72 896h130.688l36.48-113.152 24.448-11.904a288.282 288.282 0 0 0 34.752-20.096l22.592-15.296 116.288 25.024 65.28-113.152-79.744-88.192 1.92-27.136a293.12 293.12 0 0 0 0-40.256l-1.92-27.136 79.808-88.128-65.344-113.152-116.288 24.96-22.592-15.232a287.616 287.616 0 0 0-34.752-20.096l-24.448-11.904L577.344 128zM512 320a192 192 0 1 1 0 384 192 192 0 0 1 0-384zm0 64a128 128 0 1 0 0 256 128 128 0 0 0 0-256z"></path></svg>
                            </span>
                            <span class="fa-navbar__link-text"><?php echo esc_attr($fluentAffiliateRightMenuitem['label']); ?></span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="fa_mobile_settings_menu_opener">
                <button aria-label="Open Menu" class="fa_menu_opener_btn" aria-disabled="false" type="button" onclick="toggleMobileSettingsMenu()">
                    <i class="el-icon">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.25 4H16.75V5.5H3.25V4ZM3.25 9.25H12.25V10.75H3.25V9.25ZM3.25 14.5H16.75V16H3.25V14.5Z" fill="currentColor"/></svg>
                    </i>
                </button>
            </div>
        </div>
        <div class="fframe_body">
            <div id="fluent-framework-app" class="fs_route_wrapper"></div>
        </div>
    </div>
</div>
