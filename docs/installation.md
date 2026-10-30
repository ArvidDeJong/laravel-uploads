---
title: "Installation & configuration"
description: "Install darvis/laravel-uploads step by step: requirements, migration, disks, the queue, a check that it works, and every key of config/uploads.php."
nav_order: 2
---

# Installation & configuration

## Requirements

- PHP 8.2 or higher with the GD extension (`ext-gd`), which resizes the images. Composer refuses to install the package without it.
- Laravel 11, 12 or 13.
- Two filesystem disks: one for public files and one for private files. A new Laravel application has `public` and `local`, which are the defaults.
- A queue worker for the conversions, or `UPLOADS_QUEUE_CONNECTION=sync` to make them in the request.
- For PDF pages: the Imagick extension with Ghostscript, or the `pdftoppm` binary (`apt install poppler-utils`, `brew install poppler`). Without either, images still work and a PDF upload fails its processing job with `PdfRenderingUnavailable`.
- For the two components: Livewire 3 or 4.

## Step 1. Install the package

```bash
composer require darvis/laravel-uploads
```

The service provider is registered through Laravel's package discovery; there is nothing to add to `bootstrap/providers.php`.

## Step 2. Run the migration

```bash
php artisan migrate
```

It creates one table, `uploads`. The migration is loaded from the package and cannot be published.

## Step 3. Link the public disk

Public uploads are served straight from the `public` disk, so the usual symlink has to exist:

```bash
php artisan storage:link
```

On S3 nothing has to be linked; set the disks in the next step.

## Step 4. Set the disks and the queue

In `.env`:

```
UPLOADS_PUBLIC_DISK=public
UPLOADS_PRIVATE_DISK=local
UPLOADS_QUEUE_CONNECTION=sync
```

Keep `sync` while you try the package; switch to your queue connection (or leave the variable out, which takes the default connection) once a worker runs, because rendering a PDF or a 20 MB photo takes seconds you do not want in a request.

For S3 use your S3 disks, for example `UPLOADS_PUBLIC_DISK=s3` and `UPLOADS_PRIVATE_DISK=s3_private`, with a second disk in `config/filesystems.php` that points at the private bucket. See [Private files](private-files.md#s3) for the bucket side.

## Step 5. Add the trait to a model

```php
use Darvis\Uploads\Models\Concerns\HasUploads;

class Product extends Model
{
    use HasUploads;
}
```

## Check that it works

Run this in `php artisan tinker`. It stores a PNG that GD draws on the spot, so you need no file at hand:

```php
$image = imagecreatetruecolor(800, 600);
imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
$path = storage_path('app/check.png');
imagepng($image, $path);

$file = new \Illuminate\Http\UploadedFile($path, 'check.png', 'image/png', null, true);
$upload = \App\Models\Product::first()->addUpload($file, 'photos', public: true);

$upload->fresh()->conversions;
$upload->fresh()->url();
```

With `UPLOADS_QUEUE_CONNECTION=sync` the `conversions` array holds `thumb` and `preview` and both files exist under `storage/app/public/products/<id>/photos/`. With a real queue, `conversions` is `null` until the worker has processed the job. Open the URL in the browser; delete the test upload afterwards with `$upload->forceDelete()`, which removes the files too.

## Publish tags

```bash
php artisan vendor:publish --tag=uploads-config   # config/uploads.php
php artisan vendor:publish --tag=uploads-views    # resources/views/vendor/uploads
```

## Configuration

Every key of `config/uploads.php`, in the order of the file. Read the values in your own code through `Darvis\Uploads\Support\UploadsConfig`, not with `config('uploads.…')`.

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `conversions` | | `thumb`, `preview` | Named image versions, see [Conversions & PDF pages](conversions-and-pdf.md) |
| `disks.private` | `UPLOADS_PRIVATE_DISK` | `local` | Disk for private uploads |
| `disks.public` | `UPLOADS_PUBLIC_DISK` | `public` | Disk for public uploads |
| `link_expires_after_minutes` | `UPLOADS_LINK_EXPIRES_AFTER_MINUTES` | `30` | Lifetime of a signed link to a private upload |
| `max_kilobytes` | `UPLOADS_MAX_KILOBYTES` | `512000` | Largest file the Livewire upload manager accepts |
| `path_prefix` | `UPLOADS_PATH_PREFIX` | empty | Folder on the disk above the model folders |
| `pdf.dpi` | | `110` | Resolution of the rendered pages |
| `pdf.driver` | `UPLOADS_PDF_DRIVER` | `auto` | `imagick`, `pdftoppm` or `auto` |
| `pdf.format` | | `jpg` | `jpg` or `png` for the pages |
| `pdf.max_pages` | `UPLOADS_PDF_MAX_PAGES` | `50` | Pages rendered per PDF; `0` renders none |
| `pdf.pdftoppm_path` | `UPLOADS_PDFTOPPM_PATH` | `pdftoppm` | Binary name or absolute path |
| `pdf.quality` | | `85` | JPEG quality of the pages |
| `prune_after_days` | `UPLOADS_PRUNE_AFTER_DAYS` | `30` | Days in the trash before `uploads:prune` removes an upload |
| `queue.connection` | `UPLOADS_QUEUE_CONNECTION` | default | Queue connection of the processing job; `sync` runs it in the request |
| `queue.queue` | `UPLOADS_QUEUE` | default | Queue name of the processing job |
| `route_middleware` | | `['web']` | Middleware of the download route, `signed` is always added |
| `route_prefix` | | `uploads` | URL prefix of the download route |
| `temporary_url_minutes` | `UPLOADS_TEMPORARY_URL_MINUTES` | `5` | Lifetime of the S3 URL the download route redirects to |

The route prefix and middleware are read when the routes are registered, so changing them at runtime has no effect.
