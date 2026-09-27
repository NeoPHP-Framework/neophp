<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Patch;

use NeoPHP\Package\NeoAI\Exception\PatchException;
use NeoPHP\Package\NeoAI\Model\Patch;
use NeoPHP\Package\NeoAI\Security\Sandbox;

class PatchApplier
{
    public function __construct(protected Sandbox $sandbox, protected string $backupDirectory, protected PatchParser $parser = new PatchParser())
    {
    }

    public function check(Patch $patch): array
    {
        $plan = [];

        foreach ($this->parser->parse($patch->getDiff()) as $file) {
            $path = $this->sandbox->normalize((string) ($file['new'] ?? $file['old']));
            $absolute = $this->sandbox->resolve($path, $file['old'] !== null);
            $expected = $patch->getHash($path);

            if ($file['old'] === null) {
                if (file_exists($absolute)) {
                    throw new PatchException('"{path}" already exists: the patch expected to create it.', 0, null, ['path' => $path]);
                }

                $plan[] = ['path' => $path, 'absolute' => $absolute, 'content' => $this->parser->apply('', $file), 'original' => null];
                continue;
            }

            if (!is_file($absolute)) {
                throw new PatchException('"{path}" does not exist anymore.', 0, null, ['path' => $path]);
            }

            $original = (string) file_get_contents($absolute);

            if ($expected === null || $expected === PatchParser::NEW_FILE || !hash_equals($expected, sha1($original))) {
                throw new PatchException('"{path}" changed since the assistant read it: the patch was not applied. Ask again to get a fresh patch.', 0, null, ['path' => $path]);
            }

            $plan[] = ['path' => $path, 'absolute' => $absolute, 'content' => $this->parser->apply($original, $file), 'original' => $original, 'delete' => $file['new'] === null];
        }

        return $plan;
    }

    public function apply(Patch $patch): array
    {
        $plan = $this->check($patch);
        $backup = rtrim($this->backupDirectory, '/\\') . DIRECTORY_SEPARATOR . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $result = ['backup' => $backup, 'files' => []];

        foreach ($plan as $entry) {
            if ($entry['original'] !== null) {
                $this->write($backup . DIRECTORY_SEPARATOR . $entry['path'], $entry['original']);
            }
        }

        foreach ($plan as $entry) {
            if (!empty($entry['delete'])) {
                if (!@unlink($entry['absolute'])) {
                    throw new PatchException('"{path}" cannot be deleted.', 0, null, ['path' => $entry['path']]);
                }

                $result['files'][$entry['path']] = 'deleted';
                continue;
            }

            $this->write($entry['absolute'], $entry['content']);
            $result['files'][$entry['path']] = $entry['original'] === null ? 'created' : 'modified';
        }

        return $result;
    }

    protected function write(string $file, string $content): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new PatchException('The directory "{directory}" cannot be created.', 0, null, ['directory' => $directory]);
        }

        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            throw new PatchException('"{file}" cannot be written.', 0, null, ['file' => $file]);
        }
    }
}