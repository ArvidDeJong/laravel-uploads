---
title: "Quick start"
description: "One complete example: a product with public photos and a private print file, the upload form, the gallery with thumbnails and the signed download link."
nav_order: 3
---

# Quick start

A webshop sells 3D printed parts. Every product has photos that the site shows, and print files that only the shop owner may open. This page builds both from a fresh install.

## 1. The model

```php
use Darvis\Uploads\Models\Concerns\HasUploads;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasUploads;
}
```

## 2. Storing files

In a controller, a Livewire component or a job, with any `Illuminate\Http\UploadedFile`:

```php
// Public: stored on the public disk, shown on the site.
$photo = $product->addUpload($request->file('photo'), 'photos', public: true, meta: [
    'caption' => 'Front view',
]);

// Private: stored on the private disk, only reachable through a signed link.
$print = $product->addUpload($request->file('print'), 'files', meta: [
    'layer_height' => 0.2,
    'infill' => 20,
]);
```

Each call stores the file as `products/<id>/<collection>/<uuid>.<ext>`, writes a row to `uploads` and queues a job that makes the configured conversions. The original file name is kept in `name`.

## 3. The gallery

The templates on this site use PHP echo tags; in your Blade files write the usual double curly braces instead.

```blade
@foreach ($product->uploadsIn('photos') as $photo)
    <a href="<?= $photo->conversionUrl('preview') ?>">
        <img src="<?= $photo->thumbnailUrl() ?>" alt="<?= $photo->meta('caption', $photo->name) ?>">
    </a>
@endforeach
```

`thumbnailUrl()` is the `thumb` conversion once the job has run and the original until then, so the page never shows a broken image. `uploadsIn()` returns the uploads in display order and leaves out everything in the trash.

## 4. The private file

```blade
@can('view', $print)
    <a href="<?= $print->downloadUrl() ?>"><?= $print->name ?> (<?= $print->humanSize() ?>)</a>
@endcan
```

`downloadUrl()` is a signed link to the route `uploads.show`, valid for `link_expires_after_minutes` (30), that forces a download with the original file name. The route checks `Darvis\Uploads\Policies\UploadPolicy`: the uploader, a user whose id is the `user_id` of the product and an administrator (a truthy `is_admin` attribute) may open it. Replace the policy when your rules differ, see [Private files](private-files.md#who-may-open-a-private-upload).

## 5. The upload form with Livewire

When Livewire is installed, the package registers a component that does steps 2 and 3 for the administration:

```blade
<livewire:uploads.upload-manager :model="$product" collection="photos" :public="true" accept="jpg,jpeg,png,webp" />
<livewire:uploads.upload-manager :model="$product" collection="files" accept="3mf,stl,step,obj,gcode" />
```

Each one shows a file input, the list of that collection with thumbnails, a caption field per file, drag and drop sorting and a remove button. See [Livewire components](livewire.md).

## 6. The administration

```blade
<livewire:uploads.uploads-overview />
```

Totals per disk and collection, every upload with filters, and the trash with restore and delete for good. Schedule the pruning so the trash does not grow for ever:

```php
// routes/console.php
Schedule::command('uploads:prune')->daily();
```

## 7. A PDF

A PDF goes through the same `addUpload()`. The job renders every page to an image and makes the conversions from the first page, so a manual gets a thumbnail like a photo:

```blade
<img src="<?= $manual->thumbnailUrl() ?>" alt="<?= $manual->name ?>">

@for ($page = 1; $page <= $manual->pageCount(); $page++)
    <img src="<?= $manual->pageUrl($page) ?>" alt="Page <?= $page ?>">
@endfor
```

That needs Imagick or `pdftoppm` on the server, see [Conversions & PDF pages](conversions-and-pdf.md#pdf-pages).
