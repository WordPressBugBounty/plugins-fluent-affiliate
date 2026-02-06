<?php
namespace FluentAffiliate\App\Modules\Auth;

use FluentAffiliate\Framework\Support\Arr;

class FormBuilder
{
    private $formFields = [];

    public function __construct($formFields)
    {
        $this->formFields = $formFields;
    }

    public function render()
    {
        foreach ($this->formFields as $index => $field) {
            if (empty($field['disabled'])) {
                $this->renderField($field);
            }
        }
    }

    private function renderField($field)
    {
        $type = $field['type'];
        $label = $field['label'] ?? '';
        $name = $field['name'];
        $required = Arr::get($field, 'required') === 'yes';
        $options = $field['options'] ?? [];
        $value = $field['value'] ?? '';
        $readonly =  Arr::get($field, 'readonly') === 'yes';
        $helpText = $field['help_text'] ?? '';

        $atts = array_filter([
            'type'        => in_array($type, ['text', 'email', 'password', 'url']) ? $type : '',
            'id'          => 'fa_' . $name,
            'name'        => $name,
            'value'       => $value,
            'required'    => '',//$required ? 'required' : '',
            'readonly'    => $readonly ? 'readonly' : '',
            'placeholder' => $field['placeholder'] ?? '',
            'class'       => $field['input_class'] ?? '',
        ]);

        echo "<div id='fa_group_" . esc_attr($name) . "' class='fa_form-group'>";
        if ($label):
            echo "<div class='fa_form_label'><label for='" . esc_attr($atts['id']) . "'>" . esc_html($label) . "</label></div>";
        endif;

        echo "<div class='fa_form_input'>";

        if (isset($atts['type'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printAtts() method escapes all attributes
            echo "<input " . $this->printAtts($atts) . ">";
        } elseif ($type === 'select') {
            echo "<select id='" . esc_attr($name) . "' name='" . esc_attr($name) . "' " . ($required ? 'required' : '') . ">";
            foreach ($options as $option) {
                echo "<option value='".esc_attr($option)."'>".esc_html($option)."</option>";
            }
            echo "</select>";
        } else if ($type === 'inline_checkbox') {
            echo "<div class='fa_inline_checkbox'>";
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printAtts() method escapes all attributes
            echo "<input type='checkbox' " . $this->printAtts($atts) . ">";
            echo "<label for='" . esc_attr($atts['id']) . "'>" . wp_kses_post($field['inline_label']) . "</label>";
            echo "</div>";
        } else if ($type === 'textarea') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printAtts() method escapes all attributes
            echo "<textarea " . $this->printAtts($atts) . "></textarea>";
        } else if($type == 'raw_html') {
            echo "<div class='fa_raw_html'>" . wp_kses_post($field['html']) . "</div>";
        }

        if($helpText) {
            echo "<div class='fa_help_text'>" . wp_kses_post($helpText) . "</div>";
        }

        echo "</div></div>";
    }

    private function printAtts($atts)
    {
        $result = '';
        foreach ($atts as $key => $value) {
            $result .= " " . esc_attr($key) . "='" . esc_attr($value) . "'";
        }
        return $result;
    }
}
