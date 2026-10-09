<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Helper\Console;

use JsonException;
use NeoPHP\Component\HttpClient\Contract\ResponseInterface;
use NeoPHP\Component\HttpClient\Exception\HttpClientException;
use NeoPHP\Component\HttpClient\HttpClientManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\Formatter;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'http:request', description: 'Sends an HTTP request with the HttpClient and displays the response')]
class HttpRequestCommand extends AbstractConsole
{
    public function __construct(protected HttpClientManagerInterface $client)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('url', InputArgument::REQUIRED, 'The URL (relative to the base_uri of the client, if any)', null, 'URL to request');
        $input->addOption('method', 'X', InputOption::VALUE_REQUIRED, 'The HTTP method', 'GET');
        $input->addOption('header', 'H', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A header "Name: value" (repeatable)', []);
        $input->addOption('json', null, InputOption::VALUE_REQUIRED, 'A JSON body (sets Content-Type: application/json)');
        $input->addOption('data', 'd', InputOption::VALUE_REQUIRED, 'A raw body, e.g. "a=1&b=2" (sent as a form when it looks like a query string)');
        $input->addOption('client', 'c', InputOption::VALUE_REQUIRED, 'A named client of config/framework/http_client.yaml');
        $input->addOption('include', 'i', InputOption::VALUE_NONE, 'Display the response headers');
        $input->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the response body to this file');
        $this->setHelp('The exit code is 1 when the response status is 4xx / 5xx or when the request fails. With -v, the request information is displayed.');
        $this->addExample('http:request https://api.github.com/zen');
        $this->addExample('http:request /users/octocat --client=github -i');
        $this->addExample('http:request https://httpbin.org/post -X POST --json=\'{"name":"neo"}\'');
        $this->addExample('http:request https://example.com/file.zip --output=var/file.zip');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if ($input->isArgumentProvided('url')) {
            return;
        }

        $url = $output->ask('URL to request', null, static function (mixed $value): string {
            $value = trim((string) $value);

            if ($value === '') {
                throw new HttpClientException('A URL is required.');
            }

            return $value;
        });

        $input->setArgument('url', (string) $url);
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $method = strtoupper((string) $input->getOption('method'));
        $url = (string) $input->getArgument('url');
        $target = $input->getOption('output');

        try {
            $client = $this->client;
            $name = $input->getOption('client');

            if (is_string($name) && $name !== '') {
                $client = $client->client($name);
            }

            $options = $this->options($input);
            $response = is_string($target) && $target !== '' ? $client->download($url, $target, $options + ['method' => $method]) : $client->request($method, $url, $options);
        } catch (HttpClientException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $status = $response->getStatusCode();
        $output->writeln(sprintf('<%s>HTTP %d</%1$s> %s <muted>(%d ms)</muted>', $status >= 400 ? 'error' : ($status >= 300 ? 'comment' : 'info'), $status, Formatter::escape($method . ' ' . (string) $response->getInfo('url')), (int) round((float) $response->getInfo('total_time') * 1000)));

        if ($output->isVerbose()) {
            $output->definitionList([
                'Original URL' => (string) $response->getInfo('original_url'),
                'Redirects' => (string) (int) $response->getInfo('redirect_count'),
                'Retries' => (string) (int) $response->getInfo('retry_count'),
                'Transport' => (string) $this->client->getTransport()->getName(),
            ]);
        }

        if ($input->getOption('include')) {
            foreach ($response->getHeaders() as $header => $values) {
                foreach ($values as $value) {
                    $output->writeln('<comment>' . Formatter::escape($header) . '</comment>: ' . Formatter::escape($value));
                }
            }
        }

        if (is_string($target) && $target !== '') {
            $output->newLine();
            $output->success(sprintf('Body written to %s (%d bytes).', $target, is_file($target) ? (int) filesize($target) : 0));
        } else {
            $body = $this->format($response);

            if ($body !== '') {
                $output->newLine();
                $output->writeln(Formatter::escape($body));
            }
        }

        return $status >= 400 ? self::FAILURE : self::SUCCESS;
    }

    protected function options(InputInterface $input): array
    {
        $options = ['headers' => []];

        foreach ((array) $input->getOption('header') as $header) {
            if (!str_contains((string) $header, ':')) {
                throw new HttpClientException('The header "{header}" must be formatted as "Name: value".', 0, null, ['header' => (string) $header]);
            }

            [$name, $value] = array_map('trim', explode(':', (string) $header, 2));
            $options['headers'][$name] = $value;
        }

        $json = $input->getOption('json');
        $data = $input->getOption('data');

        if (is_string($json) && $json !== '') {
            try {
                $options['json'] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new HttpClientException('The --json value is not valid JSON: {error}.', 0, $exception, ['error' => $exception->getMessage()]);
            }
        } elseif (is_string($data) && $data !== '') {
            if (preg_match('#^[^=&\s]+=[^&]*(&[^=&\s]+=[^&]*)*$#', $data) === 1) {
                parse_str($data, $form);
                $options['body'] = $form;
            } else {
                $options['body'] = $data;
            }
        }

        if ($options['headers'] === []) {
            unset($options['headers']);
        }

        return $options;
    }

    protected function format(ResponseInterface $response): string
    {
        $content = $response->getContent(false);

        if ($content === '' || !str_contains(strtolower((string) $response->getHeader('content-type')), 'json')) {
            return $content;
        }

        try {
            return (string) json_encode(json_decode($content, false, 512, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return $content;
        }
    }
}