<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Maker;

use NeoPHP\Package\Orm\Contract\AbstractRepository;
use NeoPHP\Package\Security\Contract\PasswordAuthenticatedUserInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\SecurityException;

class UserMaker extends AbstractMaker
{
    public function __construct(string $path, string $namespace, protected string $repositoryPath, protected string $repositoryNamespace)
    {
        parent::__construct($path, $namespace);
    }

    public function make(string $name = 'User', string $property = 'email', bool $force = false): array
    {
        if (preg_match('/^[a-z][A-Za-z0-9_]*$/', $property) !== 1 || in_array($property, ['id', 'roles', 'password'], true)) {
            throw new SecurityException('The identifier property "{property}" is not valid: use a camelCase name other than id, roles and password.', 0, null, ['property' => $property]);
        }

        [$class, $file, $short] = $this->resolve($name);
        $repositoryClass = trim($this->repositoryNamespace, '\\') . '\\' . $short . 'Repository';
        $repositoryFile = rtrim($this->repositoryPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $short . 'Repository') . '.php';

        if (!$force) {
            foreach ([$file, $repositoryFile] as $existing) {
                if (is_file($existing)) {
                    throw new SecurityException('The file "{file}" already exists: use --force to overwrite it.', 0, null, ['file' => $existing]);
                }
            }
        }

        $studly = ucfirst($property);
        $entity = self::header(self::namespaceOf($class), [$repositoryClass, 'NeoPHP\\Package\\Orm\\Mapping as ORM', PasswordAuthenticatedUserInterface::class, UserInterface::class])
            . '#[ORM\\Entity(repository: ' . self::shortName($repositoryClass) . "::class)]\n"
            . 'class ' . self::shortName($class) . " implements UserInterface, PasswordAuthenticatedUserInterface\n"
            . "{\n"
            . "    #[ORM\\Id]\n    #[ORM\\GeneratedValue]\n    #[ORM\\Column]\n    private ?int \$id = null;\n\n"
            . "    #[ORM\\Column(length: 180, unique: true)]\n    private ?string \$" . $property . " = null;\n\n"
            . "    #[ORM\\Column(type: 'json')]\n    private array \$roles = [];\n\n"
            . "    #[ORM\\Column]\n    private ?string \$password = null;\n\n"
            . "    public function getId(): ?int\n    {\n        return \$this->id;\n    }\n\n"
            . '    public function get' . $studly . "(): ?string\n    {\n        return \$this->" . $property . ";\n    }\n\n"
            . '    public function set' . $studly . '(string $' . $property . "): static\n    {\n        \$this->" . $property . ' = $' . $property . ";\n\n        return \$this;\n    }\n\n"
            . "    public function getUserIdentifier(): string\n    {\n        return (string) \$this->" . $property . ";\n    }\n\n"
            . "    public function getRoles(): array\n    {\n        return array_values(array_unique([...\$this->roles, 'ROLE_USER']));\n    }\n\n"
            . "    public function setRoles(array \$roles): static\n    {\n        \$this->roles = \$roles;\n\n        return \$this;\n    }\n\n"
            . "    public function getPassword(): ?string\n    {\n        return \$this->password;\n    }\n\n"
            . "    public function setPassword(string \$password): static\n    {\n        \$this->password = \$password;\n\n        return \$this;\n    }\n"
            . "}\n";

        $repository = self::header(self::namespaceOf($repositoryClass), [$class, AbstractRepository::class])
            . 'class ' . self::shortName($repositoryClass) . " extends AbstractRepository\n"
            . "{\n"
            . '    protected string $entityClass = ' . self::shortName($class) . "::class;\n"
            . "}\n";

        $this->write($file, $entity, $force);
        $this->write($repositoryFile, $repository, $force);

        return [$class, $file, $repositoryClass, $repositoryFile];
    }
}