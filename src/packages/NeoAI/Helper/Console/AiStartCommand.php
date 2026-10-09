<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Helper\Console;

use NeoPHP\Package\NeoAI\Agent\Assistant;
use NeoPHP\Package\NeoAI\Contract\AbstractAiCommand;
use NeoPHP\Package\NeoAI\Exception\NeoAiException;
use NeoPHP\Package\NeoAI\Model\Conversation;
use NeoPHP\Package\NeoAI\Model\Finding;
use NeoPHP\Package\NeoAI\NeoAiManager;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\Formatter;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'ai:start', description: 'Starts an interactive chat with the NeoAI development assistant', aliases: ['ai'])]
class AiStartCommand extends AbstractAiCommand
{
    public const HELP = [
        '/help' => 'Show this help',
        '/exit' => 'Quit (also /quit or Ctrl+D)',
        '/clear' => 'Start a new conversation',
        '/model [connection]' => 'List the connections or switch to another one',
        '/file <path>' => 'Attach a project file to the next question',
        '/scan [focus] [path]' => 'Quick audit (focus: all, security, performance, bugs, conventions)',
        '/patches' => 'Review again the patches of the last answer',
    ];

    protected ?Assistant $assistant = null;

    protected array $attachments = [];

    protected array $lastPatches = [];

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('connection', null, InputOption::VALUE_REQUIRED, 'The connection to use (default: default_connection)');
        $input->addOption('ask', null, InputOption::VALUE_REQUIRED, 'Ask a single question and exit');
        $this->addExample('ai:start');
        $this->addExample('ai:start --connection=local');
        $this->addExample('ai:start --ask="Where are the routes of the blog defined?"');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $manager = $this->manager($output);

        if ($manager === null) {
            return self::FAILURE;
        }

        $connection = $input->getOption('connection');

        try {
            $this->assistant = $manager->assistant(is_string($connection) && $connection !== '' ? $connection : null);
        } catch (NeoAiException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $conversation = Conversation::create(['source' => 'console']);
        $question = $input->getOption('ask');

        if (is_string($question) && trim($question) !== '') {
            $this->privacy($output, $this->assistant->getProvider());

            return $this->send($output, $manager, $conversation, $question) ? self::SUCCESS : self::FAILURE;
        }

        if (!$output->isInteractive()) {
            $output->error('ai:start is interactive: use --ask="..." in non interactive mode.');

            return self::FAILURE;
        }

        $output->title('NeoAI assistant');
        $this->privacy($output, $this->assistant->getProvider());
        $output->writeln(' <muted>Type your question, /help for the commands, /exit to quit.</muted>');

        while (true) {
            $output->newLine();
            $line = $output->ask('You');

            if ($line === null) {
                break;
            }

            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '/')) {
                if (!$this->command($output, $manager, $conversation, $line)) {
                    break;
                }

                continue;
            }

            $this->send($output, $manager, $conversation, $line);
        }

        $output->writeln(' <muted>Bye.</muted>');

        return self::SUCCESS;
    }

    protected function send(OutputInterface $output, NeoAiManager $manager, Conversation $conversation, string $question): bool
    {
        $provider = $this->assistant->getProvider();
        $output->writeln(sprintf(' <muted>... %s is thinking</muted>', Formatter::escape($provider->getModel())));
        $this->assistant->onStep(static function (string $tool, array $arguments) use ($output): void {
            $detail = (string) ($arguments['path'] ?? $arguments['dir'] ?? $arguments['regex'] ?? $arguments['token'] ?? '');
            $output->writeln(sprintf('   <muted>> %s %s</muted>', Formatter::escape($tool), Formatter::escape(mb_substr($detail, 0, 80))));
        });

        try {
            $reply = $this->assistant->ask($conversation, $question, $this->attachments);
        } catch (NeoAiException $exception) {
            $output->error($manager->redactor()->redact($exception->getMessage()));

            return false;
        }

        $this->attachments = [];
        $output->newLine();
        $output->writeln(' <title>NeoAI</title>');
        $this->markdown($output, $reply->getContent());
        $usage = $reply->getUsage();
        $output->writeln(sprintf(' <muted>[%s | %d in / %d out tokens | %d round(s)]</muted>', Formatter::escape($reply->getModel() ?: $provider->getModel()), $usage['prompt'], $usage['completion'], $reply->getIterations()));
        $this->lastPatches = $reply->getPatches();

        if ($this->lastPatches !== []) {
            $this->review($output, $manager, $this->lastPatches);
        }

        return true;
    }

    protected function command(OutputInterface $output, NeoAiManager $manager, Conversation $conversation, string $line): bool
    {
        $parts = preg_split('/\s+/', $line, 3) ?: [];
        $name = strtolower((string) ($parts[0] ?? ''));
        $argument = trim((string) ($parts[1] ?? ''));

        switch ($name) {
            case '/exit':
            case '/quit':
                return false;
            case '/help':
                $output->definitionList(self::HELP);

                return true;
            case '/clear':
                $conversation->clear();
                $this->attachments = [];
                $output->success('New conversation.');

                return true;
            case '/model':
                if ($argument === '') {
                    foreach ($manager->getConnectionNames() as $connection) {
                        $info = $manager->describe($connection);
                        $output->writeln(sprintf(' %s <info>%s</info> %s / %s%s', $connection === $this->assistant->getProvider()->getName() ? '*' : ' ', Formatter::escape($connection), Formatter::escape((string) $info['provider']), Formatter::escape((string) $info['model']), $info['error'] !== null ? ' <error>' . Formatter::escape((string) $info['error']) . '</error>' : ''));
                    }

                    return true;
                }

                try {
                    $this->assistant = $manager->assistant($argument);
                    $this->privacy($output, $this->assistant->getProvider());
                } catch (NeoAiException $exception) {
                    $output->error($exception->getMessage());
                }

                return true;
            case '/file':
                try {
                    $file = $manager->sandbox()->read($argument);
                    $this->assistant->getTools()->remember($file['path'], $file['hash']);
                    $this->attachments['File ' . $file['path']] = $file['content'];
                    $output->success(sprintf('%s attached (%d lines%s) to the next question.', $file['path'], $file['total'], $file['truncated'] ? ', truncated' : ''));
                } catch (NeoAiException $exception) {
                    $output->error($exception->getMessage());
                }

                return true;
            case '/scan':
                $this->scan($output, $manager, $argument !== '' ? $argument : 'all', trim((string) ($parts[2] ?? '')));

                return true;
            case '/patches':
                if ($this->lastPatches === []) {
                    $output->comment('No patch in the last answer.');
                } else {
                    $this->review($output, $manager, $this->lastPatches);
                }

                return true;
            default:
                $output->warning(sprintf('Unknown command "%s": type /help.', $name));

                return true;
        }
    }

    protected function scan(OutputInterface $output, NeoAiManager $manager, string $focus, string $path): void
    {
        try {
            $scanner = $manager->scanner($this->assistant->getProvider()->getName(), ['max_files' => 30]);
            $result = $scanner->scan($path !== '' ? [$path] : [], $focus, static function (int $batch, int $total, array $files) use ($output): void {
                $output->writeln(sprintf('   <muted>batch %d/%d (%d files)</muted>', $batch, $total, count($files)));
            });
        } catch (NeoAiException $exception) {
            $output->error($exception->getMessage());

            return;
        }

        $rows = array_map(static fn (Finding $finding): array => [strtoupper($finding->getSeverity()), $finding->getLocation(), $finding->getTitle()], array_slice($result['findings'], 0, 20));

        if ($rows === []) {
            $output->success(sprintf('No finding in %d file(s).', count($result['files'])));

            return;
        }

        $output->table(['Severity', 'Location', 'Title'], $rows);
        $output->comment('Use "bin/neo ai:scan" for the full report.');
    }
}