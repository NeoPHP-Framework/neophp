<?php

namespace NeoPHP\Package\Orm\Translatable;
use NeoPHP\Package\Orm\Contract\TranslatableInterface;
use NeoPHP\Package\Orm\Exception\MappingException;
use NeoPHP\Package\Orm\Mapping as ORM;
use NeoPHP\Package\Orm\Metadata\MetadataFactory;

/**
 * @phpstan-ignore trait.unused
 */
trait TranslationTrait
{
    #[ORM\Column(length: 40)]
    private string $locale = '';

    private ?TranslatableInterface $translatable = null;

    public static function getTranslatableEntityClass(): string
    {
        $class = MetadataFactory::getRealClass(static::class);

        if (!str_ends_with($class, 'Translation') || strlen($class) === strlen('Translation')) {
            throw new MappingException(
                'The translation entity "{class}" does not end with "Translation": override getTranslatableEntityClass().',
                0,
                null,
                [
                    'class' => $class,
                ]
            );
        }

        return substr($class, 0, -strlen('Translation'));
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTranslatable(): ?TranslatableInterface
    {
        return $this->translatable;
    }

    public function setTranslatable(?TranslatableInterface $translatable): static
    {
        $this->translatable = $translatable;

        return $this;
    }
}