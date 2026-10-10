# Upload

The Upload component (`src/components/Upload`) stores the files sent by the users in a public directory (`public/uploads/` by default) and builds their URLs.

## Summary

- [Module](#module)
- [Configuration](#configuration)
- [Storing a file](#storing-a-file)
- [Displaying a file](#displaying-a-file)
- [Deleting a file](#deleting-a-file)
- [Security](#security)
- [API](#api)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Component\Upload\UploadManager` (`final`) |
| Interface | `NeoPHP\Component\Upload\UploadManagerInterface` |
| Attribute | `#[Component(provider: UploadProvider::class)]` |
| Requires | nothing |

Inject `UploadManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Component\Upload\UploadManagerInterface;

public function __construct(private UploadManagerInterface $upload)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Component\Upload\UploadManager::class => false,
```

The public API of the module is its manager and its interface, `Contract\`, the attributes, the exceptions, the events and the classes documented below. The classes marked `@internal` are used by the framework only.

## Configuration

`config/framework/upload.yaml` (key `framework.upload`):

```yaml
path: '%kernel.public_path%/uploads'
public_url: /uploads
max_size: 5242880
mime_types:
    image/jpeg: jpg
    image/png: png
    image/gif: gif
    image/webp: webp
    image/avif: avif
```

| Option | Default | Description |
|---|---|---|
| `path` | `public/uploads` | directory where the files are stored |
| `public_url` | `/uploads` | URL of this directory |
| `max_size` | `5242880` (5 MB) | maximum size in bytes, `0` = no limit |
| `mime_types` | images (jpeg, png, gif, webp, avif) | allowed MIME types and the extension given to the stored file |

Add `/public/uploads/` to `.gitignore` (done in the generated projects).

## Storing a file

```php
#[Route('/project/{id}/cover', name: 'project_cover', methods: ['POST'])]
public function cover(Project $project, Request $request, EntityManagerInterface $entityManager): Response
{
    $file = $request->files->get('cover');

    $this->deleteUpload($project->getCover());
    $project->setCover($this->storeUpload($file, 'projects/' . $project->getId() . '/cover'));
    $entityManager->flush();

    return $this->redirectToRoute('project_show', ['id' => $project->getId(), 'slug' => $project->getSlug()]);
}
```

`storeUpload()` returns the path relative to the upload directory (`projects/12/cover/9f2c….webp`): store it in the database. Options (third argument):

| Option | Description |
|---|---|
| `max_size` | maximum size for this upload |
| `mime_types` | allowed MIME types for this upload (`['application/pdf' => 'pdf']`) |
| `name` | file name without extension (sanitized), a random name by default; an existing file with the same name is replaced |

An `UploadException` is thrown when the file is invalid, too large or of a type that is not allowed. Elsewhere, inject `NeoPHP\Component\Upload\UploadManagerInterface` (also `uploader` in the container).

## Displaying a file

```twig
<img src="{{ upload(project.cover) }}" alt="{{ project.name }}">
<img src="{{ upload(app.user.avatar, asset('images/avatar.webp')) }}" alt="">
```

`upload(path, default = null)` accepts a relative path (`projects/12/cover/a.webp`) or a path starting with the public URL (`/uploads/projects/12/cover/a.webp`), adds the base path of the application (sub-directory installs) and returns external URLs (`https://…`) unchanged. An empty path returns `default`.

`asset()` is not used for uploads: it compiles the files of `assets/` into `public/builds/`.

## Deleting a file

```php
$this->deleteUpload($project->getCover());
```

Returns `false` when the path is empty, external or the file does not exist.

## Security

- The MIME type is detected from the content of the file (`fileinfo` extension), not from the name or the type sent by the browser.
- The stored file gets a random name and the extension of its MIME type: `photo.php.png` becomes `9f2c….png`. Executable extensions (`php`, `phtml`, `phar`, `html`, `svg`, `js`...) are refused even when listed in `mime_types`.
- Paths containing `..` are refused.
- `public/.htaccess` only executes `index.php`.
- Private files (invoices, documents...) must not be stored in `public/`: store them in `var/` and send them from a controller that checks the access rights.

## API

`UploadManagerInterface`:

| Method | Description |
|---|---|
| `store(UploadedFile $file, string $directory = '', array $options = []): string` | validates and moves the file, returns its relative path |
| `delete(?string $path): bool` | deletes a stored file |
| `exists(?string $path): bool` | whether the file exists |
| `url(?string $path, ?string $default = null): ?string` | public URL |
| `path(string $path): string` | absolute path on the disk |
| `getUploadPath()`, `getPublicUrl()` | configuration |

Controller helpers (`UploadController` trait of `AbstractController`): `storeUpload()`, `deleteUpload()`, `uploadUrl()`. View function: `upload()`.

## Changelog

- v2.0.0 — `UploadManager` is the `final` entry point of the module, declared with `#[Component]`; `UploadManagerInterface` replaces `Contract\UploaderInterface`; `Contract\AbstractUploader` is merged into the manager; the internal classes are marked `@internal`.
- v1.40.0 — Upload component: `UploaderInterface` (`store()`, `delete()`, `exists()`, `url()`, `path()`), MIME type detection, random names, size limit, `upload()` view function, `storeUpload()` / `deleteUpload()` / `uploadUrl()` controller helpers, `config/framework/upload.yaml`