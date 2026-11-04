<?php

namespace NeoPHP\Package\Orm\Translatable;

use NeoPHP\Package\Orm\Collection\ArrayCollection;
use NeoPHP\Package\Orm\Contract\CollectionInterface;
use NeoPHP\Package\Orm\Contract\TranslationInterface;
use NeoPHP\Package\Orm\Exception\OrmException;
use NeoPHP\Package\Orm\Metadata\MetadataFactory;

/**
 * @phpstan-ignore trait.unused
 */
trait TranslatableTrait
{
    private ?CollectionInterface $translations = null;

    public static function getTranslationEntityClass(): string
    {
        return MetadataFactory::getRealClass(static::class) . 'Translation';
    }

    public function getTranslations(): CollectionInterface
    {
        return $this->translations ??= new ArrayCollection();
    }

    public function addTranslation(TranslationInterface $translation): static
    {
        $locale = $translation->getLocale();

        if ($locale === '') {
            throw new OrmException(
                'Set the locale of the translation before adding it to "{class}".',
                0,
                null,
                [
                    'class' => MetadataFactory::getRealClass(static::class),
                ]
            );
        }

        $existing = $this->getTranslation($locale);

        if ($existing === $translation) {
            return $this;
        }

        if ($existing !== null) {
            throw new OrmException(
                'The entity "{class}" already has a translation for the locale "{locale}": update it, or use getOrCreateTranslation().',
                0,
                null,
                [
                    'class' => MetadataFactory::getRealClass(static::class),
                    'locale' => $locale,
                ]
            );
        }

        $this->getTranslations()->add($translation);
        $translation->setTranslatable($this);

        return $this;
    }

    public function removeTranslation(TranslationInterface $translation): static
    {
        if ($this->getTranslations()->removeElement($translation) && $translation->getTranslatable() === $this) {
            $translation->setTranslatable(null);
        }

        return $this;
    }

    public function getTranslation(string $locale): ?TranslationInterface
    {
        foreach ($this->getTranslations() as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }

        return null;
    }

    public function getOrCreateTranslation(string $locale): TranslationInterface
    {
        $translation = $this->getTranslation($locale);

        if ($translation === null) {
            $class = static::getTranslationEntityClass();
            $translation = (new $class())->setLocale($locale);
            $this->addTranslation($translation);
        }

        return $translation;
    }

    public function translate(?string $locale = null, bool $fallback = true): ?TranslationInterface
    {
        $locale ??= TranslatableLocale::getLocale();

        if ($locale !== null && ($translation = $this->getTranslation($locale)) !== null) {
            return $translation;
        }

        if (!$fallback) {
            return null;
        }

        foreach ($locale !== null ? TranslatableLocale::getFallbackLocales($locale) : [] as $fallbackLocale) {
            if (($translation = $this->getTranslation($fallbackLocale)) !== null) {
                return $translation;
            }
        }

        $first = $this->getTranslations()->first();
        return $first instanceof TranslationInterface ? $first : null;
    }
}