---
title: "Quick start"
description: "One complete example: a product with public photos and a private print file, the upload form, the gallery with thumbnails and the signed download link."
nav_order: 5
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

In a controller, a Livewire component or a job, with any `Illuminate\Http\UploadedFile`. The [tutorial](tutorial.md) shows the complete route, controller, validation and form around these lines.

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

{: .note }
This site cannot show Blade's double curly braces, so the templates use `<?= e(...) ?>`, which escapes the value exactly like the braces do. Paste them as they are or write the braces; never drop the `e()`.

```blade
@foreach ($product->uploadsIn('photos')->get() as $photo)
    <a href="<?= e($photo->conversionUrl('preview')) ?>">
        <img src="<?= e($photo->thumbnailUrl()) ?>" alt="<?= e($photo->meta('caption') ?: $photo->name) ?>">
    </a>
@endforeach
```

`thumbnailUrl()` is the `thumb` conversion once the job has run and the original until then, so the page never shows a broken image. `uploadsIn()` is a query for the uploads in display order, without everything in the trash; `->get()` fetches them. A `foreach` over the query itself, without `->get()`, loops over nothing.

## 4. The private file

```blade
@can('view', $print)
    <a href="<?= e($print->downloadUrl()) ?>"><?= e($print->name) ?> (<?= e($print->humanSize()) ?>)</a>
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
<img src="<?= e($manual->thumbnailUrl()) ?>" alt="<?= e($manual->name) ?>">

@for ($page = 1; $page <= $manual->pageCount(); $page++)
    <img src="<?= e($manual->pageUrl($page)) ?>" alt="Page <?= e($page) ?>">
@endfor
```

That needs Imagick or `pdftoppm` on the server, see [Conversions & PDF pages](conversions-and-pdf.md#pdf-pages).
