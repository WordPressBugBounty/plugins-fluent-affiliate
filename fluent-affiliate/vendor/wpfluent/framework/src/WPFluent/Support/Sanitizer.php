<?php

namespace FluentAffiliate\Framework\Support;

use DateTimeInterface;
use InvalidArgumentException;
use FluentAffiliate\Framework\Support\DateTime;

class Sanitizer
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
     * @param string $objectType    Type of object the meta is registered to
     * (e.g., 'post', 'term', 'user').
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
     * Sanitize an integer.
     * 
     * @param  string $arg
     * @return integer
     */
    public static function sanitizeInt($arg)
    {
        return intval($arg);
    }

    /**
     * Sanitize a boolean the way PHP's FILTER_VALIDATE_BOOLEAN reads one.
     *
     * @param  mixed $arg
     * @return bool|null TRUE for "1", "true", "on", "yes"; FALSE for "0",
     *                   "false", "off", "no", ""; NULL for anything else.
     */
    public static function sanitizeBool($arg)
    {
        if (is_bool($arg)) {
            return $arg;
        }

        if (!is_scalar($arg)) {
            return null;
        }

        return filter_var($arg, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Sanitize an float.
     * 
     * @param  string $arg
     * @return float
     */
    public static function sanitizeFloat($arg)
    {
        return floatval($arg);
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
     * Sanitize a text field.
     *
     * @param string $arg
     * @return string
     */
    public static function sanitizeText($arg)
    {
        return static::sanitizeTextField($arg);
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
     * @param array|string $allowedHtml Allowed HTML tags. Can be an array of
     * tags/attributes,or a context string accepted by wp_kses_allowed_html().
     * Defaults to 'post'.
     * 
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
     * Returns a DateTime object, or null when the value is not a date.
     *
     * @param  string|\DateTimeInterface  $value
     * @param  string|null  $format
     * @param  string|null|\DateTimeZone  $tz
     * @return \FluentAffiliate\Framework\Support\DateTime|null
     */
    public static function sanitizeDate($value, $format, $tz)
    {
        if ($value instanceof DateTimeInterface) {
            return DateTime::parse($value, $tz);
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        try {
            return is_null($format)
                ? DateTime::parse($value, $tz)
                : DateTime::createFromFormat($format, $value, $tz);
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * Sanitize data based on given rules.
     *
     * @param array $data
     * @param array $rules
     * @return array
     */
    public static function sanitize($data = [], $rules = [])
    {
        $result = [];
        $expandedRules = [];

        // Expand rules first (handle wildcards)
        foreach ($rules as $key => $callbacks) {
            if (!$callbacks) {
                continue;
            }

            // [Class::class, 'method'] is one rule, not two rule names.
            $callbacks = is_array($callbacks) && !static::isArrayCallable($callbacks)
                ? $callbacks
                : [$callbacks];

            if (str_contains($key, '*')) {
                $expandedRules = array_merge(
                    $expandedRules,
                    static::substituteWildcardKeys(
                        [$key => $callbacks], $key, $data
                    )
                );
            } else {
                $expandedRules[$key] = $callbacks;
            }
        }

        // Apply sanitization
        foreach ($expandedRules as $k => $callbacks) {
            $callbacks = static::mayBeFixCallbacks($callbacks);

            if (($value = Arr::get($data, $k)) !== null) {
                
                foreach ($callbacks as $cb) {
                    if ($cb = static::getCallback($cb)) {
                        $value = $cb($value);
                    }
                }

                Arr::Set($data, $k, $value);
            }
        }

        return $data;
    }

    public static function substituteWildcardKeys($array, $field, $data)
    {
        $callback = $array[$field];
        
        unset($array[$field]);

        $expand = function (
            $data,
            $segments,
            $prefix = ''
        ) use (
            &$expand,
            $callback,
            &$array
        ) {
            $segment = array_shift($segments);

            if ($segment === null) {
                $array[$prefix] = $callback;
                return;
            }

            if ($segment === '*') {
                if (!is_array($data)) return;

                foreach ($data as $key => $child) {
                    $newPrefix = $prefix === '' ? $key : $prefix . '.' . $key;
                    $expand($child, $segments, $newPrefix);
                }
            } else {
                if (is_array($data) && array_key_exists($segment, $data)) {
                    $newPrefix = $prefix === '' ? $segment : $prefix . '.' . $segment;
                    $expand($data[$segment], $segments, $newPrefix);
                }
            }
        };

        $segments = explode('.', $field);
        $expand($data, $segments);

        return $array;
    }

    /**
     * Check and fix if callbacks are given
     * as: callback1|callback2\callback3.
     * 
     * @param array|string $callbacks
     * @return array
     */
    public static function mayBeFixCallbacks($callbacks)
    {
        $normalized = [];

        foreach ((array)$callbacks as $cb) {
            if (is_string($cb)) {
                $normalized = array_merge($normalized, explode('|', $cb));
            } elseif (is_callable($cb)) {
                $normalized[] = $cb;
            }
        }

        return $normalized;
    }

    /**
     * Get the callback function.
     *
     * @param callable|string $callback
     * @return callable|null
     * @throws \InvalidArgumentException If the rule resolves to nothing callable.
     */
    protected static function getCallback($callback)
    {
        if ($callback) {
            // Only a name can be one of the Sanitizer's own methods; an
            // array callable or an invokable object is used as it is.
            if (!is_string($callback)) {
                if (is_callable($callback)) return $callback;

                throw new InvalidArgumentException(sprintf(
                    'Unknown sanitizer rule: %s.', gettype($callback)
                ));
            }

            if ($cb = static::methodExists($callback)) {
                $callback = $cb;
            } elseif (!is_callable($callback)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown sanitizer rule: %s.',
                    is_string($callback) ? $callback : gettype($callback)
                ));
            }
        }

        return $callback;
    }

    /**
     * Whether a rule is a single array callable, such as
     * [Class::class, 'method'] or [$object, 'method'], rather than a
     * list of rules.
     *
     * @param  mixed  $rule
     * @return bool
     */
    protected static function isArrayCallable($rule)
    {
        return is_array($rule)
            && count($rule) === 2
            && array_keys($rule) === [0, 1]
            && (is_object($rule[0]) || (is_string($rule[0]) && class_exists($rule[0])))
            && is_string($rule[1])
            && is_callable($rule);
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
