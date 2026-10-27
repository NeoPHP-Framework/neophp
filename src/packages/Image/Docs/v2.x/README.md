# Image

The Image package (`src/packages/Image`) processes the images of the application with the GD extension of PHP, without any other dependency: thumbnails and resized copies generated on demand and cached, conversion to WebP or AVIF, photos turned upright from their EXIF orientation, metadata (GPS position, camera) removed.

## Summary

- [Module](#module)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Presets](#presets)
- [Displaying an image](#displaying-an-image)
- [Optimizing an upload](#optimizing-an-upload)
- [Editing an image](#editing-an-image)
- [Commands](#commands)
- [ImageManagerInterface](#imagemanagerinterface)
- [Security and limits](#security-and-limits)
- [Errors](#errors)
- [Changelog](#changelog)

## Module

| | |
|---|---|
| Manager | `NeoPHP\Package\Image\ImageManager` (`final`) |
| Interface | `NeoPHP\Package\Image\ImageManagerInterface` |
| Attribute | `#[Package(provider: ImageProvider::class, requires: [UploadManager::class])]` |
| Requires | Upload component; PHP extension `gd` (with WebP support for WebP, `imageavif()` for AVIF); `exif` recommended |

Inject `ImageManagerInterface` in a service, a command or a controller:

```php
use NeoPHP\Package\Image\ImageManagerInterface;

public function __construct(private ImageManagerInterface $images)
{
}
```

The module is enabled by default. To disable it in a project, add it to `config/config.php` (see the Kernel documentation):

```php
NeoPHP\Package\Image\ImageManager::class => false,
```

The public API of the module is its manager and its interface, `Model\Image`, the exception and the classes documented below. The classes marked `@internal` are used by the framework only.

## Quick start

Store a photo, then prepare it (upright, without metadata, at most 2560 pixels, WebP):

```php
#[Route('/photos', name: 'photo_upload', methods: ['POST'])]
public function upload(Request $request): Response
{
    $path = $this->storeUpload($request->files->get('photo'), 'photos');   // photos/3f9c…e1.jpg
    $path = $this->optimizeImage($path, ['format' => 'webp']);              // photos/3f9c…e1.webp

    // ... save $path in the database

    return $this->redirectToRoute('photo_list');
}
```

Display it with a preset of `config/packages/image.yaml`:

```twig
<img src="{{ image(photo.path, 'thumbnail') }}" width="300" height="300" alt="">
<a href="{{ image(photo.path, 'medium') }}">Open</a>
```

The derivative (`public/media/cache/thumbnail/photos/3f9c…e1.webp`) is generated the first time it is displayed, then served by the web server like any static file.

## Configuration

`neo install` generates `config/packages/image.yaml`:

```yaml
cache_path: '%kernel.public_path%/media/cache'
cache_url: /media/cache
quality: 82
max_pixels: 50000000

presets:
    thumbnail: { width: 300, height: 300, mode: cover, format: webp }
    medium: { width: 1200, height: 1200, mode: fit, format: webp }

optimize:
    max_width: 2560
    max_height: 2560
    format: ~
    quality: 85
```

| Key | Default | Description |
|---|---|---|
| `cache_path` | `public/media/cache` | directory of the derivatives (must be under `public/`) |
| `cache_url` | `/media/cache` | public URL of `cache_path` |
| `quality` | `82` | default quality of JPEG, WebP and AVIF (0 to 100) |
| `max_pixels` | `50000000` | images with more pixels (width × height) are refused before being decoded |
| `presets` | `{ }` | named transformations, see [Presets](#presets) |
| `optimize` | see above | default options of `optimize()` |

`/public/media/cache/` is added to the `.gitignore` of new projects.

## Presets

| Option | Default | Description |
|---|---|---|
| `width`, `height` | `~` | size of the box in pixels; at least one of them |
| `mode` | `fit` | `fit`: inside the box, ratio kept; `cover`: fills the box exactly and cuts what overflows (a single dimension gives a square); `resize`: exact size, the ratio can change (`~` for a dimension keeps the ratio) |
| `position` | `center` | part kept by `cover`: `center`, `top`, `bottom`, `left`, `right`, `top-left`, `top-right`, `bottom-left`, `bottom-right` |
| `format` | `~` | `webp`, `jpeg`, `png`, `gif`, `avif`, or `~` for the format of the source |
| `quality` | `quality` | quality of this preset |
| `upscale` | `false` | enlarges the images smaller than the box |

```yaml
presets:
    avatar: { width: 96, mode: cover, format: webp }             # 96x96 square
    banner: { width: 1600, height: 400, mode: cover, position: top }
    gallery: { height: 400, mode: fit }                           # height 400, any width
    print: { width: 3000, format: jpeg, quality: 95 }
```

A derivative is generated again when its source is more recent. After a change of a preset, run `php bin/neo image:clear <preset>`: the derivatives are generated again on their next display.

The photos are always turned upright (EXIF orientation) before the preset is applied, and the derivatives never contain metadata.

## Displaying an image

The `image()` view function returns the URL of a derivative, generated when it is missing or outdated:

```twig
<img src="{{ image(user.avatar, 'avatar', asset('images/avatar.webp')) }}" alt="">
```

```php
<img src="<?= $this->image($user->getAvatar(), 'avatar', '/images/avatar.webp') ?>" alt="">
```

`image(path, preset, default = null)` accepts a path relative to the upload directory (`photos/a.jpg`, as returned by `storeUpload()`) or a URL of the uploads (`/uploads/photos/a.jpg`). It returns `default` when the path is empty or the file does not exist, and the URL unchanged for an external URL (`https://…`). The URL contains the sub-directory of the application.

In a controller, `imageUrl($path, $preset, $default = null)` does the same (`ImageController` trait of `AbstractController`); in a service, `$images->url($path, $preset, $default)`.

The first display of a page with many new images generates all of them: generate them in advance with `image:warmup`, or call `thumbnail()` in a job of the Queue package after the upload.

## Optimizing an upload

Phone photos are large (4000 × 3000, 3 to 8 MB), often stored sideways with an EXIF orientation, and contain the GPS position where they were taken. `optimize()` replaces the file with a clean copy:

1. turned upright according to its EXIF orientation;
2. shrunk to `max_width` × `max_height` (ratio kept, never enlarged);
3. converted to `format` when it is given (the extension changes: `a.jpg` becomes `a.webp`, the old file is deleted);
4. saved without any metadata.

```php
$path = $images->optimize('photos/a.jpg');                                         // same path
$path = $images->optimize('photos/a.jpg', ['format' => 'webp', 'max_width' => 1920]); // photos/a.webp
```

It returns the new path (relative when the given path was relative): save this one. The derivatives of the image are deleted. GIF images are left untouched unless a `format` is given (GD would keep only the first frame of an animated GIF).

## Editing an image

`open($path)` and `read($bytes)` return a `NeoPHP\Package\Image\Model\Image`, whose methods change the image and return it:

```php
$images->open('photos/a.jpg')
    ->autoOrient()
    ->cover(800, 800, 'top')
    ->greyscale()
    ->save($images->getCachePath() . '/custom/a.webp', 'webp', 80);

$bytes = $images->read($request->getContent())->fit(512, 512)->encode('png');
```

| Method | Description |
|---|---|
| `Image::fromFile(string $path, int $maxPixels = 0)`, `Image::fromString(string $data, string $name = 'string', int $maxPixels = 0)`, `Image::canvas(int $width, int $height, string $format = 'png')` | create an image |
| `getWidth()`, `getHeight()`, `getFormat()`, `getMimeType()`, `getOrientation()`, `getResource(): GdImage` | information |
| `autoOrient()` | turns the photo upright according to its EXIF orientation |
| `fit(?int $width, ?int $height, bool $upscale = false)` | inside the box, ratio kept |
| `cover(int $width, int $height, string $position = 'center', bool $upscale = false)` | fills the box and cuts what overflows |
| `resize(?int $width, ?int $height)` | exact size (`null` keeps the ratio) |
| `crop(int $x, int $y, int $width, int $height)` | cuts a rectangle |
| `rotate(float $degrees, string $background = 'transparent')` | clockwise rotation (`#RRGGBB` or `#RRGGBBAA` for the corners) |
| `flip(bool $horizontal = true, bool $vertical = false)`, `greyscale()` | mirror, shades of grey |
| `encode(?string $format = null, int $quality = 82): string` | the bytes of the image (format of the source by default) |
| `save(string $path, ?string $format = null, int $quality = 82): string` | writes the file (format from the extension by default), through a temporary file |
| `Image::supports(string $format): bool` | whether GD can write this format on this server |

The transparency is kept in PNG, WebP, AVIF and GIF; a transparent image saved as JPEG gets a white background. `clone $image` gives an independent copy.

## Commands

```bash
php bin/neo image:warmup
php bin/neo image:warmup thumbnail medium --directory=photos
php bin/neo image:clear
php bin/neo image:clear thumbnail
```

| Command | Description |
|---|---|
| `image:warmup [presets...] [--directory=]` | generates the derivatives of the images of the uploads (all presets by default) |
| `image:clear [preset]` | deletes the derivatives (of one preset) |

## ImageManagerInterface

| Method | Description |
|---|---|
| `open(string $path): Image` | loads an image (absolute path, path relative to the uploads or URL of the uploads) |
| `read(string $data): Image` | loads an image from its bytes |
| `thumbnail(string $path, string $preset): string` | absolute path of the derivative, generated when needed |
| `url(?string $path, string $preset, ?string $default = null): ?string` | public URL of the derivative |
| `optimize(string $path, array $options = []): string` | prepares an original, returns its new path |
| `forget(string $path): int` | deletes the derivatives of an image |
| `clear(?string $preset = null): int` | deletes the derivatives |
| `getPresets(): array`, `getCachePath(): string` | configuration |

The manager is also available with the `image` container id. Call `forget($path)` when an image is replaced or deleted (`deleteUpload()` does not know the derivatives).

## Security and limits

- A relative path is resolved in the upload directory: `..` is refused, so a path coming from the database cannot reach another file.
- `max_pixels` refuses an image before decoding it: a small file can declare a huge size and fill the memory (decompression bomb). GD needs about 4 bytes per pixel: a 12 megapixel photo uses about 50 MB of memory while it is processed; raise `memory_limit` if needed.
- The type of a file is detected from its content, never from its extension.
- Derivatives are public files: never use a preset on a private image, serve it with a file response of the Http component after checking the access instead (`open()` then `save()` in `var/`).
- AVIF needs a GD built with AVIF support (`Image::supports('avif')`); animated GIF and WebP images lose their animation.

## Errors

Every error throws `NeoPHP\Package\Image\Exception\ImageException` (extends `FrameworkException`): GD missing, unknown preset or invalid option, file missing or not a supported image, image larger than `max_pixels`, path outside the uploads, format not supported by GD, file that cannot be written.

## Changelog

- v2.6.0 — Image package: `ImageManager` and `ImageManagerInterface`, `Model\Image` (fit, cover, resize, crop, rotate, flip, greyscale, EXIF orientation, encoding in JPEG, PNG, GIF, WebP and AVIF without metadata), presets with cached derivatives, `image()` view function, `imageUrl()` / `optimizeImage()` controller helpers, `optimize()` for the uploads, `max_pixels` protection, `image:warmup` and `image:clear` commands, `config/packages/image.yaml` generated by `neo install`.