## darvis/laravel-uploads

This package stores every uploaded file as a row of the `uploads` table (`Darvis\Uploads\Models\Upload`): a polymorphic owner, a named collection, public or private, extra data in `meta`, conversions and PDF pages made by a queued job, signed links for private files, soft and hard delete. Models get files through the `Darvis\Uploads\Models\Concerns\HasUploads` trait.

- Config lives under the key `uploads` (file `config/uploads.php`). Read it through `Darvis\Uploads\Support\UploadsConfig` (`publicDisk()`, `privateDisk()`, `conversions()`, `linkExpiresAfterMinutes()`, `pdfMaxPages()`, …), never through `config('uploads.…')` in code that extends the package.
- Store and read files through the trait; don't write `Upload` rows or paths by hand:

@verbatim
<code-snippet name="Attach files to a model" lang="php">
use Darvis\Uploads\Models\Concerns\HasUploads;

class Product extends Model
{
    use HasUploads;
}

$photo = $product->addUpload($file, 'photos', public: true, meta: ['caption' => 'Front']);
$print = $product->addUpload($file, 'files');                  // private by default

$product->uploadsIn('photos');          // ordered, without the trash
$product->firstUpload('photos');        // cover, or null
$product->reorderUploads('photos', [12, 7, 9]);

$photo->thumbnailUrl();                 // thumb conversion, or the original until the job ran
$photo->conversionUrl('preview');
$print->url();                          // signed link, link_expires_after_minutes
$print->downloadUrl();                  // same, forces a download with the original name
$print->meta('layer_height', 0.2);
$print->setMeta('approved', true);
</code-snippet>
@endverbatim

- `addUpload()` stores the file as `<path_prefix>/<models>/<id>/<collection>/<uuid>.<ext>` on the public or the private disk from the config, keeps the original name in `name`, and dispatches `Darvis\Uploads\Jobs\ProcessUpload` on the queue from `uploads.queue` (`sync` runs it in the request). The job makes every conversion from `uploads.conversions` (`width`, `height`, `fit` = `cover` or `contain`, `format` = `jpg`, `png` or `webp`, `quality`) next to the original, so a private upload keeps private conversions. `conversionUrl()` falls back to the original while the conversion is not there yet; `isProcessed()` says whether the job ran.
- A PDF is rendered to one image per page, up to `pdf.max_pages`, with Imagick or the `pdftoppm` binary (`pdf.driver`: `imagick`, `pdftoppm`, `auto`), and the first page gets the conversions, so `thumbnailUrl()` works for a PDF. `pageCount()` and `pageUrl($n)` show the pages; `pageUrl()` is `null` for a page that was not rendered. Without a driver the job throws `Darvis\Uploads\Exceptions\PdfRenderingUnavailable`; the file itself is stored anyway.
- Private files are served by the route `uploads.show` (prefix `uploads`, middleware `route_middleware` plus `signed`), which authorizes `view` with `Darvis\Uploads\Policies\UploadPolicy` (uploader, `user_id` on the owning model, or `is_admin` on the user), then streams the file or redirects to a temporary S3 URL. Register your own policy with `Gate::policy(Upload::class, …)` when the rules differ; a guest is refused by the default policy, so a policy that returns `true` is the way to make possession of the link enough.
- `$upload->delete()` is a soft delete that keeps the files; `restore()` brings it back; `forceDelete()` removes the row, the original, the conversions and the pages. `php artisan uploads:prune` force deletes everything in the trash older than `prune_after_days`; schedule it daily. Deleting the owning model does not touch its uploads.
- To add a conversion for existing uploads, change the config and dispatch the job again: `ProcessUpload::dispatchFor($upload)`.
- `Darvis\Uploads\Events\UploadProcessed` is dispatched after the job, also for files that get no conversions. `Darvis\Uploads\Support\UploadStatistics` gives `total()`, `trashed()`, `perDisk()` and `perCollection()`.
- With Livewire installed: `<livewire:uploads.upload-manager :model="$product" collection="photos" :public="true" accept="jpg,png" />` (upload, caption, drag to sort, remove; checks `update` on the model when a policy exists) and `<livewire:uploads.uploads-overview />` (every upload, totals, filters, trash). Views are Tailwind, publishable with `--tag=uploads-views`; texts go through `__()` with a Dutch translation in the package.
- For S3 use two buckets and two disks (`UPLOADS_PUBLIC_DISK=s3`, `UPLOADS_PRIVATE_DISK=s3_private`), no ACL or visibility in the disk config. Point `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` at the private bucket for direct browser uploads of large files.
- Tests: fake the two disks, keep the queue on `sync` or call `Queue::fake()`, use `UploadedFile::fake()->image()` for anything that goes through the conversions, call `->fresh()` after `addUpload()` before asserting on `conversions`.
