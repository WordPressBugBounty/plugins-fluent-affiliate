<?php

namespace FluentAffiliate\Framework\Http\Concerns;

use InvalidArgumentException;

trait RegistersRouteTrait
{
    /**
     * Register the rest endpoint
     *
     * @return null
     */
    public function register()
    {
        $this->updateRouteOptions();

        return register_rest_route(
            $this->restNamespace,
            $this->getRouteUri(),
            $this->getOptions(),
            $this->shouldOverride
        );
    }

    /**
     * Update route options before registering.
     * 
     * @return void
     */
    protected function updateRouteOptions()
    {
        $this->setOptions();
    }

    /**
     * Get normalized uri for the current route.
     * 
     * @return string
     */
    protected function getRouteUri()
    {
        return '/' . trim($this->compileRoute($this->uri), '/');
    }

    /**
     * Set route options
     *
     * @return null
     */
    protected function setOptions()
    {
        $this->options = array_merge(
            $this->options, $this->getDefaultOptions()
        );
    }

    /**
     * Get default options.
     * 
     * @return array
     */
    protected function getDefaultOptions()
    {
        return [
            [
                'methods' => $this->method,
                'callback' => [$this, 'callback'],
                'permission_callback' => [$this, 'permissionCallback'],
                'args' => [],
            ],
        ];
    }

    /**
     * Compikle the rest route to regex
     *
     * @param string $uri
     * @return string compiled rest endpoint
     */
    protected function compileRoute($uri)
    {
        $params = [];

        // Turn each '/{name}' in the URI into a WordPress REST named group,
        // '/(?P<name>...)', using the name's where() pattern if it has one;
        // '/{name?}' becomes optional. '/users/{id}/posts/{slug?}' with
        // int('id') -> '/users/(?P<id>[0-9]+)/posts(?:/(?P<slug>...))?'.
        $compiledUri = preg_replace_callback('#/{(.*?)}#', function ($match) use (&$params, $uri) {
            // Default: one or more characters that are not whitespace or '/',
            // so a value never spans two path segments: 'hello-world' and
            // 'hello!' match, 'a/b' doesn't.
            $regx = '[^\s/]+';

            $param = trim($match[1]);

            if ($isOptional = strpos($param, '?')) {
                $param = trim($param, '?');
            }

            if (in_array($param, $params, true)) {
                throw new InvalidArgumentException(
                    "Duplicate parameter name '{$param}' found in {$uri}.", 500
                );
            }

            $params[] = $param;

            if (isset($this->wheres[$param])) {
                $regx = $this->wheres[$param];
            }

            // A named group, which WordPress passes on as the parameter:
            // '/(?P<id>[0-9]+)' takes '5' from '/5' as id.
            $pattern = "/(?P<" . $param . ">" . $regx . ")";

            if ($isOptional) {
                // Wrap it so the whole '/value' part may be left out.
                $pattern = "(?:" . $pattern . ")?";
            }

            $this->options['args'][$param]['required'] = !$isOptional;

            return $pattern;

        }, $uri);

        return $this->compiled = $compiledUri;
    }
}
