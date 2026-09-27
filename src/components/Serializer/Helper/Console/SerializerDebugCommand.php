<?php

declare(strict_types=1);

namespace NeoPHP\Component\Serializer\Helper\Console;

use NeoPHP\Component\Serializer\Contract\SerializerInterface;
use NeoPHP\Component\Serializer\Mapping\MetadataFactory;
use NeoPHP\Component\Serializer\SerializerManager;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

#[AsCommand(name: 'serializer:debug', description: 'Displays the serialization metadata of a class (serialized names, groups, types, ignored attributes, max depth, context)')]
class SerializerDebugCommand extends AbstractConsole
{
    public function __construct(protected SerializerInterface $serializer)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('class', InputArgument::REQUIRED, 'The fully qualified class name', null, 'Class to inspect');
        $this->setHelp('Access: "r" readable (normalization), "w" writable (denormalization: setter, public property or constructor argument).');
        $this->addExample('serializer:debug "App\\Entity\\Post"');
        $this->addExample('serializer:debug "App\\Dto\\PostInput"');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $class = ltrim(trim((string) $input->getArgument('class')), '\\');

        if (!class_exists($class)) {
            $output->error(sprintf('The class "%s" does not exist.', $class));

            return self::FAILURE;
        }

        $manager = $this->serializer instanceof SerializerManager ? $this->serializer : null;
        $metadata = ($manager?->getMetadataFactory() ?? new MetadataFactory())->getMetadata($class);
        $converter = $manager?->getNameConverter();
        $rows = [];

        foreach ($metadata->getProperties() as $property) {
            $access = ($property->isReadable() ? 'r' : '') . ($property->isWritable() ? 'w' : '');
            $context = [];

            foreach ($property->contexts as $attribute) {
                $parts = [];

                foreach (['context' => '', 'normalization' => 'norm ', 'denormalization' => 'denorm '] as $key => $label) {
                    if ($attribute[$key] !== []) {
                        $parts[] = $label . $this->export($attribute[$key]);
                    }
                }

                $context[] = implode(' ', $parts) . ($attribute['groups'] !== [] ? ' (groups: ' . implode(', ', $attribute['groups']) . ')' : '');
            }

            $rows[] = [
                $property->name,
                $property->serializedName ?? ($converter === null ? $property->name : $converter->normalize($property->name)),
                $property->groups === [] ? '<muted>-</muted>' : implode(', ', $property->groups),
                $property->describeType(),
                $access === '' ? '<muted>-</muted>' : $access,
                $property->ignored ? 'yes' : 'no',
                $property->maxDepth === null ? '<muted>-</muted>' : (string) $property->maxDepth,
                $context === [] ? '<muted>-</muted>' : implode('; ', $context),
            ];
        }

        $output->title($metadata->class);

        if ($metadata->groups !== []) {
            $output->text('Class groups: ' . implode(', ', $metadata->groups));
        }

        if ($rows === []) {
            $output->warning('This class has no serializable attribute.');

            return self::SUCCESS;
        }

        $output->table(['Property', 'Serialized name', 'Groups', 'Type', 'Access', 'Ignored', 'Max depth', 'Context'], $rows);

        return self::SUCCESS;
    }

    protected function export(array $values): string
    {
        $parts = [];

        foreach ($values as $key => $value) {
            $parts[] = $key . '=' . (is_scalar($value) ? var_export($value, true) : get_debug_type($value));
        }

        return '{' . implode(', ', $parts) . '}';
    }
}