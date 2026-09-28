# NeoAI

NeoAI is an AI development assistant for NeoPHP applications. It reads the project (code, routes, configuration, WebProfiler profiles), answers questions, audits the code and proposes patches as unified diffs, from the console (`ai:start`, `ai:scan`) and from the web debug toolbar and the profiler.
It is a **development tool**: it is enabled only when `kernel.debug` is true, it can never write files from HTTP, and every patch is reviewed and confirmed by the developer in the console.
It works with OpenAI and OpenAI-compatible APIs (Mistral, Groq, OpenRouter, LM Studio, vLLM), Anthropic, Google Gemini and a local Ollama, through the HttpClient component. No external library, no CDN.

## Summary

- [Installation](#installation)
- [Configuration](#configuration)
- [Environment variables per provider](#environment-variables-per-provider)
- [Commands](#commands)
- [Toolbar and profiler](#toolbar-and-profiler)
- [Tools and context](#tools-and-context)
- [Patches](#patches)
- [Privacy and security](#privacy-and-security)
- [Using the assistant from code](#using-the-assistant-from-code)
- [Extending](#extending)
- [Exceptions](#exceptions)
- [Limitations](#limitations)
- [Changelog](#changelog)

## Installation

1. Register the provider in the kernel (after `MarkdownProvider`), or in the `providers()` method of the application kernel:

````php
use NeoPHP\Package\NeoAI\Provider\NeoAiProvider;

protected function providers(): iterable
{
    return [NeoAiProvider::class];
}
````

2. Create `config/packages/neo_ai.yaml` (installed by `bin/neo install` from `config/packages/neo_ai.yaml.stub`).
3. Add the variables to `.env` (all of them must exist, even empty, because `%env(...)%` is resolved when the configuration is loaded) and put the real keys in `.env.local`:

````dotenv
NEO_AI_PROVIDER=ollama
NEO_AI_MODEL=qwen2.5-coder:7b
NEO_AI_API_KEY=
NEO_AI_BASE_URL=
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
GEMINI_API_KEY=
MISTRAL_API_KEY=
OLLAMA_URL=http://localhost:11434
````

4. Check the setup:

````bash
php bin/neo ai:test
````

The toolbar item and the profiler panel need the WebProfiler package.

## Configuration

`config/packages/neo_ai.yaml` (key `packages.neo_ai`):

````yaml
enabled: ~
allow_remote: true
default_connection: default
language: en
system_prompt: ''

connections:
  default:
    provider: '%env(NEO_AI_PROVIDER)%'
    model: '%env(NEO_AI_MODEL)%'
    api_key: '%env(NEO_AI_API_KEY)%'
    base_url: '%env(NEO_AI_BASE_URL)%'
    temperature: 0.2
    max_tokens: 4096
    timeout: 120
    retries: 2
  anthropic:
      provider: anthropic
      model: claude-sonnet-5
      api_key: '%env(ANTHROPIC_API_KEY)%'
  local:
    provider: ollama
    model: qwen2.5-coder:7b
    base_url: '%env(OLLAMA_URL)%'

providers: {}

context:
  max_files: 40
  max_file_bytes: 60000
  max_context_chars: 120000
  max_tool_iterations: 8
  max_tool_output: 12000

scan:
  paths: [src, templates, config]
  extensions: [php, twig, yaml, yml, js, html, phtml]
  max_files: 200
  batch_chars: 60000

excluded_paths: []
redact_patterns: []

web:
  enabled: true
  path: /_neo_ai
  max_request_bytes: 200000
  max_message_chars: 8000
  allowed_ips: ~
````

| Key | Default | Description |
|---|---|---|
| `enabled` | `~` | `~` follows `kernel.debug`; `true` forces the console commands in any environment (the web endpoints always require `kernel.debug`) |
| `allow_remote` | `true` | `false` only allows local servers (Ollama, `localhost` / `127.0.0.1` / `*.local` base URLs) |
| `default_connection` | `default` | connection used when none is given |
| `language` | `en` | language of the answers (`en`, `fr`, `de`, `es`...) |
| `system_prompt` | `''` | project specific instructions appended to the system prompt |
| `connections` | `{}` | named connections, see below |
| `providers` | `{}` | custom provider types: `type: Fully\Qualified\Class` |
| `context.max_file_bytes` | `60000` | maximum bytes returned when reading one file |
| `context.max_context_chars` | `120000` | maximum characters sent in one request (oldest messages dropped first) |
| `context.max_tool_iterations` | `8` | maximum tool rounds per question |
| `context.max_tool_output` | `12000` | maximum bytes of one tool result |
| `scan.*` | see above | default paths, extensions, file limit and batch size of `ai:scan` |
| `excluded_paths` | `[]` | extra paths never readable, added to the built-in list |
| `redact_patterns` | `[]` | extra regular expressions replaced by `[REDACTED]` |
| `web.enabled` | `true` | toolbar item, panel and `/_neo_ai` endpoint |
| `web.path` | `/_neo_ai` | prefix of the endpoint |
| `web.allowed_ips` | `~` (local and private networks) | IPs / CIDR ranges allowed on `/_neo_ai/chat` (list or comma separated string); `~` = `Request::LOCAL_NETWORKS`, `[]` = any client |
| `storage` | `var/ai` | conversations, reports, backups and the web secret |

Connection options:

| Option | Default | Description |
|---|---|---|
| `provider` | required | `openai`, `openai_compatible`, `mistral`, `groq`, `openrouter`, `lmstudio`, `vllm`, `anthropic`, `gemini`, `ollama` or a custom type |
| `model` | required | model name |
| `api_key` | `''` | API key (required for OpenAI, Mistral, Groq, OpenRouter, Anthropic, Gemini) |
| `base_url` | provider default | API base URL |
| `temperature` | `0.2` | sampling temperature |
| `max_tokens` | `2048` | maximum tokens of the answer |
| `timeout` | `60` | request timeout in seconds |
| `retries` | `2` | retries on network errors, HTTP 429 and 5xx (exponential backoff) |
| `retry_delay` | `1000` | first retry delay in milliseconds |
| `headers` | `{}` | extra HTTP headers (e.g. `HTTP-Referer` for OpenRouter) |
| `context_window` | model default | Ollama only: context size in tokens (`num_ctx`); Ollama truncates longer prompts to 2048 / 4096 tokens, `16384` is recommended for the assistant |
| `keep_alive` | Ollama default (`5m`) | Ollama only: time the model stays loaded after a request (`30m`, `-1` = forever): avoids reloading it on CPU |

## Environment variables per provider

Only one connection is needed. Put the keys in `.env.local`, never in `.env`.

OpenAI:

````dotenv
NEO_AI_PROVIDER=openai
NEO_AI_MODEL=gpt-5.4-mini
OPENAI_API_KEY=sk-...
NEO_AI_API_KEY=${OPENAI_API_KEY}
````

Anthropic (Messages API):

````dotenv
NEO_AI_PROVIDER=anthropic
NEO_AI_MODEL=claude-sonnet-5
ANTHROPIC_API_KEY=sk-ant-...
NEO_AI_API_KEY=${ANTHROPIC_API_KEY}
````

Google Gemini (`generateContent`):

````dotenv
NEO_AI_PROVIDER=gemini
NEO_AI_MODEL=gemini-3.8-flash
GEMINI_API_KEY=AIza...
NEO_AI_API_KEY=${GEMINI_API_KEY}
````

The free tier of the Gemini API has a small daily quota per model (requests per day): an HTTP 429 means the quota is reached. The "Live" models use another API (WebSocket) and cannot be used. A model that is retired returns an HTTP 404: pick a current one in Google AI Studio.

Mistral, Groq, OpenRouter (OpenAI-compatible, default base URLs built in):

````dotenv
NEO_AI_PROVIDER=mistral
NEO_AI_MODEL=mistral-small-latest
MISTRAL_API_KEY=...
NEO_AI_API_KEY=${MISTRAL_API_KEY}
````

Ollama (local, native `/api/chat`, no key, nothing leaves the machine):

````dotenv
NEO_AI_PROVIDER=ollama
NEO_AI_MODEL=qwen2.5-coder:7b
NEO_AI_BASE_URL=http://localhost:11434
````

````bash
ollama pull qwen2.5-coder:7b
````

LM Studio (local OpenAI-compatible server):

````dotenv
NEO_AI_PROVIDER=lmstudio
NEO_AI_MODEL=qwen2.5-coder-7b-instruct
NEO_AI_BASE_URL=http://localhost:1234/v1
````

vLLM or any OpenAI-compatible server:

````dotenv
NEO_AI_PROVIDER=openai_compatible
NEO_AI_MODEL=Qwen/Qwen2.5-Coder-7B-Instruct
NEO_AI_BASE_URL=http://gpu-box.local:8000/v1
````

Default base URLs: OpenAI `https://api.openai.com/v1`, Mistral `https://api.mistral.ai/v1`, Groq `https://api.groq.com/openai/v1`, OpenRouter `https://openrouter.ai/api/v1`, LM Studio `http://localhost:1234/v1`, vLLM `http://localhost:8000/v1`, Anthropic `https://api.anthropic.com/v1`, Gemini `https://generativelanguage.googleapis.com/v1beta`, Ollama `http://localhost:11434`.

## Commands

| Command | Description |
|---|---|
| `ai:start` (`ai`) | interactive chat in the console |
| `ai:scan` | project audit, Markdown report |
| `ai:test` | shows the configuration of every connection and sends a ping |

`ai:start`:

````bash
php bin/neo ai:start
php bin/neo ai:start --connection=local
php bin/neo ai:start --ask="Where is the contact form validated?"
````

The history is kept for the session. The provider, the model and whether the server is local or remote are shown before the first question. After each answer: the token usage and, for each proposed patch, a colored diff and a confirmation.

| Chat command | Description |
|---|---|
| `/help` | list of the commands |
| `/exit`, `/quit`, Ctrl+D | quit |
| `/clear` | new conversation |
| `/model [connection]` | list the connections or switch |
| `/file <path>` | attach a project file to the next question |
| `/scan [focus] [path]` | quick audit (30 files) |
| `/patches` | review again the patches of the last answer |

`ai:scan`:

````bash
php bin/neo ai:scan
php bin/neo ai:scan --focus=security --path=src/Controller --path=templates
php bin/neo ai:scan --connection=local --max-files=50 --output=var/audit.md --fail-on=high
php bin/neo ai:scan --dry-run
````

| Option | Description |
|---|---|
| `--path` | paths to scan (repeatable), default `scan.paths` |
| `--focus` | `all`, `security`, `performance`, `bugs`, `conventions` |
| `--connection` | connection to use |
| `--max-files` | maximum number of files |
| `--output` | report file, default `var/ai/reports/scan-YYYYmmdd-His.md` |
| `--fail-on` | exit code 1 when a finding is at least `critical`, `high`, `medium`, `low` or `info` (CI) |
| `--dry-run` | list the files and batches, nothing is sent |

The files are read through the sandbox, redacted, numbered and grouped in batches of `scan.batch_chars` characters. Each batch is analysed separately; the model returns JSON findings (severity, category, file, line, title, explanation, fix, optional diff) aggregated and sorted by severity, printed in the console and written in the Markdown report.

## Toolbar and profiler

With the WebProfiler package, `Helper/Profiler/NeoAiProfiler` adds:

- an **AI** toolbar item (model, provider, local or remote server). A click opens a floating chat window on the page. The option *Attach page context* sends the URL, the title, the headings, the forms (field names only), the visible text (6000 characters), the JavaScript errors captured since the toolbar loaded, a DOM summary and the profile of the current request (`X-Debug-Token`).
- an **AI assistant** panel in the profiler with the same chat, bound to the profile being viewed and with suggestions (*explain this request*, *explain the exception*, *why is it slow / N+1*).

Answers are rendered as safe Markdown (HTML escaped first, then code blocks, inline code, bold, lists and `http(s)` or relative links). Patches are displayed as colored diffs with a **Copy** button: they are never applied from the browser.

The browser calls `POST /_neo_ai/chat` (registered at request time only when `enabled`, `web.enabled` and `kernel.debug` are true):

````json
{"message": "Why is this page slow?", "conversation_id": "", "profile_token": "a1b2c3", "connection": "default", "page": {"url": "", "title": "", "headings": [], "forms": [], "text": "", "errors": [], "dom": {}}}
````

````json
{"reply": "markdown", "patches": [{"description": "", "files": [], "diff": ""}], "usage": {"prompt": 0, "completion": 0, "total": 0}, "tools": [], "iterations": 1, "model": "", "conversation_id": "", "connection": "", "provider": "", "remote": false}
````

Conversations are stored in `var/ai/conversations/{id}.json` (60 messages kept).

## Tools and context

The assistant does not depend on provider-native tool APIs: it asks for actions with fenced JSON blocks that NeoAI executes and answers in the next message, so every provider and local model works the same way.

````text
```neo-tool
{"tool": "read_file", "path": "src/Controller/BlogController.php", "from": 1, "to": 80}
```
````

| Tool | Arguments | Result |
|---|---|---|
| `list_files` | `dir`, `pattern` | files of a directory (recursive, 300 max) |
| `read_file` | `path`, `from`, `to` | numbered lines (`context.max_file_bytes`), the file hash is remembered for the patches |
| `search` | `regex`, `glob`, `dir` | matching lines `file:line: text` (80 max) |
| `project_info` | | framework and PHP versions, environment, installed features, routes, configuration summary without secrets |
| `profile` | `token`, `panel` | a WebProfiler profile (summary and collected data), or one collector |
| `propose_patch` | `diff`, `description` | records a unified diff for the developer |

At most `context.max_tool_iterations` rounds per question; the last round asks the model to answer without tools. Every result is redacted and truncated to `context.max_tool_output` bytes. Diffs written in a `diff` fenced block of the final answer are also collected as patches when they apply.

The sandbox restricts every path to the project root: absolute paths outside the root, `..` escapes, symbolic links, null bytes and the excluded paths are refused. Built-in exclusions: `vendor`, `var`, `node_modules`, `.git`, `.idea`, `.env`, `.env.*`, `*.key`, `*.pem`, `*.p12`, `*.pfx`, `*.crt`, `id_rsa*`, `*.sqlite`, `*.db`, `.htpasswd`, `auth.json` (plus `excluded_paths`).

## Patches

- The model proposes patches; NeoAI checks that the paths are inside the sandbox and that every hunk applies to the current file (context lines, with line offset tolerance), and stores the SHA-1 of each file as it was read.
- In `ai:start`, each patch is shown as a colored diff and applied only after confirmation (default: no). Before writing, the file hash is compared to the hash read by the assistant: a file changed in the meantime is never overwritten. The original files are copied to `var/ai/backups/YYYYmmdd-His-xxxxxx/` first. New files (`--- /dev/null`) are supported.
- In non interactive mode (`-n`, `--ask` in CI) patches are only printed.
- In the browser, patches are only displayed with a copy button. There is no HTTP route that writes a file.

## Privacy and security

- Enabled only with `kernel.debug` by default; the web endpoint and the toolbar item never exist when `kernel.debug` is false, even with `enabled: true`.
- Everything sent to a provider is redacted first: the values of the environment variables whose name contains `PASSWORD`, `SECRET`, `KEY`, `TOKEN`, `AUTH`, `DSN`, `DATABASE_URL` (and the password of every `scheme://user:password@` URL), the configured API keys, well-known key formats (OpenAI, Anthropic, Google, Groq, GitHub, Slack, AWS, Stripe, JWT, private key blocks), `KEY=value` lines, YAML `password: value` entries, quoted `'secret' => '...'` literals and `redact_patterns`. `%env(...)%` references are kept.
- The provider, the model and whether the server is local or remote are shown in the console, in the toolbar popover, in the chat window and in the panel.
- `allow_remote: false` refuses every connection whose server is not local.
- `/_neo_ai/chat` requires `POST`, a client IP in `web.allowed_ips` (local and private networks by default), a JSON body smaller than `web.max_request_bytes`, the same origin (`Sec-Fetch-Site`, `Origin` or `Referer`) and the `X-Neo-AI-Token` header: an HMAC of a random secret stored in `var/ai/secret`, rotated every 12 hours and embedded in the toolbar and the panel.
- Error messages returned to the browser are redacted.
- `var/ai` contains conversations and backups: keep `var/` out of version control.

## Using the assistant from code

````php
use NeoPHP\Package\NeoAI\Model\Conversation;
use NeoPHP\Package\NeoAI\Model\Message;
use NeoPHP\Package\NeoAI\NeoAiManager;

$manager = $container->get(NeoAiManager::class);

$response = $manager->connection('local')->chat([Message::user('Say hello')]);
echo $response->getContent(), ' ', $response->getTotalTokens();

$conversation = Conversation::create();
$reply = $manager->assistant()->ask($conversation, 'Which controller renders /blog?');
echo $reply->getContent();

foreach ($reply->getPatches() as $patch) {
    echo $patch->getDiff();
}

$result = $manager->scanner(null, ['max_files' => 20])->scan(['src/Controller'], 'security');
````

## Extending

A custom provider implements `NeoPHP\Package\NeoAI\Contract\ProviderInterface` (or extends `Contract\AbstractProvider`, which gives the retries, the error mapping and the key checks). It is built with `new $class(array $connection, ?HttpClientInterface $http)`:

````php
namespace App\Ai;

use NeoPHP\Package\NeoAI\Contract\AbstractProvider;
use NeoPHP\Package\NeoAI\Model\ChatResponse;
use NeoPHP\Package\NeoAI\Model\Conversation;
use NeoPHP\Package\NeoAI\Model\Message;

class CompanyGatewayProvider extends AbstractProvider
{
    public const TYPE = 'company';

    public const DEFAULT_BASE_URL = 'https://ai.company.internal';

    public function chat(Conversation|array $messages, array $options = []): ChatResponse
    {
        $data = $this->post($this->getBaseUrl() . '/v1/complete', [
            'model' => $this->requireModel($options),
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $this->messages($messages)),
        ], ['Authorization' => 'Bearer ' . $this->apiKey()]);

        return new ChatResponse((string) ($data['text'] ?? ''), $this->getModel(), 'stop', (int) ($data['input_tokens'] ?? 0), (int) ($data['output_tokens'] ?? 0), $data);
    }
}
````

````yaml
providers:
  company: App\Ai\CompanyGatewayProvider

connections:
  gateway:
    provider: company
    model: coder-large
    api_key: '%env(COMPANY_AI_KEY)%'
````

`isLocal()` decides if the connection is allowed with `allow_remote: false` (default: the host of the base URL). `NeoPHP\Package\NeoAI\Llm\FakeProvider` returns scripted answers (`FakeProvider::queue()`, `responses` option) and records the requests: register it in `providers` to test code that uses the assistant without network.

## Exceptions

All exceptions extend `NeoPHP\Package\NeoAI\Exception\NeoAiException` (a `FrameworkException`):

| Exception | When |
|---|---|
| `ConfigurationException` | unknown connection or provider, missing key or model, HttpClient missing |
| `ProviderException` | invalid answer, HTTP error, unknown model (404) |
| `AuthenticationException` | HTTP 401 / 403 (bad key) |
| `RateLimitException` | HTTP 429 after the retries |
| `NetworkException` | DNS, connection or timeout error after the retries |
| `SecurityException` | `allow_remote: false`, invalid web token or origin |
| `SandboxException` | path outside the root, excluded, missing or binary |
| `PatchException` | invalid diff, context mismatch, file changed since read, write error |
| `ToolException` | tool error |

## Limitations

- No token streaming: the HttpClient component buffers responses, so `stream()` calls `chat()` and sends the whole answer at once (the console shows a waiting line). `supportsStreaming()` returns `false`.
- The tool protocol relies on the model following the fenced JSON format: small local models may ignore it or produce invalid diffs (they are rejected, never applied).
- Token counts come from the provider (estimated by `FakeProvider`); no cost is computed.
- Redaction is pattern based: a secret stored in an unusual format in a readable file may be sent. Keep secrets in `.env.local` or excluded files, and use a local provider for sensitive projects.
- The page context is captured by JavaScript in the browser: errors thrown before the toolbar loaded are not captured.
- Conversations and backups in `var/ai` are not purged automatically.

## Changelog

- v1.30.0 — `ai:test` sends 512 tokens (reasoning models answered empty with 16) and fails on an empty answer; error messages show the configured provider (`mistral`, `groq`...) instead of `openai`; Ollama `context_window` (`num_ctx`) and `keep_alive` options; default models updated (`gpt-5.4-mini`, `claude-sonnet-5`, `gemini-3.8-flash`).
- v1.29.1 (bugfix) — `web.allowed_ips` (local and private networks by default) checked by `RequestGuard` on the chat endpoint.
- v1.26.0 — NeoAI package: OpenAI, OpenAI-compatible (Mistral, Groq, OpenRouter, LM Studio, vLLM), Anthropic, Gemini and Ollama providers through HttpClient with named connections, retries and typed errors; portable `neo-tool` loop (`list_files`, `read_file`, `search`, `project_info`, `profile`, `propose_patch`) in a sandbox with excluded paths and secret redaction; `ai:start` chat with patch review (hash check and backups), `ai:scan` batched audit with Markdown report and `--fail-on`, `ai:test`; AI toolbar item with floating chat and page context, AI profiler panel, `POST /_neo_ai/chat` protected by origin and token checks; `FakeProvider` for tests.