<?php

declare(strict_types=1);

namespace NeoPHP\Component\Controller\Helper\Console;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Controller\Maker\ControllerMaker;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\Exception\InvalidInputException;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

#[AsCommand(name: 'make:controller', description: 'Generates a controller in src/Controller/ and its template')]
class MakeControllerCommand extends AbstractConsole
{
    public const FORMAT_LABELS = [
        ControllerMaker::FORMAT_PHP => 'PHP template (templates/<name>/index.php)',
        ControllerMaker::FORMAT_TWIG => 'Twig template (templates/<name>/index.html.twig)',
        ControllerMaker::FORMAT_API => 'JSON response, no template',
        ControllerMaker::FORMAT_NONE => 'plain Response, no template',
    ];

    protected ?string $format = null;

    public function __construct(protected ContainerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('name', InputArgument::REQUIRED, 'The controller name (the "Controller" suffix is added); a sub-namespace is allowed (Admin/Post)', null, 'Name of the controller (e.g. Post, Admin/Dashboard)');
        $input->addOption('twig', null, InputOption::VALUE_NONE, 'Generate a Twig template instead of a PHP template');
        $input->addOption('api', null, InputOption::VALUE_NONE, 'Return JSON, without template');
        $input->addOption('no-template', null, InputOption::VALUE_NONE, 'Return a plain Response, without template');
        $this->setHelp('Creates src/Controller/<Name>Controller.php with an index() action on /<name> (route <name>_index) and its template. Existing files are only replaced with --force.');
        $this->addExample('make:controller Post');
        $this->addExample('make:controller Admin/Dashboard --twig');
        $this->addExample('make:controller Api/Product --api');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if (!$input->isArgumentProvided('name')) {
            $input->setArgument('name', $output->ask('Name of the controller (e.g. Post, Admin/Dashboard)', null, static fn (mixed $value): string => trim((string) $value) !== '' ? trim((string) $value) : throw new InvalidInputException('A value is required.')));

            if (!$input->getOption('twig') && !$input->getOption('api') && !$input->getOption('no-template')) {
                $this->format = (string) $output->choice('Response of the action', self::FORMAT_LABELS, $this->defaultFormat());
            }
        }
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $root = (string) $this->container->get('kernel.root_path');
        $templates = $this->templatesPath($root);
        $maker = new ControllerMaker($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controller', 'App\\Controller', $templates);
        $format = match (true) {
            (bool) $input->getOption('api') => ControllerMaker::FORMAT_API,
            (bool) $input->getOption('no-template') => ControllerMaker::FORMAT_NONE,
            (bool) $input->getOption('twig') => ControllerMaker::FORMAT_TWIG,
            default => $this->format ?? $this->defaultFormat(),
        };

        try {
            $result = $maker->make((string) $input->getArgument('name'), $format, (bool) $input->getOption('force'));
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('  <success>created</success>  %s', $result['file']));

        if ($result['template'] !== null) {
            $output->writeln(sprintf('  <success>created</success>  %s', $result['template']));
        }

        $output->success(sprintf('%s created.', substr($result['class'], (int) strrpos($result['class'], '\\') + 1)));
        $output->text(sprintf('Open <info>%s</info> (route <info>%s</info>). Inject services in the constructor or in the action, e.g. <info>public function index(EntityManagerInterface $entityManager): Response</info>.', $result['route_path'], $result['route_name']));

        return self::SUCCESS;
    }

    protected function defaultFormat(): string
    {
        $templates = $this->templatesPath((string) $this->container->get('kernel.root_path'));

        return is_file($templates . DIRECTORY_SEPARATOR . 'base.html.twig') && !is_file($templates . DIRECTORY_SEPARATOR . 'base.php') ? ControllerMaker::FORMAT_TWIG : ControllerMaker::FORMAT_PHP;
    }

    protected function templatesPath(string $root): string
    {
        return $this->container->has('kernel.templates_path') ? (string) $this->container->get('kernel.templates_path') : $root . DIRECTORY_SEPARATOR . 'templates';
    }
}