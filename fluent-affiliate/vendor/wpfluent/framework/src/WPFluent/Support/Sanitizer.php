<?php

namespace FluentAffiliate\Framework\Support;

Class Sanitizer
{
    /**
     * Sanitize an email address.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeEmail($arg)
    {
        return sanitize_email($arg);
    }

    /**
     * Sanitize a file name.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeFileName($arg)
    {
        return sanitize_file_name($arg);
    }

    /**
     * Sanitize an HTML class.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeHtmlClass($arg)
    {
        return sanitize_html_class($arg);
    }

    /**
     * Sanitize a key.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeKey($arg)
    {
        return sanitize_key($arg);
    }

    /**
     * Sanitize meta data.
     *
     * @param string $metaKey       Meta key.
     * @param mixed  $metaValue     Meta value to sanitize.
     * @param string $objectType    Type of object the meta is registered to (e.g., 'post', 'term', 'user').
     * @param string $objectSubtype Optional. Subtype of the object type (e.g., custom post type). Default ''.
     *
     * @return mixed Sanitized meta value.
     */
    public static function sanitizeMeta(
        $metaKey,
        $metaValue,
        $objectType = 'post',
        $objectSubtype = ''
    )
    {
        return sanitize_meta($metaKey, $metaValue, $objectType, $objectSubtype);
    }

    /**
     * Sanitize a mime type.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeMimeType($arg)
    {
        return sanitize_mime_type($arg);
    }

    /**
     * Sanitize an option value.
     *
     * @param string $option The option name.
     * @param mixed  $value  The option value to sanitize.
     * @return mixed
     */
    public static function sanitizeOption(string $option, $value)
    {
        return sanitize_option($option, $value);
    }

    /**
     * Sanitize an SQL ORDER BY clause.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeSqlOrderby($arg)
    {
        return sanitize_sql_orderby($arg);
    }

    /**
     * Sanitize a text field.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeTextField($arg)
    {
        return sanitize_text_field($arg);
    }

    /**
     * Sanitize a title.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeTitle($arg)
    {
        return sanitize_title($arg);
    }

    /**
     * Sanitize a title for a query.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeTitleForQuery($arg)
    {
        return sanitize_title_for_query($arg);
    }

    /**
     * Sanitize a title with dashes.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeTitleWithDashes($arg)
    {
        return sanitize_title_with_dashes($arg);
    }

    /**
     * Sanitize a username.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeUser($arg)
    {
        return sanitize_user($arg);
    }

    /**
     * Filter content through kses for posts.
     *
     * @param string $arg
     * @return string
     */
    public static function wpFilterPostKses($arg)
    {
        return wp_filter_post_kses($arg);
    }

    /**
     * Filter content through kses for no HTML.
     *
     * @param string $arg
     * @return string
     */
    public static function wpFilterNohtmlKses($arg)
    {
        return wp_filter_nohtml_kses($arg);
    }

    /**
     * Escape HTML attribute.
     *
     * @param string $arg
     * @return string
     */
    public static function escAttr($arg)
    {
        return esc_attr($arg);
    }

    /**
     * Escape HTML.
     *
     * @param string $arg
     * @return string
     */
    public static function escHtml($arg)
    {
        return esc_html($arg);
    }

    /**
     * Escape JavaScript.
     *
     * @param string $arg
     * @return string
     */
    public static function escJs($arg)
    {
        return esc_js($arg);
    }

    /**
     * Escape textarea content.
     *
     * @param string $arg
     * @return string
     */
    public static function escTextarea($arg)
    {
        return esc_textarea($arg);
    }

    /**
     * Escape URL.
     *
     * @param string $arg
     * @return string
     */
    public static function escUrl($arg)
    {
        return esc_url($arg);
    }

    /**
     * Escape URL (raw).
     *
     * @param string $arg
     * @return string
     */
    public static function escUrlRaw($arg)
    {
        return esc_url_raw($arg);
    }

    /**
     * Escape XML.
     *
     * @param string $arg
     * @return string
     */
    public static function escXml($arg)
    {
        return esc_xml($arg);
    }

    /**
     * Sanitize content with KSES.
     *
     * @param string       $string      Content to sanitize.
     * @param array|string $allowedHtml Allowed HTML tags. Can be an array of tags/attributes,
     *                                   or a context string accepted by wp_kses_allowed_html().
     *                                   Defaults to 'post'.
     * @return string Sanitized content with only allowed HTML.
     */
    public static function kses($string, $allowedHtml = 'post')
    {
        if (is_string($allowedHtml)) {
            $allowedHtml = wp_kses_allowed_html($allowedHtml);
        }

        return wp_kses($string, $allowedHtml);
    }

    /**
     * Kses post content.
     *
     * @param string $arg
     * @return string
     */
    public static function ksesPost($arg)
    {
        return wp_kses_post($arg);
    }

    /**
     * Kses data.
     *
     * @param string $arg
     * @return string
     */
    public static function ksesData($arg)
    {
        return wp_kses_data($arg);
    }

    /**
     * Escape HTML with translation.
     *
     * @param string $arg
     * @return string
     */
    public static function escHtml__($arg)
    {
        return esc_html__($arg);
    }

    /**
     * Escape attribute with translation.
     *
     * @param string $arg
     * @return string
     */
    public static function escAttr__($arg)
    {
        return esc_attr__($arg);
    }

    /**
     * Escape HTML and echo.
     *
     * @param string $arg
     * @return void
     */
    public static function escHtmlE($arg)
    {
        esc_html_e($arg);
    }

    /**
     * Escape attribute and echo.
     *
     * @param string $arg
     * @return void
     */
    public static function escAttrE($arg)
    {
        esc_attr_e($arg);
    }

    /**
     * Escape HTML with translation context.
     *
     * @param string $text     Text to escape and translate.
     * @param string $context  Context information for translators.
     * @param string $domain   Optional. Text domain. Default 'default'.
     * @return string
     */
    public static function escHtmlX($text, $context, $domain = 'default')
    {
        return esc_html_x($text, $context, $domain);
    }

    /**
     * Escape attribute with translation context.
     *
     * @param string $text     Text to escape and translate.
     * @param string $context  Context information for translators.
     * @param string $domain   Optional. Text domain. Default 'default'.
     * @return string
     */
    public static function escAttrX($text, $context, $domain = 'default')
    {
        return esc_attr_x($text, $context, $domain);
    }

    /**
     * Sanitize data based on given rules.
     *
     * @param array $data
     * @param array $rules
     * @return array
     */
    public static function sanitize(array $data = [], array $rules = [])
    {
        $array = $result = [];

        foreach ($rules as $key => $callbacks) {

            if (!$callbacks) continue;

            $callbacks = is_array($callbacks) ? $callbacks : [$callbacks];

            $array[$key] = $callbacks;

            if (str_contains($key, '*')) {
                $array = static::substituteWildcardKeys($array, $key, $data);
            }

            foreach ($array as $k => $callbacks) {

                $callbacks = static::mayBeFixCallbacks($callbacks);

                if (($value = Arr::get($data, $k)) !== null) {
                    $callbacks = is_callable($callbacks) ? [$callbacks] : $callbacks;

                    while ($callback = static::getCallback(array_shift($callbacks))) {
                        if (is_array($value)) {
                            $value = array_map($callback, $value);
                        } else {
                            $value = $callback($value);
                        }
                    }

                    Arr::set($result, $k, $value);
                }
            }
        }

        return $result;
    }

    /**
     * Normalize wildcard rules to dotted rule.
     * 
     * @param array $array
     * @param string $field
     * @param array $data
     * @return array
     */
    protected static function substituteWildcardKeys($array, $field, $data)
    {
        $callback = $array[$field];

        $keys = array_map(function($v) {
            return trim($v, '.');
        }, explode('*', $field));

        $key = array_shift($keys);
        
        if ($key && ($val = Arr::get($data, $key)) && is_array($val)) {
            
            $dotted = array_keys(Arr::dot($val, $key . '.'));
            
            foreach ($dotted as $dottedField) {
                
                $r = preg_replace('/[0-9]+/', '*', $dottedField);
                
                if (preg_match("/{$field}/", $r)) {
                    
                    $array[$dottedField] = $callback;
                    
                    if (isset($array[$field])) {
                        unset($array[$field]);
                    }
                }
            }
        }

        return $array;
    }

    /**
     * Check and fix if callbacks are given
     * as: callback1|callback2\callback3.
     * 
     * @param array|string $callbacks
     * @return array
     */
    protected static function mayBeFixCallbacks($callbacks)
    {
        $nonFunctionCallables = $functionCallables = [];
            
        foreach ($callbacks as $cb) {
            if (is_callable($cb)) {
                $nonFunctionCallables[] = $cb;
            } elseif (is_string($cb)) {
                 $functionCallables[] = explode('|', $cb);
            } elseif (is_array($cb) && !str_contains($cb[0], '::')) {
                $functionCallables[] = $cb[0];
            } elseif (is_array($cb) && str_contains($cb[0], '::')) {
                $nonFunctionCallables[] = explode('::', $cb[0]);
            }
        }

        $callbacks = array_merge(
            Arr::flatten($functionCallables), $nonFunctionCallables
        );

        return $callbacks;
    }

    /**
     * Get the callback function.
     *
     * @param callable|string $callback
     * @return callable|null
     */
    protected static function getCallback($callback)
    {
        if ($callback) {
            
            if ($cb = static::methodExists($callback)) {
                $callback = $cb;
            }
        }
        
        return $callback;
    }

    /**
     * Check if the method exists.
     *
     * @param string $method
     * @return callable|null
     */
    protected static function methodExists($method)
    {
        $suffix = '';

        if (Str::endsWith($method, '__')) {
            $suffix = '__';
        }
        
        $method = Str::camel($method) . $suffix;

        if (method_exists(static::class, $method)) {
            return [static::class, $method];
        }
    }
}
