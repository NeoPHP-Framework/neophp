# NeoPHP CI/CD

## Branches

| Branch | Purpose |
|---|---|
| `main` | Latest published major version. Only `dev` is merged into it. |
| `dev` | Development of the next major version. |
| `vX.x` | Maintenance of a major version (`v1.x`, `v2.x`...). Created automatically. |

Work branches start from:

- `feature/`, `docs/`...: the version branch (`vX.x`), or `dev` for the next major version
- `bugfix/`: `dev`
- `hotfix/`: `main`

## Automations

### 1. Merging `dev` into `main`: new major version

Workflow: `.github/workflows/release-major.yml`

1. Computes the next major version: `v1` if no tag exists, otherwise the highest major version + 1.
2. Creates the `vX.x` branch on the merge commit.
3. Creates the `vX.0.0` tag and the GitHub release with the changelog.

### 2. Merging into `vX.x`: minor or patch version

Workflow: `.github/workflows/release-version.yml`

The version type is detected from the pull request title and its commits:

| Pull request content | Version |
|---|---|
| at least one `feat(...)`, a `!` after the type (`refactor(kernel)!:`) or a `BREAKING CHANGE` footer | minor: `v1.0.3` → `v1.1.0` |
| `fix`, `perf`, `refactor`, `revert` or a non-conventional commit | patch: `v1.0.3` → `v1.0.4` |
| only `docs`, `test`, `ci`, `chore`, `style`, `build` | no release |

You can force the choice with a label on the pull request: `release:minor`, `release:patch` or `release:none`.

A major version is never created from a `vX.x` branch, whatever the commit messages. It is only created by merging `dev` into `main`: prepare it on `dev`, never on `vX.x`.

### 3. Push on `vX.x`: back-merge pull request to `dev`

Workflow: `.github/workflows/back-merge.yml`

Every push on `vX.x` opens a `vX.x → dev` pull request, so that fixes reach future versions. No pull request is opened if `dev` already contains everything, or if one is already open: the open one updates itself.

Merge it with a **merge commit** (no squash, no rebase).

### 4. Tests and static analysis

Workflows: `.github/workflows/tests.yaml` and `.github/workflows/phpstan.yaml`

On every pull request and every push on `dev`, `main` and `vX.x`:

- `composer validate --strict`, then PHPUnit on PHP 8.2, 8.3, 8.4 and 8.5;
- PHPStan on PHP 8.2.

Make them required checks (see [GitHub setup](#github-setup-once)): a pull request can then be merged only when both are green.

## Tests

```bash
composer install
composer test                                       # every test
vendor/bin/phpunit --testsuite=components           # components, packages or process
vendor/bin/phpunit tests/Component/Http             # one folder
vendor/bin/phpunit --filter=testIpMatches           # one test
```

The tests mirror `src/`: the tests of `src/components/Http/Request/Request.php` are in `tests/Component/Http/RequestTest.php`, namespace `NeoPHP\Tests\Component\Http`.

| Base class | Use |
|---|---|
| `PHPUnit\Framework\TestCase` | unit test of a class created by hand (`new Request(...)`) |
| `NeoPHP\Tests\KernelTestCase` | integration test: generates a project from the skeleton of the Installer in a temporary directory and boots its kernel |

`KernelTestCase`:

| Method | Description |
|---|---|
| `createProject(array $modules = [], array $files = []): string` | generates the project; `$modules` is the content of `config/config.php`, `$files` adds or replaces files (`'src/Controller/PostController.php' => '<?php ...'`) |
| `bootKernel(array $modules = [], string $environment = 'test', bool $debug = true): AbstractKernel` | boots the kernel of the project (created if needed) |
| `getContainer(): ContainerManagerInterface` | container of the booted kernel |
| `request(string $method, string $uri, array $parameters = [], array $server = [], ?string $content = null): Response` | handles a request through the kernel |

The project directory, the error handler of the kernel and the environment variables are reset after each test. A test that changes a static state (`Request::setTrustedProxies()`...) resets it in its `tearDown()`.

The tests are not part of the Composer package (`export-ignore` in `.gitattributes`).

## Changelog

The changelog of a release is generated in its description, from the commits between the previous version and the new one, grouped by type:

```
## v1.0.1 (2026-09-24)

### Bug fixes

- **routing**: handle trailing slash (abcdef1)

**Documentation**: https://github.com/<owner>/<repo>/blob/v1.x/docs/v1.x/README.md

**Full changelog**: https://github.com/<owner>/<repo>/compare/v1.0.0...v1.0.1
```

Commit format: `type(feature): message`.

`CHANGELOG.md` keeps a one-line summary of every version, and the documentation of each feature has its own Changelog section: update them in the pull request of the change.

## Documentation per version

Each major version has its documentation in `docs/vX.x/README.md`: how it works, its components and its changes. If the file exists, the release links to it.

## Full example

1. Development on `dev`, then a `dev → main` pull request is merged.
2. The `v1.x` branch, the `v1.0.0` tag and its release are created automatically.
3. A `bugfix/xxx → v1.x` pull request with `fix(routing): ...` is merged.
4. The `v1.0.1` tag and its release are created, and the `v1.x → dev` pull request is opened.
5. A `feature/xxx → v1.x` pull request with `feat(view): ...` is merged.
6. The `v1.1.0` tag and its release are created, and the `v1.x → dev` pull request is opened or updated.
7. A new `dev → main` pull request is merged.
8. The `v2.x` branch, the `v2.0.0` tag and its release are created.

## GitHub setup (once)

- Settings > Actions > General > Workflow permissions: select **Read and write permissions**.
- Same page: check **Allow GitHub Actions to create and approve pull requests**.
- Settings > General: set the default branch to **`dev`**.
- Protect `main`, `dev` and `v*.x` (Settings > Rules): merge through pull requests only, with the required status checks `PHPStan` and `PHPUnit (PHP 8.2)` to `PHPUnit (PHP 8.5)`.