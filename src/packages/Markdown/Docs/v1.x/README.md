# Markdown

The Markdown package (`src/packages/Markdown`) parses Markdown (CommonMark + GitHub Flavored Markdown) into HTML without any dependency.
It exposes a document API (title, description, summary, sections, links, images, code blocks) and converts HTML files and templates into Markdown.

## Summary

- [Quick start](#quick-start)
- [Reading a Markdown file](#reading-a-markdown-file)
- [MarkdownDocument](#markdowndocument)
- [Converting HTML into Markdown](#converting-html-into-markdown)
- [View filter](#view-filter)
- [Command](#command)
- [MarkdownParserInterface](#markdownparserinterface)
- [Supported syntax](#supported-syntax)
- [Changelog](#changelog)

## Quick start

Inject `NeoPHP\Package\Markdown\Contract\MarkdownParserInterface` (autowired, implemented by `NeoPHP\Package\Markdown\MarkdownManager`):

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Markdown\Contract\MarkdownParserInterface;

class DocController extends AbstractController
{
    public function show(MarkdownParserInterface $markdown): Response
    {
        $document = $markdown->get('docs/guide.md');

        return $this->render('doc/show.html.twig', [
            'title' => $document->getTitle(),
            'summary' => $document->getSummary(),
            'content' => $document->toHtml(),
        ]);
    }
}
```

```php
$html = $markdown->toHtml('**Hello** world');
$markdown->parse('templates/page/about.html.twig', ['user' => $user])->to('docs/about.md');
```

## Reading a Markdown file

| Method | Description |
|---|---|
| `get(string $file): MarkdownDocument` | reads a `.md` file (absolute path or relative to the project root) |
| `fromString(string $markdown): MarkdownDocument` | parses a Markdown string |
| `toHtml(string $markdown): string` | converts a Markdown string into HTML (raw HTML kept: trusted content) |
| `toSafeHtml(string $markdown): string` | converts untrusted Markdown into HTML (raw HTML escaped) |

A missing file throws `NeoPHP\Package\Markdown\Exception\MarkdownException`.

## MarkdownDocument

`NeoPHP\Package\Markdown\Document\MarkdownDocument`:

| Method | Description |
|---|---|
| `getSource(): string` | the Markdown source |
| `toHtml(): string` | the HTML (headings carry an `id`) |
| `getTitle(): ?string` | text of the first level 1 heading |
| `getDescription(): ?string` | first paragraph after the title, as plain text |
| `getHeadings(): array` | `Heading` objects (`getLevel()`, `getText()`, `getId()`, `getAnchor()`, `toArray()`) |
| `getSummary(): array` | tree of headings: `['level', 'text', 'id', 'children' => [...]]` |
| `getSections(): array` | level 2 sections keyed by title |
| `getSection(string $title): ?Section` | a section by title or id (case insensitive) |
| `getLinks(): array` | `['text', 'url', 'title']` |
| `getImages(): array` | `['alt', 'url', 'title']` |
| `getCodeBlocks(): array` | `['language', 'code']` |

A `Section` gives `getTitle()`, `getId()`, `getLevel()`, `getMarkdown()` (content without the heading) and `toHtml()`:

```php
$changelog = $markdown->get('README.md')->getSection('Changelog')?->toHtml();
```

Ids follow the GitHub rules: lowercase, punctuation removed (except `-` and `_`), spaces replaced by `-`, duplicates suffixed with `-1`, `-2`.

## Converting HTML into Markdown

`parse(string $file, array $parameters = []): MarkdownConversion` accepts:

- a template of `templates/` (`.html.twig`, `.twig`, `.php`): rendered first by the View component with `$parameters`
- a `.html` / `.htm` file: converted directly
- a `.txt` file: kept (line endings normalized, trimmed)

`convert(string $html): string` converts an HTML string. The `<body>` is used; `script`, `style` and `head` are ignored.

`MarkdownConversion`:

| Method | Description |
|---|---|
| `getMarkdown(): string` / `__toString()` | the Markdown |
| `getSource(): string` | the absolute path of the converted file |
| `getDocument(): MarkdownDocument` | the document of the Markdown |
| `to(string $target): string` | writes the file (directories created, relative to the project root) and returns its absolute path |

## View filter

The `markdown` filter converts Markdown into HTML (safe HTML, not escaped again). By default the raw HTML written in the Markdown is escaped, so the filter can be used on content written by visitors (comments, profiles):

```twig
{{ comment.body|markdown }}        {# <script> is displayed as text #}
{{ page.body|markdown(true) }}     {# trusted content: raw HTML kept #}
```

```php
<?= $this->filter('markdown', $comment->getBody()) ?>
<?= $this->filter('markdown', $page->getBody(), true) ?>
```

A `markdown` filter of the application (`src/Helper/View`) replaces the one of the framework.

## Command

```bash
php bin/neo markdown:convert templates/page/about.html.twig docs/about.md
```

`markdown:convert <source> <target>` asks both arguments when they are missing, converts the source with `parse()` and writes the target with `to()`.

## MarkdownParserInterface

| Method | Description |
|---|---|
| `get(string $file): MarkdownDocument` | reads a Markdown file |
| `fromString(string $markdown): MarkdownDocument` | parses a string |
| `toHtml(string $markdown): string` | Markdown to HTML |
| `toSafeHtml(string $markdown): string` | Markdown to HTML, raw HTML escaped |
| `parse(string $file, array $parameters = []): MarkdownConversion` | HTML file, template or text file to Markdown |
| `convert(string $html): string` | HTML to Markdown |

Errors throw `NeoPHP\Package\Markdown\Exception\MarkdownException` (extends `FrameworkException`).

## Supported syntax

- ATX (`#`) and setext (`===`, `---`) headings, with an `id`
- paragraphs, hard line breaks (two spaces or `\`)
- emphasis and strong emphasis (`*`, `_`), strikethrough (`~~text~~`)
- inline code, fenced code blocks (```` ``` ```` and `~~~`, `class="language-xxx"`), indented code blocks
- links `[text](url "title")`, reference links `[text][ref]` with `[ref]: url "title"`, autolinks `<https://...>`, bare URLs (`https://...`, `www.`)
- images `![alt](url "title")`
- blockquotes (nested, with any block inside)
- ordered and unordered lists (nested, tight or loose, start number), task lists `- [ ]` / `- [x]`
- tables with alignment, thematic breaks
- raw HTML blocks and inline HTML, backslash escapes, HTML entities

The text is escaped; `javascript:`, `vbscript:` and `data:` URLs (except `data:image`) are replaced by `#`. `toHtml()`, `get()` and `fromString()` keep raw HTML as written (trusted content); use `toSafeHtml()` or the `markdown` filter without argument for untrusted Markdown.

## Changelog

- v1.30.0 — the `markdown` filter escapes raw HTML by default (`markdown(true)` keeps it), `toSafeHtml()` in `MarkdownParserInterface`, `HtmlRenderer::setEscapeHtml()`.
- v1.19.0 — Markdown package: CommonMark + GFM parser, `MarkdownDocument` (title, description, summary, sections, links, images, code blocks), HTML and templates to Markdown, `markdown` view filter, `markdown:convert` command.