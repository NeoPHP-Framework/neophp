<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Contract;

use NeoPHP\Component\Form\Model\FormView;
use NeoPHP\Component\Form\Renderer\FormRenderer;

interface ThemeInterface
{
    public function formStart(FormView $view, array $vars, FormRenderer $renderer): string;

    public function formEnd(FormView $view, array $vars, FormRenderer $renderer): string;

    public function formRest(FormView $view, array $vars, FormRenderer $renderer): string;

    public function formRow(FormView $view, array $vars, FormRenderer $renderer): string;

    public function formWidget(FormView $view, array $vars, FormRenderer $renderer): string;

    public function formLabel(FormView $view, array $vars, FormRenderer $renderer): string;

    public function formErrors(FormView $view, array $vars, FormRenderer $renderer): string;

    public function formHelp(FormView $view, array $vars, FormRenderer $renderer): string;
}