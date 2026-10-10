<?php

declare(strict_types=1);

namespace NeoPHP\Package\Yaml;

use NeoPHP\Package\Yaml\Exception\ParseException;

interface YamlManagerInterface
{
    /**
     * Parses YAML.
     *
     * @param string $input The YAML
     * @return mixed The parsed value: an array for a mapping or a sequence, a scalar or null
     * @throws ParseException When the YAML is not valid
     */
    public function parse(string $input): mixed;

    /**
     * Parses a YAML file.
     *
     * @param string $file Path of the file
     * @return mixed The parsed value: an array for a mapping or a sequence, a scalar or null
     * @throws ParseException When the file does not exist, is not readable or is not valid YAML
     */
    public function parseFile(string $file): mixed;
}