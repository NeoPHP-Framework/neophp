<?php

declare(strict_types=1);

namespace NeoPHP\Component\Api\Helper\Console;

use NeoPHP\Component\Api\OpenApi\OpenApiGenerator;
use NeoPHP\Component\Serializer\Contract\SerializerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;
use stdClass;

#[AsCommand(name: 'openapi:dump', description: 'Dumps the OpenAPI 3.1 document of the API routes (JSON or YAML)')]
class OpenApiDumpCommand extends AbstractConsole
{
    public const EMPTY_OBJECT = '__neo_empty_object__';

    public function __construct(protected OpenApiGenerator $generator, protected SerializerInterface $serializer)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: json or yaml', 'json');
        $input->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the document to this file instead of the standard output');
        $this->setHelp('The documented routes are the ones matching openapi.paths of config/framework/api.yaml (default: ^/api).');
        $this->addExample('openapi:dump');
        $this->addExample('openapi:dump --format=yaml --output=public/openapi.yaml');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $format = strtolower((string) ($input->getOption('format') ?? 'json'));

        if (!in_array($format, ['json', 'yaml', 'yml'], true)) {
            $output->error(sprintf('Unknown format "%s": use json or yaml.', $format));

            return self::FAILURE;
        }

        $document = $this->generator->generate();
        $content = $format === 'json'
            ? (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . PHP_EOL
            : (string) preg_replace('/(["\']?)' . self::EMPTY_OBJECT . '\1/', '{}', $this->serializer->encode($this->yaml($document), 'yaml', ['yaml_inline' => 20]));
        $file = (string) ($input->getOption('output') ?? '');

        if ($file === '') {
            $output->write($content);

            return self::SUCCESS;
        }

        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $output->error(sprintf('Unable to create the directory "%s".', $directory));

            return self::FAILURE;
        }

        if (file_put_contents($file, $content) === false) {
            $output->error(sprintf('Unable to write the file "%s".', $file));

            return self::FAILURE;
        }

        $output->success(sprintf('OpenAPI document written to %s (%d path(s)).', $file, count((array) $document['paths'])));

        return self::SUCCESS;
    }

    protected function yaml(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;

            return $value === [] ? self::EMPTY_OBJECT : array_map(fn (mixed $item): mixed => $this->yaml($item), $value);
        }

        return is_array($value) ? array_map(fn (mixed $item): mixed => $this->yaml($item), $value) : $value;
    }
}