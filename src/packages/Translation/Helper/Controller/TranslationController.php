<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Helper\Controller;

use NeoPHP\Component\Cookie\CookieManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Session\SessionManagerInterface;
use NeoPHP\Package\Translation\Exception\TranslationException;
use NeoPHP\Package\Translation\TranslationManagerInterface;

trait TranslationController
{
    abstract protected function get(string $id): mixed;

    abstract protected function has(string $id): bool;

    protected function translate(string $key, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->get(TranslationManagerInterface::class)->translate($key, $parameters, $domain, $locale);
    }

    protected function switchLocale(string $locale, bool $remember = true): string
    {
        $translator = $this->get(TranslationManagerInterface::class);
        $matched = $translator->matchLocale($locale) ?? throw new TranslationException('The locale "{locale}" is not enabled: use one of "{locales}".', 0, null, [
            'locale' => $locale,
            'locales' => implode('", "', $translator->getLocales()),
        ]);
        $detection = (array) ($translator->getConfig()['detection'] ?? []);

        $translator->setLocale($matched);

        if ($this->has(Request::class)) {
            $this->get(Request::class)->attributes->set(TranslationManagerInterface::ATTRIBUTE, $matched);
        }

        if ($remember && $this->has(SessionManagerInterface::class)) {
            $this->get(SessionManagerInterface::class)->set((string) ($detection['session_key'] ?? '_locale'), $matched);
        }

        if ($remember && $this->has(CookieManagerInterface::class) && (string) ($detection['cookie_name'] ?? '') !== '') {
            $this->get(CookieManagerInterface::class)->set((string) $detection['cookie_name'], $matched, ['lifetime' => (int) ($detection['cookie_lifetime'] ?? 31536000)]);
        }

        return $matched;
    }
}