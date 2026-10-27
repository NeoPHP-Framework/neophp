<?php

declare(strict_types=1);

namespace NeoPHP\Package\Mercure\Helper\Console;

use NeoPHP\Package\Mercure\Exception\MercureException;
use NeoPHP\Package\Mercure\MercureManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'mercure:token', description: 'Creates a JWT to publish to or subscribe on the Mercure hub')]
class MercureTokenCommand extends AbstractConsole
{
    public function __construct(protected MercureManagerInterface $mercure)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('publish', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Topics allowed to publish to ("*" for every topic)');
        $input->addOption('subscribe', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Topics allowed to subscribe to ("*" for every topic)');
        $input->addOption('lifetime', null, InputOption::VALUE_REQUIRED, 'Lifetime of the token, in seconds');
        $this->setHelp(implode("\n", [
            'Without option, the token allows to publish to the topics of jwt.publish (config/packages/mercure.yaml).',
            'A subscriber token expires after cookie.lifetime seconds unless --lifetime is given; a publisher token never expires unless --lifetime is given.',
            'The token is signed with MERCURE_JWT_SECRET: the hub must use the same key.',
        ]));
        $this->addExample('mercure:token');
        $this->addExample('mercure:token --subscribe="*" --lifetime=3600');
        $this->addExample('mercure:token --publish=chat/42');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $publish = array_values(array_map('strval', (array) $input->getOption('publish')));
        $subscribe = array_values(array_map('strval', (array) $input->getOption('subscribe')));
        $lifetime = $input->getOption('lifetime') !== null ? (int) $input->getOption('lifetime') : null;

        if ($publish !== [] && $subscribe !== []) {
            $output->error('Use --publish or --subscribe, not both: a token is either a publisher or a subscriber token.');

            return self::FAILURE;
        }

        try {
            $token = $subscribe === []
                ? $this->mercure->createPublisherToken($publish === [] ? null : $publish, $lifetime)
                : $this->mercure->createSubscriberToken($subscribe, [], $lifetime);
        } catch (MercureException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln($token);

        return self::SUCCESS;
    }
}