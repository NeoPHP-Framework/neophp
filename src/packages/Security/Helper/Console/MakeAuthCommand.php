<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Package\Security\Maker\AuthMaker;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'make:auth', description: 'Generates a login controller and its template')]
class MakeAuthCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('name', InputArgument::OPTIONAL, 'The controller name', 'SecurityController', 'Name of the login controller');
        $input->addOption('twig', null, InputOption::VALUE_NONE, 'Generate a Twig template instead of a PHP template', null, 'Generate a Twig template instead of a PHP template');
        $this->addExample('make:auth');
        $this->addExample('make:auth LoginController --twig');
        $this->addExample('make:auth --force');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $root = (string) $this->container->get('kernel.root_path');
        $templates = $this->container->has('kernel.templates_path') ? (string) $this->container->get('kernel.templates_path') : $root . '/templates';
        $maker = new AuthMaker($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controller', 'App\\Controller', $templates);

        try {
            [, $file, $template, $layout] = $maker->make((string) $input->getArgument('name'), (bool) $input->getOption('twig'), (bool) $input->getOption('force'));
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('  <success>created</success>  %s', $file));
        $output->writeln(sprintf('  <success>created</success>  %s', $template));

        if ($layout !== null) {
            $output->writeln(sprintf('  <success>created</success>  %s <muted>(layout extended by the login template)</muted>', $layout));
        }

        $output->success('Login controller created.');
        $output->text([
            'Enable the login form in <info>config/packages/security.yaml</info>:',
            '',
            '  <comment>firewalls:</comment>',
            '  <comment>  main:</comment>',
            '  <comment>    form_login:</comment>',
            '  <comment>      login_path: app_login</comment>',
            '  <comment>      enable_csrf: true</comment>',
            '  <comment>    logout:</comment>',
            '  <comment>      path: app_logout</comment>',
            '  <comment>      target: /</comment>',
            '  <comment>      enable_csrf: true</comment>',
            '  <comment>      methods: [POST]</comment>',
            '',
            'Logout button in a template: <info>{{ logout_form(\'Logout\') }}</info>',
        ]);

        return self::SUCCESS;
    }
}