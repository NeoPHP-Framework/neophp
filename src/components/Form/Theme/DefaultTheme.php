<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Theme;

use NeoPHP\Component\Form\Contract\ThemeInterface;
use NeoPHP\Component\Form\Model\Form;
use NeoPHP\Component\Form\Model\FormView;
use NeoPHP\Component\Form\Renderer\FormRenderer;
use Stringable;

class DefaultTheme implements ThemeInterface
{
    public function formStart(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $method = strtoupper((string) ($vars['method'] ?? 'POST'));
        $attr = array_replace([
            'name' => $vars['name'] !== '' ? $vars['name'] : null,
            'method' => $method === 'GET' ? 'get' : 'post',
            'action' => (string) ($vars['action'] ?? ''),
            'enctype' => ($vars['multipart'] ?? false) ? 'multipart/form-data' : null,
        ], (array) ($vars['attr'] ?? []));

        $html = '<form' . $this->attributes($attr) . '>';

        if (!in_array($method, ['GET', 'POST'], true)) {
            $html .= '<input type="hidden" name="_method" value="' . $this->e($method) . '">';
        }

        return $html;
    }

    public function formEnd(FormView $view, array $vars, FormRenderer $renderer): string
    {
        return (($vars['render_rest'] ?? true) ? $renderer->rest($view) : '') . '</form>';
    }

    public function formRest(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $html = '';

        foreach ($view->children as $child) {
            if (!$child->isRendered()) {
                $html .= $renderer->row($child);
            }
        }

        return $html;
    }

    public function formRow(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $widgetVars = [];

        if (!empty($vars['help'])) {
            $widgetVars['attr'] = ['aria-describedby' => $vars['id'] . '_help'];
        }

        return '<div' . $this->attributes($this->rowAttributes($view, $vars)) . '>'
            . $renderer->label($view)
            . $renderer->widget($view, $widgetVars)
            . $renderer->help($view)
            . ($vars['compound'] ? '' : $renderer->errors($view))
            . '</div>';
    }

    public function hiddenRow(FormView $view, array $vars, FormRenderer $renderer): string
    {
        return $renderer->widget($view);
    }

    public function buttonRow(FormView $view, array $vars, FormRenderer $renderer): string
    {
        return '<div' . $this->attributes((array) ($vars['row_attr'] ?? [])) . '>' . $renderer->widget($view) . '</div>';
    }

    public function checkboxRow(FormView $view, array $vars, FormRenderer $renderer): string
    {
        return '<div' . $this->attributes($this->rowAttributes($view, $vars)) . '>'
            . $renderer->widget($view)
            . ' ' . $renderer->label($view)
            . $renderer->help($view)
            . $renderer->errors($view)
            . '</div>';
    }

    public function repeatedRow(FormView $view, array $vars, FormRenderer $renderer): string
    {
        return $renderer->errors($view) . $renderer->widget($view);
    }

    public function formWidget(FormView $view, array $vars, FormRenderer $renderer): string
    {
        return $vars['compound'] ? $this->formWidgetCompound($view, $vars, $renderer) : $this->formWidgetSimple($view, $vars, $renderer);
    }

    public function formWidgetCompound(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $html = '<div' . $this->attributes(['id' => $vars['id'] !== '' ? $vars['id'] : null] + (array) $vars['attr']) . '>';

        if ($view->parent === null || !empty($vars['errors'])) {
            $html .= $renderer->errors($view);
        }

        foreach ($view->children as $child) {
            if (!$child->isRendered()) {
                $html .= $renderer->row($child);
            }
        }

        return $html . '</div>';
    }

    public function formWidgetSimple(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $attr = ['type' => $vars['type'] ?? 'text'] + $this->widgetAttributes($vars) + [
                'value' => ($vars['type'] ?? 'text') === 'file' ? null : $this->scalar($vars['value'] ?? ''),
            ];

        if (($vars['type'] ?? '') === 'file' && ($vars['multiple'] ?? false)) {
            $attr['name'] .= '[]';
        }

        return '<input' . $this->attributes($attr) . '>';
    }

    public function textareaWidget(FormView $view, array $vars, FormRenderer $renderer): string
    {
        return '<textarea' . $this->attributes($this->widgetAttributes($vars)) . '>' . $this->e($this->scalar($vars['value'] ?? '')) . '</textarea>';
    }

    public function checkboxWidget(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $attr = $this->widgetAttributes($vars);
        unset($attr['required']);

        return '<input' . $this->attributes(['type' => 'checkbox'] + $attr + ['value' => $vars['value'] ?? '1', 'checked' => (bool) ($vars['checked'] ?? false)]) . '>';
    }

    public function choiceWidget(FormView $view, array $vars, FormRenderer $renderer): string
    {
        if ($vars['expanded'] ?? false) {
            return $this->choiceWidgetExpanded($view, $vars, $renderer);
        }

        $attr = $this->widgetAttributes($vars);

        if ($vars['multiple'] ?? false) {
            $attr['name'] .= '[]';
            $attr['multiple'] = true;
        }

        $selected = array_map('strval', (array) ($vars['value'] ?? []));
        $html = '<select' . $this->attributes($attr) . '>';

        if (($vars['placeholder'] ?? null) !== null && !($vars['multiple'] ?? false)) {
            $html .= '<option value=""' . ($selected === [''] || $selected === [] ? ' selected' : '') . '>' . $this->e($vars['placeholder']) . '</option>';
        }

        foreach ((array) ($vars['choices'] ?? []) as $choice) {
            $html .= '<option' . $this->attributes(['value' => $choice['value'], 'selected' => in_array((string) $choice['value'], $selected, true)] + (array) $choice['attr']) . '>' . $this->e($choice['label']) . '</option>';
        }

        return $html . '</select>';
    }

    public function choiceWidgetExpanded(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $html = '<div' . $this->attributes(['id' => $vars['id']] + (array) $vars['attr']) . '>';

        foreach ($this->expandedChoices($vars) as $choice) {
            $html .= '<div>' . $this->choiceInput($choice) . ' <label' . $this->attributes(['for' => $choice['id']]) . '>' . $this->e($choice['label']) . '</label></div>';
        }

        return $html . '</div>';
    }

    public function buttonWidget(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $label = $vars['label'] === false || $vars['label'] === null ? Form::humanize((string) $vars['name']) : (string) $vars['label'];
        $attr = ['type' => $vars['type'] ?? 'button', 'id' => $vars['id'], 'name' => $vars['full_name'], 'disabled' => (bool) ($vars['disabled'] ?? false)] + (array) $vars['attr'];

        return '<button' . $this->attributes($attr) . '>' . $this->e($label) . '</button>';
    }

    public function collectionWidget(FormView $view, array $vars, FormRenderer $renderer): string
    {
        if (isset($vars['prototype']) && $vars['prototype'] instanceof FormView) {
            $vars['attr'] = ['data-prototype' => $renderer->row($vars['prototype']), 'data-prototype-name' => $vars['prototype_name'] ?? '__name__'] + (array) $vars['attr'];
        }

        return $this->formWidgetCompound($view, $vars, $renderer);
    }

    public function formLabel(FormView $view, array $vars, FormRenderer $renderer): string
    {
        if (($vars['label'] ?? null) === false) {
            return '';
        }

        $attr = (array) ($vars['label_attr'] ?? []);

        if (!$vars['compound']) {
            $attr = ['for' => $vars['id']] + $attr;
        }

        if ($vars['required'] ?? false) {
            $attr = $this->addClass($attr, 'required');
        }

        return '<label' . $this->attributes($attr) . '>' . $this->e($vars['label'] ?? '') . '</label>';
    }

    public function formErrors(FormView $view, array $vars, FormRenderer $renderer): string
    {
        $errors = (array) ($vars['errors'] ?? []);

        if ($errors === []) {
            return '';
        }

        $html = '<ul class="form-errors">';

        foreach ($errors as $error) {
            $html .= '<li>' . $this->e($error) . '</li>';
        }

        return $html . '</ul>';
    }

    public function formHelp(FormView $view, array $vars, FormRenderer $renderer): string
    {
        if (empty($vars['help'])) {
            return '';
        }

        return '<small' . $this->attributes(['id' => $vars['id'] . '_help'] + (array) ($vars['help_attr'] ?? [])) . '>' . $this->e($vars['help']) . '</small>';
    }

    public function attributes(array $attributes): string
    {
        $html = '';

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            $html .= $value === true ? ' ' . $this->e($name) : ' ' . $this->e($name) . '="' . $this->e($this->scalar($value)) . '"';
        }

        return $html;
    }

    public function addClass(array $attr, string $class): array
    {
        $existing = trim((string) ($attr['class'] ?? ''));
        $attr['class'] = trim($class . ' ' . $existing);

        return $attr;
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars($this->scalar($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function expandedChoices(array $vars): array
    {
        $selected = array_map('strval', (array) ($vars['value'] ?? []));
        $multiple = (bool) ($vars['multiple'] ?? false);
        $choices = [];

        foreach (array_values((array) ($vars['choices'] ?? [])) as $index => $choice) {
            $choices[] = [
                'id' => $vars['id'] . '_' . $index,
                'label' => $choice['label'],
                'attr' => [
                        'type' => $multiple ? 'checkbox' : 'radio',
                        'id' => $vars['id'] . '_' . $index,
                        'name' => $vars['full_name'] . ($multiple ? '[]' : ''),
                        'value' => $choice['value'],
                        'checked' => in_array((string) $choice['value'], $selected, true),
                        'required' => !$multiple && ($vars['required'] ?? false),
                        'disabled' => (bool) ($vars['disabled'] ?? false),
                    ] + (array) $choice['attr'],
            ];
        }

        return $choices;
    }

    protected function choiceInput(array $choice, array $class = []): string
    {
        return '<input' . $this->attributes($choice['attr'] + $class) . '>';
    }

    protected function widgetAttributes(array $vars): array
    {
        $attr = [
            'id' => $vars['id'],
            'name' => $vars['full_name'],
            'required' => (bool) ($vars['required'] ?? false),
            'disabled' => (bool) ($vars['disabled'] ?? false),
        ];

        if (!($vars['valid'] ?? true)) {
            $attr['aria-invalid'] = 'true';
        }

        return array_replace($attr, (array) ($vars['attr'] ?? []));
    }

    protected function rowAttributes(FormView $view, array $vars): array
    {
        return (array) ($vars['row_attr'] ?? []);
    }

    protected function scalar(mixed $value): string
    {
        return match (true) {
            $value === null || $value === false => '',
            $value === true => '1',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            is_array($value) => implode(',', array_map(fn (mixed $item): string => $this->scalar($item), $value)),
            default => '',
        };
    }
}