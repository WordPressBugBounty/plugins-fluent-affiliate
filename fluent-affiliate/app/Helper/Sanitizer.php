<?php

namespace FluentAffiliate\App\Helper;

class Sanitizer
{
    const SANITIZE_EMAIL = 'sanitize_email';
    const SANITIZE_FILE_NAME = 'sanitize_file_name';
    const SANITIZE_HEX_COLOR = 'sanitize_hex_color';
    const SANITIZE_HEX_COLOR_NO_HASH = 'sanitize_hex_color_no_hash';
    const SANITIZE_HTML_CLASS = 'sanitize_html_class';
    const SANITIZE_KEY = 'sanitize_key';
    const SANITIZE_META = 'sanitize_meta';
    const SANITIZE_MIME_TYPE = 'sanitize_mime_type';
    const SANITIZE_OPTION = 'sanitize_option';
    const SANITIZE_SQL_ORDERBY = 'sanitize_sql_orderby';
    const SANITIZE_TEXT_FIELD = 'sanitize_text_field';
    const SANITIZE_TEXTAREA_FIELD = 'sanitize_textarea_field';
    const SANITIZE_TITLE = 'sanitize_title';
    const SANITIZE_TITLE_FOR_QUERY = 'sanitize_title_for_query';
    const SANITIZE_TITLE_WITH_DASHES = 'sanitize_title_with_dashes';
    const SANITIZE_USER = 'sanitize_user';
    const SANITIZE_URL = 'sanitize_url';
    const WP_KSES = 'wp_kses';
    const WP_KSES_POST = 'wp_kses_post';

    #array shape [key => sanitize_type]
    public $sanitizers = [
        'email'             => self::SANITIZE_EMAIL,
        'file_name'         => self::SANITIZE_FILE_NAME,
        'hex_color'         => self::SANITIZE_HEX_COLOR,
        'hex_color_no_hash' => self::SANITIZE_HEX_COLOR_NO_HASH,
        'html_class'        => self::SANITIZE_HTML_CLASS,
        'key'               => self::SANITIZE_KEY,
        'meta'              => self::SANITIZE_META,
        'mime_type'         => self::SANITIZE_MIME_TYPE,
        'option'            => self::SANITIZE_OPTION,
        'sql_orderby'       => self::SANITIZE_SQL_ORDERBY,
        'text_field'        => self::SANITIZE_TEXT_FIELD,
        'textarea_field'    => self::SANITIZE_TEXTAREA_FIELD,
        'title'             => self::SANITIZE_TITLE,
        'title_for_query'   => self::SANITIZE_TITLE_FOR_QUERY,
        'title_with_dashes' => self::SANITIZE_TITLE_WITH_DASHES,
        'user'              => self::SANITIZE_USER,
        'url'               => self::SANITIZE_URL,
        'kses'              => self::WP_KSES,
        'kses_post'         => self::WP_KSES_POST
    ];

    public function __call($name, $arguments)
    {
        if (isset($this->sanitizers[$name])) {
            return call_user_func_array($this->sanitizers[$name], $arguments);
        }

        return null;
    }

    public static function __callStatic($name, $arguments)
    {
        return (new static())->__call($name, $arguments);
    }

    public static function sanitize($data, $sanitizer)
    {
        return call_user_func($sanitizer, $data);
    }

    public static function forCsv($value) {

        // Convert to string and handle null values
        $value = is_null($value) ? '' : (string)$value;

        $value = sanitize_text_field($value);
        // Escape double quotes by doubling them
        $value = str_replace('"', '""', $value);
        // Wrap the value in double quotes if it contains commas, quotes, or newlines
        if (preg_match('/[,"\n\r]/', $value)) {
            $value = '"' . $value . '"';
        }
        return $value;
    }
}
