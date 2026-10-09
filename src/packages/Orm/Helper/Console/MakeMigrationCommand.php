<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Helper\Console;

use NeoPHP\Package\Orm\Migration\MigrationGenerator;
use NeoPHP\Package\Orm\Migration\Migrator;
use NeoPHP\Package\Orm\OrmManagerInterface;
use NeoPHP\Package\Orm\Schema\SchemaTool;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\Formatter;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'make:migration', description: 'Generates a migration from the differences between the entities and the database')]
class MakeMigrationCommand extends AbstractConsole
{
    public function __construct(protected OrmManagerInterface $orm, protected SchemaTool $schemaTool, protected Migrator $migrator, protected MigrationGenerator $generator)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('empty', null, InputOption::VALUE_NONE, 'Generate an empty migration to write by hand');
        $input->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'The description of the migration (asked when there are changes)', '');
        $this->setHelp('The file is written in migrations/Migration_{hash}.php. The pending migrations must be executed first.');
        $this->addExample('make:migration');
        $this->addExample('make:migration --description="Add the post table"');
        $this->addExample('make:migration --empty');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $empty = (bool) $input->getOption('empty');
        $description = (string) $input->getOption('description');

        try {
            $pending = $this->migrator->getPending();

            if ($pending !== [] && !$empty) {
                $output->error(sprintf('%d migration(s) not executed yet.', count($pending)));
                $output->text('Run <info>php bin/neo migration:migrate</info> first, then generate the new migration.');

                return self::FAILURE;
            }

            [$up, $down] = $empty ? [[], []] : $this->schemaTool->getMigrationSql();

            if ($up === [] && !$empty) {
                $output->note('No changes detected: the database is in sync with the entities.');

                return self::SUCCESS;
            }

            if ($description === '' && !$input->isOptionProvided('description')) {
                $description = trim((string) $output->ask('Description of the migration (optional, press <return> to skip)', ''));
            }

            $file = $this->generator->generate($up, $down, $empty ? null : $this->orm->getPlatform()->getName(), $description);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('  <success>created</success>  %s', $file));

        foreach ($up as $sql) {
            $output->writeln('      <muted>' . Formatter::escape($sql) . ';</muted>', OutputInterface::VERBOSITY_VERBOSE);
        }

        $output->success($empty ? 'Empty migration created.' : sprintf('%d SQL statement(s) in up(), %d in down().', count($up), count($down)));
        $output->text('Review it, then run: <info>php bin/neo migration:migrate</info>');

        return self::SUCCESS;
    }
}