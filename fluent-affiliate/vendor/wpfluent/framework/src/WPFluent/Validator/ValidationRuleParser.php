<?php

namespace FluentAffiliate\Framework\Validator;

use Closure;
use FluentAffiliate\Framework\Framework\Support\Arr;
use FluentAffiliate\Framework\Validator\Rules\Exists;
use FluentAffiliate\Framework\Validator\Rules\Unique;
use FluentAffiliate\Framework\Validator\Rules\ConditionalRules;

class ValidationRuleParser
{
    /**
     * The data being validated.
     *
     * @var array
     */
    public $data;

    /**
     * Create a new validation rule parser.
     *
     * @param array $data
     *
     * @return void
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Parse the human-friendly rules into a full rules array for the validator.
     *
     * @param $rules
     *
     * @return array
     */
    public function explode($rules)
    {
        return $this->explodeRules($rules);
    }

    /**
     * Explode the rules into an array of explicit rules.
     *
     * @param array $rules
     *
     * @return array
     */
    protected function explodeRules($rules)
    {
        foreach ($rules as $attribute => $rule) {
            if (mb_strpos($attribute, '*') !== false) {
                $rules = $this->explodeWildcardRules($rules, $attribute, [$rule]);

                unset($rules[$attribute]);
            } else {
                $rules[$attribute] = $this->explodeExplicitRule($rule);
            }
        }

        return $rules;
    }

    /**
     * Explode the explicit rule into an array if necessary.
     *
     * @param mixed $rule
     *
     * @return array
     */
    protected function explodeExplicitRule($rule)
    {
        $rules = $conditionals = [];

        $givenAsString = is_string($rule);

        $ruleArray = is_array($rule) ? $rule : [$rule];
        
        foreach ($ruleArray as  $key => $rule) {

            if ($rule instanceof ConditionalRules) {
                $conditionals = $this->parseConditionalRules($rule);
            } elseif($rule instanceof Closure) {
                $rules[$key] = $rule;
            } else {
                if (
                    ($rule instanceof Exists && $rule->queryCallbacks()) ||
                    ($rule instanceof Unique && $rule->queryCallbacks())
                ) {
                    $rules[] = $rule;
                } else {
                    $rules[] = (string) $rule;
                }
            }
        }

        $rules = array_merge($rules, $conditionals);

        // Now, we'll check if there is any string rule
        // given inside the array using the | sign, i.e:
        // required|alpha, if any, we'll convert it to array.
        // A regex rule inside an array is kept whole, since
        // its pattern may contain the | sign itself.

        $result = [];

        foreach ($rules as $key => $value) {
            if (! is_string($value)) {
                $result[$key] = $value;
            } elseif (! $givenAsString && static::isRegexRule($value)) {
                $result[] = $value;
            } else {
                $result = array_merge($result, explode('|', $value));
            }
        }

        return $result;
    }

    /**
     * Determine if the given rule is a regex rule.
     *
     * @param  string  $rule
     * @return bool
     */
    protected static function isRegexRule($rule)
    {
        return stripos(ltrim($rule), 'regex:') === 0;
    }

    /**
     * Parse conditional rules.
     * 
     * @param  \FluentAffiliate\Framework\Validator\Rules\ConditionalRules $rule
     * @return array
     */
    protected function parseConditionalRules($rule)
    {
        $rules = [];

        $conditionals = $rule->passes($this->data)
                                ? array_filter($rule->rules())
                                : array_filter($rule->defaultRules());
                
        if ($conditionals) {
            foreach ($conditionals as $conditional) {
                if (is_object($conditional) && method_exists($conditional, '__toString')) {
                    $rules[] = (string) $conditional;
                } elseif (is_string($conditional)) {
                    $rules[] = $conditional;
                }
            }
        }

        return $rules;
    }

    /**
     * Define a set of rules that apply to each element in an array attribute.
     *
     * @param array $results
     * @param string $attribute
     * @param string|array $rules
     *
     * @return array
     */
    protected function explodeWildcardRules($results, $attribute, $rules)
    {
        // Turn the name into a pattern where each '*' stands for any run of
        // characters without a dot, even none: 'items.*.name' matches
        // 'items.0.name' and 'items..name', not 'items.0.1.name'.
        $pattern = str_replace('\*', '[^\.]*', preg_quote($attribute, '/'));

        $data = ValidationData::initializeAndGatherData($attribute, $this->data);

        foreach ($data as $key => $value) {
            // The key starts with the name as written, or matches the whole
            // pattern: ^ is the start of the key and \z its very end.
            if (substr($key, 0, strlen($attribute)) === $attribute || (bool) preg_match('/^'.$pattern.'\z/', $key)) {
                foreach ((array) $rules as $rule) {
                    $results = $this->mergeRules($results, $key, $rule, $attribute);
                }
            }
        }

        return $results;
    }

    /**
     * Merge additional rules into a given attribute(s).
     *
     * @param array $results
     * @param string|array $attribute
     * @param string|array $rules
     * @param string|null  $originalRuleKey
     *
     * @return array
     */
    public function mergeRules($results, $attribute, $rules = [], $originalRuleKey = null)
    {
        if (is_array($attribute)) {
            foreach ((array) $attribute as $innerAttribute => $innerRules) {
                $results = $this->mergeRulesForAttribute($results, $innerAttribute, $innerRules, $originalRuleKey);
            }

            return $results;
        }

        return $this->mergeRulesForAttribute(
            $results, $attribute, $rules , $originalRuleKey
        );
    }

    /**
     * Merge additional rules into a given attribute.
     *
     * @param array $results
     * @param string $attribute
     * @param string|array $rules
     * @param string|null  $originalRuleKey
     *
     * @return array
     */
    protected function mergeRulesForAttribute($results, $attribute, $rules, $originalRuleKey = null)
    {
        $array = $this->explodeRules([$rules]);

        $merge = reset($array);

        if(!empty($originalRuleKey)){
            $merge['rule_key'] = $originalRuleKey;
        }

        $results[$attribute] = array_merge(
            isset($results[$attribute]) ?
            $this->explodeExplicitRule($results[$attribute])
            : [], $merge
        );

        return $results;
    }

    /**
     * Extract the rule name and parameters from a rule.
     *
     * @param $rule
     *
     * @return array
     */
    public static function parse($rule)
    {
        if ($rule instanceof Closure) {
            return [$rule, []];
        }

        $parameters = [];

        if (strpos($rule, ':') !== false) {
            list($rule, $parameter) = explode(':', $rule, 2);

            $parameters = static::parseParameters($rule, $parameter);
        }

        return [trim($rule), $parameters];
    }

    /**
     * Parse the parameter list of a rule.
     *
     * A regex pattern is a single parameter, as it may contain commas.
     *
     * @param  string  $rule
     * @param  string  $parameter
     * @return array
     */
    protected static function parseParameters($rule, $parameter)
    {
        if (strtolower(trim($rule)) === 'regex') {
            return [$parameter];
        }

        return str_getcsv($parameter, ',', '"', '\\');
    }
}
