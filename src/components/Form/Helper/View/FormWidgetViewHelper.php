<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Helper\View;

use NeoPHP\Component\Form\Contract\FormInterface;
use NeoPHP\Component\Form\Model\FormView;
use NeoPHP\Component\Form\Renderer\FormRenderer;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Component\View\Contract\ViewSafeHtmlInterface;

/**
 * @internal
 */
class FormWidgetViewHelper implements ViewFunctionInterface, ViewSafeHtmlInterface
{
    public function __construct(protected FormRenderer $renderer)
    {
    }

    public function getName(): string
    {
        return 'form_widget';
    }

    public function __invoke(FormView|FormInterface $form, array $variables = []): string
    {
        return $this->renderer->widget($form, $variables);
    }
}