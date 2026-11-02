---
name: laravel-uploads-development
description: Work with darvis/laravel-uploads. Use it to attach files to a model in collections, show thumbnails and other conversions, render and show PDF pages, serve private files through signed links and the policy, keep extra data per upload, soft delete, restore, hard delete and prune, add a conversion to existing uploads, use the Livewire upload manager and overview, set up S3 with two buckets, and test code around uploads.
---

# darvis/laravel-uploads development

## When to use this skill

Use this skill when code stores or shows files in an application that has `darvis/laravel-uploads` installed, when a thumbnail does not appear, when a private link answers 403 or 404, when a PDF has no pages, when uploads have to be removed or cleaned up, when you add the Livewire components to a page, when you move the disks to S3, or when you write tests around uploads.

## How a file flows through the package

1. `$model->addUpload(UploadedFile $file, string $collection = 'files', bool $public = false, array $meta = [])` on a model with the `HasUploads` trait, or `app(StoreUpload::class)->handle($file, $collection, $public, $meta, $owner, $userId)` for an upload without owner or with an explicit uploader. For contents that did not come from a form (a generated image, a downloaded PDF): `$model->addUploadFromContents(string $contents, string $name, $collection, $public, $meta, ?string $mimeType = null)` or `StoreUpload::handleContents()`; never wrap bytes in a temporary `UploadedFile`.
2. `StoreUpload` picks the disk (`uploads.disks.public` or `.private`), builds the folder `<path_prefix>/<path_pattern>` (default `uploads/{module}/{year}/{month}`; `{module}` is the owner's `uploadsModule()`, by default the model name in plural, or the collection without owner; `{id}`, `{collection}` and `{day}` exist too), picks a file name of at most `file_names.max_length` characters (the `original` name as a slug by default, or `random` lower-case letters and digits, with `-2`, `-3` when that name is taken in the folder by any upload, the trash included, or by a file on the disk), stores with `storeAs()`, creates the `Upload` row (`user_id` from `auth()->id()`, `sort_order` the next number in that collection, `meta` as given) and dispatches `ProcessUpload::dispatchFor($upload)` on the connection and queue from `uploads.queue`.
3. `ProcessUpload` copies the original to a temp file. For a PDF it renders up to `pdf.max_pages` pages with `PdfRasterizer` (Imagick or `pdftoppm`) and stores them as `<basename>-page-<n>.<format>`. For an image, or the first page of a PDF, it makes every conversion from `uploads.conversions` with `ImageConverter` and stores them as `<basename>-<name>.<format>`. It writes the paths into `conversions` (`pages` is the key for the pages), sets `processed_at` and dispatches `UploadProcessed`. A file that is neither image nor PDF only gets `processed_at`.
4. `url()`, `conversionUrl()`, `thumbnailUrl()`, `pageUrl()` and `downloadUrl()` return the disk URL for a public upload and a temporary signed URL to `uploads.show` for a private one, with `conversion`, `page` and `download` as signed query parameters.
5. `uploads.show` authorizes `view` through the policy, resolves the path, and streams the file (local disks, inline, or as attachment with `?download=1`) or redirects to `temporaryUrl()` of the disk (S3), valid for `temporary_url_minutes`.

| Situation | What you get | State left behind |
| --- | --- | --- |
| `storeAs()` returns `false` | `RuntimeException`: `Upload [<name>] could not be stored on disk [<disk>].` | nothing |
| The processing job finds no image GD can decode | the Intervention exception, the job retries three times | upload stored, `processed_at` null, `conversionUrl()` returns the original |
| A conversion has a format other than jpg, png or webp | `InvalidArgumentException`: `Conversion format [<format>] is not supported; use jpg, png or webp.` | as above |
| A PDF and no Imagick or `pdftoppm` | `Darvis\Uploads\Exceptions\PdfRenderingUnavailable` from the job | upload stored and downloadable, no pages, no thumbnail |
| The upload was hard deleted before the job ran | the job returns without doing anything | nothing |
| A signed link after `link_expires_after_minutes`, or without signature | 403 from the `signed` middleware | nothing |
| The policy refuses | 403 `This action is unauthorized.` | nothing |
| `?conversion=` or `?page=` that does not exist | 404 | nothing |
| An upload in the trash | 404, because route model binding excludes soft deleted rows | nothing |
| `delete()` | row soft deleted, files kept, gone from relations, the route and the statistics | restorable |
| `forceDelete()` | row gone, original, conversions and pages removed from the disk | nothing |
| Deleting the owning model | nothing happens to its uploads | orphaned uploads with `uploadable` null |

## Showing files

```php
$product->uploadsIn('photos')->get();    // ordered, without the trash; a query until ->get()
$product->firstUpload('photos');        // cover photo or null
$photo->thumbnailUrl();                 // thumb, or the original until processed
$photo->conversionUrl('preview', minutes: 5);
$manual->pageCount();
$manual->pageUrl(2);                    // null when page 2 was not rendered
$upload->humanSize();                   // "1.2 MB"
$upload->isImage(); $upload->isVideo(); $upload->isPdf(); $upload->isProcessed();
```

In Blade, `<img src="…thumbnailUrl()…">` inside a loop with `wire:key` when Livewire is involved. A private thumbnail is a signed link that expires, so render it when the page is built, not once into a cached fragment.

## Extra data

`meta` is a JSON column read with dot keys:

```php
$product->addUpload($file, 'photos', meta: ['caption' => 'Front', 'source' => 'phone']);
$upload->meta('caption');
$upload->meta('print.layer_height', 0.2);
$upload->setMeta('approved', true);     // saves
```

Add a real column only when you filter or sort on the value.

## Private files and the policy

The default `UploadPolicy::view()` allows the uploader (`uploads.user_id`), a user whose id equals the `user_id` attribute of the owning model, and a user with a truthy `is_admin` attribute; it refuses guests. `delete` uses the same rule. Replace it:

```php
Gate::policy(\Darvis\Uploads\Models\Upload::class, \App\Policies\UploadPolicy::class);
```

A `view()` that returns `true` makes the signed link itself the only check, which is right for a link in a mail to someone without an account. Use a short `$minutes` for such links.

## Removing files

```php
$upload->delete();        // trash, files stay
$upload->restore();
$upload->forceDelete();   // files removed too
```

`php artisan uploads:prune [--days=N]` force deletes everything in the trash older than `prune_after_days` (30); schedule it daily. To remove uploads with their model, do it in the model's `deleting` event: `$this->uploads()->get()->each->delete()` (trash) or `->each->forceDelete()` (gone).

## Adding a conversion later

Edit `uploads.conversions`, then dispatch the job for the uploads that need it:

```php
Upload::images()->each(fn (Upload $upload) => ProcessUpload::dispatchFor($upload));
```

The job rewrites the `conversions` column; a conversion removed from the config leaves its old file on the disk.

## Livewire components

```blade
<livewire:uploads.upload-manager :model="$product" collection="photos" :public="true" accept="jpg,jpeg,png,webp" />
<livewire:uploads.uploads-overview />
```

Upload manager parameters: `model`, `collection` (`files`), `public` (`false`), `accept` (extensions, also the validation), `crop` (`false`; a Crop button per image with Cropper.js), `aspect-ratio` (`1`, `16/9`, `4:3`; empty for any shape), `ability` (`update`; checked on the model with `Gate::authorize()` when a policy exists for the model class; empty string skips it). It validates `file|max:<max_kilobytes>` per file, saves captions to `meta.caption`, sorts with Alpine's Sort plugin into `reorderUploads()`, soft deletes on remove, polls every five seconds while an upload is unprocessed, and dispatches the browser event `uploads-changed` with the collection. The overview authorizes `delete` on the upload for delete, restore and force delete and keeps its filters in the URL. Both need to be behind your own middleware; publish the views with `--tag=uploads-views`.

## Cropping

- `$upload->setCropArea(['x' => …, 'y' => …, 'width' => …, 'height' => …])` in pixels of the original: stored in `meta.crop` (`Upload::CROP`), rounded to whole pixels, `processed_at` set to null, `ProcessUpload` dispatched. The job passes the area to `ImageConverter` as the `crop` key of each conversion and cuts it out before `cover` or `scaleDown`. `setCropArea(null)` uses the whole image again; `cropArea()` reads it.
- The original file is never touched, so cropping is always reversible. One area per upload, shared by every conversion; a `cover` conversion with another shape cuts its own shape out of the area.
- Only images: `setCropArea()` throws `InvalidArgumentException` for other files and for an area without the four numbers, with a negative position or a size below 1. An area that reaches past an edge is kept inside the image.
- The component loads Cropper.js 1.6 from `uploads.cropper.script` and `.style` with Livewire's `@assets`. Empty values mean the app loads it and sets `window.Cropper`; version 2 of Cropper.js has another API and does not work.
- Cropper.js runs with `checkOrientation` and `checkCrossOrigin` off, so the original can come from a signed route or an S3 redirect without CORS; the browser and Intervention both apply the EXIF orientation, so the area matches.

## S3

Two disks, two buckets, no ACL and no `visibility` in the disk config:

```
UPLOADS_PUBLIC_DISK=s3
UPLOADS_PRIVATE_DISK=s3_private
LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=s3_private
```

The private bucket needs CORS for `PUT` and `POST` from the site's origin and a lifecycle rule on `livewire-tmp/` when Livewire uploads directly to it. The download route redirects to a presigned URL for the private bucket.

## Pitfalls

- The job runs on a queue. Without a worker nothing is processed; with `UPLOADS_QUEUE_CONNECTION=sync` it runs in the request, which is fine for small images and slow for PDFs and large photos.
- `conversionUrl()` never fails: it returns the original when the conversion is missing. A thumbnail that looks like the full image means the job did not run or the name is not in the config.
- `pdftoppm` has to be in the `PATH` of the PHP process. MAMP on a Mac does not see Homebrew's `/opt/homebrew/bin`; set `UPLOADS_PDFTOPPM_PATH`.
- `pageUrl()` is counted from 1 and returns `null` beyond `pageCount()`.
- A signed URL is computed over the full address, so `APP_URL` must match what the browser uses, or every link is a 403.
- `uploads()` and `uploadsIn()` exclude the trash. Use `Upload::withTrashed()` or `onlyTrashed()` to reach it.
- `uploads()` and `uploadsIn()` return a relation, not a collection. A `foreach` or `@foreach` over the relation itself loops over nothing, without an error; always call `->get()` (or `->first()`) first.
- `reorderUploads()` only touches the given collection; ids from another collection or model are ignored, and ids not in the list keep their place after the ordered ones.
- The route prefix and middleware are read when the routes are registered; changing them at runtime does nothing.
- Deleting the owning model leaves its uploads in place, with `uploadable` resolving to `null`.
- `Number::fileSize()` is used for `humanSize()`, so the format follows Laravel (`1.0 KB`, `2.3 MB`).
- Never build a path from the settings to find a file: the folder (`{year}`, `{month}`) is the moment of the upload and the settings may have changed since. Always use `$upload->path`, `conversionPath()` or the URL methods.
- With original names (the default), a public file can be found by guessing its name. Keep `random` for public files that should not be found that way, such as quotes or contracts on the public disk.
- With original names, two requests that store the same name in the same folder at the same moment can pick the same name. The second one overwrites the first file before its own row is refused by the unique index on `disk` and `path`, so the first upload then shows the second file. With `random` names this does not happen.
- Never name a model method `uploadsModule()` for something else: the package calls it for the folder name.
- Public conversion URLs carry `?v=<processed_at timestamp>`; a test that compares `conversionUrl()` with `Storage::disk()->url($path)` has to add it.

## Settings

Read them through `Darvis\Uploads\Support\UploadsConfig` (a singleton):

| Accessor | Key | Env | Default |
| --- | --- | --- | --- |
| `conversions()`, `conversion($name)` | `conversions` | | `thumb`, `preview` |
| `cropperScript()` | `cropper.script` | `UPLOADS_CROPPER_SCRIPT` | Cropper.js 1.6.2 on jsDelivr; null when empty |
| `cropperStyle()` | `cropper.style` | `UPLOADS_CROPPER_STYLE` | Cropper.js 1.6.2 on jsDelivr; null when empty |
| `privateDisk()` | `disks.private` | `UPLOADS_PRIVATE_DISK` | `local` |
| `publicDisk()` | `disks.public` | `UPLOADS_PUBLIC_DISK` | `public` |
| `fileNameMaxLength()` | `file_names.max_length` | `UPLOADS_FILE_NAME_MAX_LENGTH` | `20`, at least `8` |
| `fileNameStrategy()` | `file_names.strategy` | `UPLOADS_FILE_NAME_STRATEGY` | `original` (or `random`) |
| `linkExpiresAfterMinutes()` | `link_expires_after_minutes` | `UPLOADS_LINK_EXPIRES_AFTER_MINUTES` | `30` |
| `maxKilobytes()` | `max_kilobytes` | `UPLOADS_MAX_KILOBYTES` | `512000` |
| `pathPattern()` | `path_pattern` | `UPLOADS_PATH_PATTERN` | `{module}/{year}/{month}` |
| `pathPrefix()` | `path_prefix` | `UPLOADS_PATH_PREFIX` | `uploads` |
| `pdfDpi()` | `pdf.dpi` | | `110` |
| `pdfDriver()` | `pdf.driver` | `UPLOADS_PDF_DRIVER` | `auto` |
| `pdfFormat()` | `pdf.format` | | `jpg` |
| `pdfMaxPages()` | `pdf.max_pages` | `UPLOADS_PDF_MAX_PAGES` | `50` |
| `pdftoppmPath()` | `pdf.pdftoppm_path` | `UPLOADS_PDFTOPPM_PATH` | `pdftoppm` |
| `pdfQuality()` | `pdf.quality` | | `85` |
| `pruneAfterDays()` | `prune_after_days` | `UPLOADS_PRUNE_AFTER_DAYS` | `30` |
| `queueConnection()` | `queue.connection` | `UPLOADS_QUEUE_CONNECTION` | default connection |
| `queueName()` | `queue.queue` | `UPLOADS_QUEUE` | default queue |
| `routeMiddleware()` | `route_middleware` | | `['web']` |
| `routePrefix()` | `route_prefix` | | `uploads` |
| `temporaryUrlMinutes()` | `temporary_url_minutes` | `UPLOADS_TEMPORARY_URL_MINUTES` | `5` |

## Testing

```php
use Darvis\Uploads\Jobs\ProcessUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('stores a photo with a thumbnail', function () {
    Storage::fake('public');
    Storage::fake('local');
    $product = Product::factory()->create();

    $upload = $product->addUpload(UploadedFile::fake()->image('photo.png', 800, 600), 'photos', public: true)->fresh();

    expect($upload->hasConversion('thumb'))->toBeTrue();
    Storage::disk('public')->assertExists($upload->conversionPath('thumb'));
});

it('keeps a private file from strangers', function () {
    Storage::fake('local');
    Queue::fake();
    $upload = $inquiry->addUpload(UploadedFile::fake()->create('order.3mf', 10), 'attachments');

    $this->actingAs($stranger)->get($upload->url())->assertForbidden();
    $this->actingAs($inquiry->user)->get($upload->downloadUrl())->assertOk()->assertDownload('order.3mf');
});
```

- `Upload::factory()` (states `public()`, `pdf()`, `video()`, `processed()`) makes rows without a file, for ordering and policy tests; owner with `->for($model, 'uploadable')`.
- Keep the queue on `sync` (Laravel's test default) when the test asserts on conversions, and call `->fresh()` after `addUpload()`; `Queue::fake()` when it does not care, with `Queue::assertPushed(ProcessUpload::class)`.
- Use `UploadedFile::fake()->image()` for anything the job will decode; `create()` makes a file of zeros that is not an image.
- `Livewire::test(UploadManager::class, ['model' => $product])->set('files', [UploadedFile::fake()->image('a.png')])->call('save')` needs the fake instances; a plain `UploadedFile` fails in Livewire's test helper.
- Skip PDF rendering tests without a driver: `->skip(fn () => ! app(PdfRasterizer::class)->isAvailable())`.
- `$this->travel(31)->minutes()` turns a default signed link into a 403.
