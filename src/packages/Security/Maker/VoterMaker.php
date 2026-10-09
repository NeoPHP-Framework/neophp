<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Maker;

use NeoPHP\Package\Security\Attribute\AsVoter;
use NeoPHP\Package\Security\Contract\AbstractVoter;
use NeoPHP\Package\Security\Contract\TokenInterface;
use NeoPHP\Package\Security\Contract\UserInterface;

class VoterMaker extends AbstractMaker
{
    public function make(string $name, ?string $subjectClass = null, bool $force = false): array
    {
        [$class, $file, $short] = $this->resolve($name, 'Voter');
        $prefix = strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', self::shortName($short)));
        $uses = [AbstractVoter::class, AsVoter::class, TokenInterface::class, UserInterface::class];

        if ($subjectClass !== null) {
            $uses[] = $subjectClass;
        }

        $supports = $subjectClass !== null
            ? "in_array(\$attribute, [self::VIEW, self::EDIT, self::DELETE], true) && \$subject instanceof " . self::shortName($subjectClass)
            : "in_array(\$attribute, [self::VIEW, self::EDIT, self::DELETE], true)";

        $code = self::header(self::namespaceOf($class), $uses)
            . "#[AsVoter]\n"
            . 'class ' . self::shortName($class) . " extends AbstractVoter\n"
            . "{\n"
            . "    public const VIEW = '" . $prefix . "_VIEW';\n\n"
            . "    public const EDIT = '" . $prefix . "_EDIT';\n\n"
            . "    public const DELETE = '" . $prefix . "_DELETE';\n\n"
            . "    protected function supports(string \$attribute, mixed \$subject): bool\n    {\n        return " . $supports . ";\n    }\n\n"
            . "    protected function voteOnAttribute(string \$attribute, mixed \$subject, TokenInterface \$token): bool\n    {\n"
            . "        \$user = \$token->getUser();\n\n"
            . "        if (!\$user instanceof UserInterface) {\n            return false;\n        }\n\n"
            . "        return match (\$attribute) {\n"
            . "            self::VIEW => true,\n"
            . "            self::EDIT, self::DELETE => in_array('ROLE_ADMIN', \$user->getRoles(), true),\n"
            . "            default => false,\n"
            . "        };\n"
            . "    }\n"
            . "}\n";

        $this->write($file, $code, $force);

        return [$class, $file, [$prefix . '_VIEW', $prefix . '_EDIT', $prefix . '_DELETE']];
    }
}