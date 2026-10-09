<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Helper\Console;

use NeoPHP\Package\Markdown\Exception\MarkdownException;
use NeoPHP\Package\Markdown\MarkdownManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'markdown:convert', description: 'Converts an HTML file, a template or a text file into a Markdown file')]
class MarkdownConvertCommand extends AbstractConsole
{
    public function __construct(protected MarkdownManagerInterface $markdown)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('source', InputArgument::REQUIRED, 'The file to convert (.html, .htm, .txt, or a .twig / .php template of templates/)', null, 'File to convert');
        $input->addArgument('target', InputArgument::REQUIRED, 'The Markdown file to write, relative to the project', null, 'Markdown file to write');
        $this->setHelp(implode("\n", [
            'Templates are rendered first (without parameters), then the HTML is converted into Markdown.',
            'The directories of the target are created when needed.',
        ]));
        $this->addExample('markdown:convert templates/page/about.html.twig docs/about.md');
        $this->addExample('markdown:convert public/legacy.html var/markdown/legacy.md');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        try {
            $file = $this->markdown->parse((string) $input->getArgument('source'))->to((string) $input->getArgument('target'));
        } catch (MarkdownException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->newLine();
        $output->writeln(sprintf('  <success>created</success>  %s', $file));
        $output->success('The Markdown file is ready.');

        return self::SUCCESS;
    }
}