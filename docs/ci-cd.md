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
- Protect `main` and `v*.x` (Settings > Rules): merge through pull requests only.