<?php

namespace FluentAffiliate\Framework\Http\Response;

use WP_REST_Response;

/**
 * A file sent as the response: its headers are ready, and its body is the
 * file, streamed when the response is sent instead of printed as JSON.
 */
class FileResponse extends WP_REST_Response
{
    /**
     * The file's path.
     *
     * @var string
     */
    protected $path;

    /**
     * Build the response for a readable file.
     *
     * @param string $path
     * @param string|null $name The name the browser saves it as.
     */
    public function __construct($path, $name = null)
    {
        $this->path = $path;

        $name = wp_basename($name ?? $path);

        parent::__construct(null, 200, array_merge(wp_get_nocache_headers(), [
            'Content-Description' => 'File Transfer',
            'Content-Type' => static::mimeTypeOf($path, $name),
            'Content-Disposition' => static::attachment($name),
            'Content-Length' => (string) filesize($path),
        ]));
    }

    /**
     * Get the file's path.
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Get the response's data: the file's contents, unless data was set.
     *
     * @return mixed
     */
    public function get_data()
    {
        return $this->data ?? file_get_contents($this->path);
    }

    /**
     * Send the response outside the REST API and end the request.
     *
     * @return void
     */
    public function send()
    {
        if (!headers_sent()) {
            status_header($this->get_status());

            foreach ($this->get_headers() as $name => $value) {
                foreach ((array) $value as $each) {
                    header("{$name}: {$each}", false);
                }
            }
        }

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            $this->stream();
        }

        exit;
    }

    /**
     * Print the file, with no output buffer left to hold it.
     *
     * @return void
     */
    public function stream()
    {
        $this->clearOutputBuffers();

        readfile($this->path);
    }

    /**
     * Drop every output buffer, so nothing printed before, such as a
     * notice, ends up inside the file.
     *
     * @return void
     */
    protected function clearOutputBuffers()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * Stream a file response when WordPress serves a REST response, after
     * it sent the headers and status, instead of printing it as JSON.
     * Runs on rest_pre_serve_request.
     *
     * @param  bool $served
     * @param  mixed $result
     * @param  \WP_REST_Request|null $request
     * @return bool
     */
    public static function serve($served, $result, $request = null)
    {
        if ($served || !$result instanceof static) {
            return $served;
        }

        if (!$request || $request->get_method() !== 'HEAD') {
            $result->stream();
        }

        return true;
    }

    /**
     * Get a file's mime type, from its contents when PHP's fileinfo is
     * there, else from its name.
     *
     * @param  string $path
     * @param  string $name
     * @return string
     */
    protected static function mimeTypeOf($path, $name)
    {
        $type = function_exists('mime_content_type')
            ? mime_content_type($path)
            : wp_check_filetype($name)['type'];

        return $type ?: 'application/octet-stream';
    }

    /**
     * Build the Content-Disposition value: a plain ASCII name for every
     * browser, and the exact name (RFC 6266) for those that read it.
     *
     * @param  string $name
     * @return string
     */
    protected static function attachment($name)
    {
        $plain = sanitize_file_name($name) ?: 'download';

        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $plain,
            rawurlencode($name)
        );
    }
}
