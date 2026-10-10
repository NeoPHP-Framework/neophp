<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Theme;

use NeoPHP\Component\Form\Model\FormView;
use NeoPHP\Component\Form\Renderer\FormRenderer;

class Bootstrap5Theme extends DefaultTheme
{
    public function formRow(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $vars['row_attr'] = $this->addClass((array) ($vars['row_attr'] ?? []), 'mb-3');

        return parent::formRow($view, $vars, $renderer);
    }

    public function buttonRow(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $vars['row_attr'] = $this->addClass((array) ($vars['row_attr'] ?? []), 'mb-3');

        return parent::buttonRow($view, $vars, $renderer);
    }

    public function checkboxRow(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $attr = $this->addClass((array) ($vars['row_attr'] ?? []), 'mb-3 form-check');

        return '<div' . $this->attributes($attr) . '>'
            . $renderer->widget($view, $this->rowWidgetVars($vars))
            . $renderer->label($view, null, ['label_attr' => $this->addClass((array) ($vars['label_attr'] ?? []), 'form-check-label')] + $this->rowLabelVars($vars))
            . $renderer->help($view, $this->rowHelpVars($vars))
            . $renderer->errors($view)
            . '</div>';
    }

    public function formWidgetSimple(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $type = $vars['type'] ?? 'text';

        if ($type !== 'hidden') {
            $vars['attr'] = $this->addClass((array) ($vars['attr'] ?? []), $type === 'color' ? 'form-control form-control-color' : ($type === 'range' ? 'form-range' : 'form-control') . (($vars['valid'] ?? true) ? '' : ' is-invalid'));
        }

        return parent::formWidgetSimple($view, $vars, $renderer);
    }

    public function textareaWidget(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $vars['attr'] = $this->addClass((array) ($vars['attr'] ?? []), 'form-control' . (($vars['valid'] ?? true) ? '' : ' is-invalid'));

        return parent::textareaWidget($view, $vars, $renderer);
    }

    public function checkboxWidget(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $vars['attr'] = $this->addClass((array) ($vars['attr'] ?? []), 'form-check-input' . (($vars['valid'] ?? true) ? '' : ' is-invalid'));

        return parent::checkboxWidget($view, $vars, $renderer);
    }

    public function choiceWidget(FormView $view, array $vars, FormRenderer $renderer): string
    {
        if (!($vars['expanded'] ?? false)) {
            $vars['attr'] = $this->addClass((array) ($vars['attr'] ?? []), 'form-select' . (($vars['valid'] ?? true) ? '' : ' is-invalid'));
        }

        return parent::choiceWidget($view, $vars, $renderer);
    }

    public function choiceWidgetExpanded(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $html = '<div' . $this->attributes(['id' => $vars['id']] + (array) $vars['attr']) . '>';

        foreach ($this->expandedChoices($vars) as $choice) {
            $choice['attr'] = $this->addClass($choice['attr'], 'form-check-input' . (($vars['valid'] ?? true) ? '' : ' is-invalid'));
            $html .= '<div class="form-check">' . $this->choiceInput($choice) . '<label' . $this->attributes(['class' => 'form-check-label', 'for' => $choice['id']]) . '>' . $this->e($choice['label']) . '</label></div>';
        }

        return $html . '</div>';
    }

    public function buttonWidget(FormView $view, array $vars, FormRenderer $renderer): string
    {
        if (!isset($vars['attr']['class'])) {
            $vars['attr'] = $this->addClass((array) ($vars['attr'] ?? []), ($vars['type'] ?? 'button') === 'submit' ? 'btn btn-primary' : 'btn btn-secondary');
        }

        return parent::buttonWidget($view, $vars, $renderer);
    }

    public function formLabel(FormView $view, array $vars, FormRenderer $renderer): string
    {
        if (!str_contains((string) (($vars['label_attr'] ?? [])['class'] ?? ''), 'form-check-label')) {
            $vars['label_attr'] = $this->addClass((array) ($vars['label_attr'] ?? []), 'form-label');
        }

        return parent::formLabel($view, $vars, $renderer);
    }

    public function formErrors(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $errors = (array) ($vars['errors'] ?? []);

        if ($errors === []) {
            return '';
        }

        $messages = implode('<br>', array_map(fn (mixed $error): string => $this->e($error), $errors));

        return $view->parent === null || ($vars['compound'] ?? false)
            ? '<div class="alert alert-danger">' . $messages . '</div>'
            : '<div class="invalid-feedback d-block">' . $messages . '</div>';
    }

    public function formHelp(FormView $view, array $vars, FormRenderer $renderer): string
    {
        if (empty($vars['help'])) {
            return '';
        }

        return '<div' . $this->attributes($this->addClass(['id' => $vars['id'] . '_help'] + (array) ($vars['help_attr'] ?? []), 'form-text')) . '>' . $this->e($vars['help']) . '</div>';
    }
}