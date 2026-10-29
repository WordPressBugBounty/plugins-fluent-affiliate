<?php

namespace FluentAffiliate\Framework\Support;

class Once
{
    protected $trace = null;

    protected $zeroStack = null;

    /**
     * Results remembered for the rest of the request, by hash.
     */
    protected static $values = [];

    /**
     * Results of strict calls, per object: a WeakMap, or SplObjectStorage
     * before PHP 8.
     */
    protected static $objects = null;

    public function __construct(array $trace)
    {
        $this->trace = $trace[1];

        $this->zeroStack = $trace[0];
    }

    public function getArguments()
    {
        return $this->trace['args'];
    }

    public function getFunctionName()
    {
        return $this->trace['function'];
    }

    public function getObjectName()
    {
        return $this->trace['class'] ?? null;
    }

    public function getObject()
    {
        if ($this->globalFunction()) {
            return $this->zeroStack['file'];
        }

        return $this->staticCall() ? $this->trace['class'] : $this->trace['object'];
    }

    public function getHash($strict)
    {
        $object = $this->getObject();

        // The file and line of the Once::call() tell two calls in one method,
        // or at the same line of two files, apart.
        $prefix = $this->zeroStack['file'] . '@' . $this->getObjectName()
            . '@' . $this->getFunctionName() . ':' . $this->zeroStack['line'];

        if ($strict && !is_object($object)) {
            $prefix .= $object;
        }

        return md5($prefix . serialize(
            $this->normalize($this->getArguments())
        ));
    }

    /**
     * Stand-in for each object, so the arguments can be serialized.
     */
    protected function normalize(array $values)
    {
        return array_map(function ($value) {
            if (is_object($value)) {
                return spl_object_hash($value);
            }

            return is_array($value) ? $this->normalize($value) : $value;
        }, $values);
    }

    protected function staticCall()
    {
        return $this->trace['type'] === '::';
    }

    protected function globalFunction()
    {
        return !isset($this->trace['type']);
    }

    public static function call(callable $callback, $strict = false)
    {
        $backtrace = new static(debug_backtrace(
            DEBUG_BACKTRACE_PROVIDE_OBJECT, 2
        ));

        $hash = $backtrace->getHash($strict) . static::hashOfUses($callback);

        $object = $backtrace->getObject();

        if ($strict && is_object($object)) {
            return static::rememberFor($object, $hash, $callback, $backtrace);
        }

        if (!array_key_exists($hash, static::$values)) {
            static::$values[$hash] = call_user_func_array(
                $callback, $backtrace->getArguments()
            );
        }

        return static::$values[$hash];
    }

    /**
     * Forget every remembered result.
     */
    public static function flush()
    {
        static::$values = [];

        static::$objects = null;
    }

    /**
     * Remember a result on the object itself, so it goes away with the
     * object and a new object never inherits it.
     */
    protected static function rememberFor($object, $hash, $callback, $backtrace)
    {
        if (!static::$objects) {
            static::$objects = class_exists('WeakMap', false)
                ? new \WeakMap : new \SplObjectStorage;
        }

        $values = isset(static::$objects[$object]) ? static::$objects[$object] : [];

        if (!array_key_exists($hash, $values)) {
            $values[$hash] = call_user_func_array(
                $callback, $backtrace->getArguments()
            );

            static::$objects[$object] = $values;
        }

        return $values[$hash];
    }

    /**
     * The values a closure captured with use() are part of what it
     * returns, so they are part of the key.
     */
    protected static function hashOfUses($callback)
    {
        if (!$callback instanceof \Closure) {
            return '';
        }

        $uses = (new \ReflectionFunction($callback))->getStaticVariables();

        return $uses ? md5(serialize(
            (new static([[], []]))->normalize($uses)
        )) : '';
    }
}
