<?php

namespace ACFNinjaformsField;

use acf_field;
use Ninja_Forms;

class Field extends acf_field
{
    /**
     * Make sure we can easily access our notices
     *
     * @var Notices
     */
    public $notices;

    /**
     * Get our forms
     *
     * @var array
     */
    public $forms;

    public function __construct()
    {
        $this->name = 'forms';
        $this->label = __('Forms', 'ninja-forms');
        $this->category = __('Relational', 'acf');
        $this->defaults = [
            'return_format' => 'form_object',
            'multiple'      => 0,
            'default_value' => '',
            'allow_null'    => 0
        ];

        // Get our notices up and running
        $this->notices = new Notices();

        if (class_exists('Ninja_Forms')) {
            $this->forms = Ninja_Forms()->form()->get_forms();
        }

        // Execute the parent constructor as well
        parent::__construct();
    }

    /**
     * Create extra settings for our ninjaforms field. These are visible when editing a field.
     *
     * @param $field
     */
    public function render_field_settings($field)
    {
        // Render a field settings that will tell us if an empty field is allowed or not
        acf_render_field_setting($field, [
            'label'        => __('Return Value', 'acf'),
            'instructions' => __('Specify the returned value on front end', 'acf'),
            'type'         => 'radio',
            'name'         => 'return_format',
            'layout'       => 'horizontal',
            'choices'      => [
                'post_object' => __('Form Object', ACF_NF_FIELD_TEXTDOMAIN),
                'id'          => __('Form ID', ACF_NF_FIELD_TEXTDOMAIN)
            ],
        ]);

        // Render a field setting that will provide the option of selecting a default form.
        acf_render_field_setting( $field, array(
            'label'         => __('Default Form','acf'),
            'instructions'  => __('Choose a default form','acf'),
            'name'          => 'default_value',
            'type'          => 'select',
            'choices'       => $this->getFormChoices()
        ));

        // Render a field setting that will tell us if an empty field is allowed or not.
        acf_render_field_setting($field, [
            'label'   => __('Allow Null?', 'acf'),
            'type'    => 'radio',
            'name'    => 'allow_null',
            'choices' => [
                1 => __('Yes', 'acf'),
                0 => __('No', 'acf'),
            ],
            'layout'  => 'horizontal'
        ]);

        // Render a field setting that will tell us if multiple forms are allowed.
        acf_render_field_setting($field, [
            'label'   => __('Select multiple values?', 'acf'),
            'type'    => 'radio',
            'name'    => 'multiple',
            'choices' => [
                1 => __('Yes', 'acf'),
                0 => __('No', 'acf'),
            ],
            'layout'  => 'horizontal'
        ]);
    }

    /**
     * Render our Ninja Form field with all the forms as options
     *
     * @param $field
     * @return bool
     */
    public function render_field($field)
    {
        // Set our defaults
        $field = array_merge($this->defaults, $field);
        $choices = [];

        // Check if we have some valid forms
        if (!$this->hasValidForms()) {
            return false;
        }

        $choices = $this->getFormChoices();

        // Override field settings and start rendering
        $field['choices'] = $choices;
        $field['type'] = 'select';
        // Create a css id for our field
        $fieldId = str_replace(['[', ']'], ['-', ''], $field['name']);

        // Check if we're allowing multiple selections.
        $hiddenField = '';
        $multiple = '';
        $fieldOptions = '';

        if ($field['multiple']) {
            $hiddenField = '<input type="hidden" name="{$field[\'name\']}">';
            $multiple = '[]" multiple="multiple" data-multiple="1';
        }

        // Check if we're allowing an empty form. If so, create a default option
        if ($field['allow_null']) {
            $fieldOptions .= '<option value="">' . __('- Select a form -', ACF_NF_FIELD_TEXTDOMAIN) . '</option>';
        }

        // Loop trough all our choices
        foreach ($field['choices'] as $formId => $formTitle) {
            $selected = '';

            if ((is_array($field['value']) && in_array($formId, $field['value'], false))
                || (int)$field['value'] === (int)$formId
            ) {
                $selected = ' selected';
            }

            $fieldOptions .= '<option value="' . $formId . '"' . $selected . '>' . $formTitle . '</option>';
        }

        // Start building the html for our field
        $fieldHhtml = $hiddenField;
        $fieldHhtml .= '<select id="' . $fieldId . '" name="' . $field['name'] . $multiple . '">';
        $fieldHhtml .= $fieldOptions;
        $fieldHhtml .= '</select>';

        echo $fieldHhtml;
    }

    /**
     * Return a form object when not empty
     *
     * @param $value
     * @param $postId
     * @param $field
     * @return array|bool
     */
    public function format_value($value, $postId, $field)
    {
        return $this->processValue($value, $field);
    }

    /**
     *
     *  This filter is applied to the $value before it is updated in the db
     *
     * @param  $value - the value which will be saved in the database
     *
     * @return $value - the modified value
     */
    public function update_value($value)
    {
        // Strip empty array values
        if (is_array($value)) {
            $value = array_values(array_filter($value));
        }
        return $value;
    }

    /**
     * Check what to return on basis of return format
     *
     * @param $value
     * @param $field
     * @return array|bool|int
     */
    public function processValue($value, $field)
    {
        if (is_array($value)) {
            $formObjects = [];

            foreach ($value as $key => $formId) {
                $form = $this->processValue($formId, $field);
                //Add it if it's not an error object
                if ($form) {
                    $formObjects[$key] = $form;
                }
            }

            // Return the form object
            if (!empty($formObjects)) {
                return $formObjects;
            }

            // Else return false
            return false;
        }

        // Make sure field is an array
        $field = (array)$field;

        if (!empty($field['return_format']) && $field['return_format'] === 'id') {
            return (int)$value;
        }
        $form = Ninja_Forms()->form($value)->get();

        //Return the form object if it's not an error object. Otherwise return false.
        if (!is_wp_error($form)) {
            return $form;
        }

        return false;
    }

    /**
     * Check if we actually have forms that we can use for our field
     *
     * @return bool
     */
    public function hasValidForms()
    {
        // Stop if Ninjaforms is not active
        if (!class_exists('Ninja_Forms')) {
            $this->notices->isNinjaFormsActive(true, true);

            return false;
        }

        // Check if there are forms and set our choices
        if (!$this->forms) {
            $this->notices->hasActiveNinjaForms(true, true);

            return false;
        }

        return true;
    }

    /**
     * Get the Ninja Form choices - normally used by select field.
     * 
     * @return array The key value pairs for the select.
     */
    protected function getFormChoices() {

        $choices = [];
        foreach ($this->forms as $form) {
            $choices[$form->get_id()] = $form->get_setting('title');
        }
        return $choices;
    }
}
