<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Agent;

use NeoPHP\Package\NeoAI\Tool\ToolRunner;

class PromptBuilder
{
    public const LANGUAGES = ['en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch'];

    public function __construct(protected string $language = 'en', protected string $extension = '', protected string $project = 'project')
    {
    }

    public function getLanguageName(): string
    {
        return self::LANGUAGES[strtolower($this->language)] ?? $this->language;
    }

    public function assistant(ToolRunner $runner, int $maxIterations): string
    {
        $prompt = <<<PROMPT
You are NeoAI, a senior PHP developer assistant embedded in a NeoPHP framework application ("{$this->project}", PHP >= 8.2, NeoPHP is an ultra modular framework: components, packages, processes; configuration in config/**/*.yaml, application code in src/, templates in templates/).
You help the developer understand, debug and improve THIS project. Answer in {$this->getLanguageName()}, concisely, with Markdown.

You have READ-ONLY access to the project through tools. To call a tool, reply with one or more fenced blocks exactly in this format and nothing else in the message:
```neo-tool
{"tool": "read_file", "path": "src/Controller/HomeController.php"}
```
The tool results are sent back to you in the next message. You may use at most {$maxIterations} tool rounds, so batch several tool blocks in one message when possible.
Available tools:
{$runner->describe()}

Rules:
- Never invent file contents: read files before explaining or patching them.
- Paths are relative to the project root. vendor/, var/, .env files and keys are not readable.
- Secrets are replaced by [REDACTED]: never ask for them and never try to guess them.
- You can NOT modify files. To suggest a change, call propose_patch with a unified diff (--- a/path, +++ b/path, @@ hunks with 3 context lines copied exactly from the file). The developer reviews and applies it.
- When you have enough information, answer without any neo-tool block.
PROMPT;

        return trim($prompt . ($this->extension !== '' ? "\n\nProject specific instructions:\n" . $this->extension : ''));
    }

    public function audit(string $focus): string
    {
        $focusText = match ($focus) {
            'security' => 'security vulnerabilities (injections, XSS, CSRF, auth/access control, secrets, unsafe file or shell usage, deserialization, open redirects)',
            'performance' => 'performance problems (N+1 queries, useless loops, heavy work in hot paths, missing caches, memory usage)',
            'bugs' => 'bugs and logic errors (null handling, wrong conditions, type errors, unhandled exceptions, edge cases)',
            'conventions' => 'code quality and conventions (PSR-12, strict types, naming, dead code, duplication, framework best practices)',
            default => 'security vulnerabilities, bugs, performance problems and convention issues',
        };

        $prompt = <<<PROMPT
You are NeoAI, an expert PHP code auditor reviewing files of a NeoPHP framework application ("{$this->project}").
Focus on: {$focusText}.
Only report real, actionable issues visible in the provided code; do not report style nitpicks unless the focus is conventions. Lines are prefixed with their number.
Answer ONLY with a JSON array (no prose) inside a ```json fenced block. Each item:
{"severity": "critical|high|medium|low|info", "category": "security|performance|bugs|conventions", "file": "relative/path", "line": 42, "title": "short title", "explanation": "why it is a problem", "fix": "how to fix it", "diff": "optional unified diff"}
Write the texts in {$this->getLanguageName()}. Return [] when nothing is worth reporting.
PROMPT;

        return trim($prompt . ($this->extension !== '' ? "\n\nProject specific instructions:\n" . $this->extension : ''));
    }
}