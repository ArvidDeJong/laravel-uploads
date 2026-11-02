# Changelog

All notable changes to `darvis/laravel-uploads` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [1.2.0] - 2026-11-02

### Added

- Cropping images in the upload manager with Cropper.js: `:crop="true"` adds a Crop button per image, `aspect-ratio="16/9"` fixes the shape. The original file is never changed; the area is kept in `meta.crop` and the conversions are made from it.
- `Upload::setCropArea()`, `Upload::cropArea()` and the constant `Upload::CROP` to crop from your own code. `setCropArea(null)` uses the whole image again.
- `cropper.script` and `cropper.style` (`UPLOADS_CROPPER_SCRIPT`, `UPLOADS_CROPPER_STYLE`): where Cropper.js 1.6.2 is loaded from, jsDelivr by default. Set both to empty to load it from your own bundle.
- Documentation: a "Cropping images" page for developers new to the package.

### Changed

- The URL of a conversion on the public disk now ends with `?v=<time of processing>`, so browsers and CDNs fetch a conversion again after it was made again. Update tests that compare `conversionUrl()` with `Storage::url()`. Republish the views if you published `upload-manager.blade.php` and want the Crop button.

## [1.1.0] - 2026-11-02

### Added

- `path_pattern` (`UPLOADS_PATH_PATTERN`): the folders below `path_prefix`, with the placeholders `{module}`, `{id}`, `{collection}`, `{year}`, `{month}` and `{day}`. `{module}` is the owning model in plural, the collection for an upload without owner.
- `uploadsModule()` on the `HasUploads` trait: override it to give a model its own folder name, for example `news` for `NewsItem`.
- `file_names.max_length` (`UPLOADS_FILE_NAME_MAX_LENGTH`, default `20`, at least `8`) and `file_names.strategy` (`UPLOADS_FILE_NAME_STRATEGY`, `original` or `random`). `original` keeps the original name as a slug, cut to the maximum length after the last whole word; `random` gives lower-case letters and digits; a name that is taken in the folder, by any upload or by a file on the disk, gets `-2`, `-3` and so on.
- Documentation: "Where the files are stored" on the How it works page, with the recommended layout.

### Changed

- New uploads are stored as `uploads/<module>/<year>/<month>/<original name>.<ext>`, for example `uploads/products/2026/11/front.jpg`, instead of `<models>/<id>/<collection>/<uuid>.<ext>`. Public files can now be found by guessing their name; set `UPLOADS_FILE_NAME_STRATEGY=random` when that matters. The default of `path_prefix` is now `uploads`. Existing uploads keep their path and keep working. To keep the old folders, set `path_pattern` to `{module}/{id}/{collection}` and `path_prefix` to an empty string.

## [1.0.1] - 2026-10-30

### Added

- Documentation for developers new to Laravel: a step by step tutorial from a fresh Laravel app to a photo page with thumbnails, a "How it works" page that explains owner, collection, disks, conversions, the queue, signed links and the trash, and a Forms section in Troubleshooting (419, a missing `enctype`, PHP upload limits).
- `support.docs` in `composer.json`, pointing at the documentation site.

### Changed

- Documentation: the quick start gallery fetches the photos with `uploadsIn('photos')->get()`; the old example looped over the query itself and showed nothing. Template examples now print with `e()`, so they are safe to paste. The installation check stores an upload without an owner, so it works before you have a model.

### Fixed

- A private upload on a local disk is streamed again on Laravel 11.0 to 11.29 when the disk has a temporary URL callback, as `Storage::fake()` sets one. The download route redirected to that callback's URL instead, because Illuminate's `LocalFilesystemAdapter` only exists since Laravel 11.30; the route now asks the Flysystem adapter whether the disk is local.
- With `pdf.driver` set to `auto`, a PDF is now rendered with `pdftoppm` when the Imagick extension is loaded but cannot read PDF files (Ghostscript missing, or ImageMagick's policy forbids PDF, the default on Debian and Ubuntu). Before, the processing job failed with `ImagickException: Failed to read the file`. Without `pdftoppm` the job now throws `PdfRenderingUnavailable` with the reason, for `auto` and `imagick` alike. Dispatch `ProcessUpload::dispatchFor($upload)` again for PDFs whose job failed.
- `forceDelete()` on an upload that was loaded before the processing job ran, such as the one `addUpload()` returns, now also removes the conversions and the PDF pages. Before, only the original was removed and the generated files stayed on the disk. Files left behind by earlier hard deletes are not cleaned up; look for `<uuid>-*` files without an original in the upload folders.

## [1.0.0] - 2026-10-30

### Added

- `Darvis\Uploads\Models\Upload`: one row per file with a polymorphic owner, `collection`, `disk`, `path`, `name`, `mime_type`, `size`, `is_public`, `sort_order`, `meta` (JSON), `conversions` (JSON) and `processed_at`, with soft deletes.
- `Darvis\Uploads\Models\Concerns\HasUploads`: `uploads()`, `uploadsIn()`, `firstUpload()`, `addUpload()` and `reorderUploads()`, and the `Darvis\Uploads\Contracts\UploadOwner` interface it satisfies.
- `Darvis\Uploads\Actions\StoreUpload`: stores a file on the public or private disk as `<prefix>/<models>/<id>/<collection>/<uuid>.<ext>`, writes the row and dispatches the processing job.
- `Darvis\Uploads\Jobs\ProcessUpload`: generates the conversions from `uploads.conversions` (width, height, `cover` or `contain`, `jpg`, `png` or `webp`, quality) with Intervention Image 3 on GD, renders a PDF to one image per page with Imagick or `pdftoppm` and makes the conversions from its first page, and dispatches `Darvis\Uploads\Events\UploadProcessed`.
- Route `uploads.show` (signed) with `Darvis\Uploads\Http\Controllers\UploadController`, serving the original, a conversion (`?conversion=`) or a page (`?page=`) of a private upload, inline or as a download (`?download=1`), streamed or through a redirect to a temporary URL of the disk.
- `Darvis\Uploads\Policies\UploadPolicy`: the uploader, the owner of the model (`user_id`) and an administrator (`is_admin`) may open and delete; replaceable with `Gate::policy()`.
- `uploads:prune` command and `Prunable` on the model: hard delete, files included, after `prune_after_days` in the trash.
- `Darvis\Uploads\Support\UploadStatistics`: totals per disk and per collection, the total and the trash.
- Livewire components `uploads.upload-manager` (upload, caption, drag to sort, remove, for one collection of one model) and `uploads.uploads-overview` (every upload, totals, filters, trash with restore and delete for good), registered when Livewire is installed, with Tailwind views and a Dutch translation.
- `Darvis\Uploads\Support\UploadsConfig` as the single reader of the package config.
- Laravel Boost guideline and skill, Pint, Larastan level 8 and the shared CI workflow on PHP 8.2 to 8.4 with Laravel 11, 12 and 13.
- Documentation site at <https://arviddejong.github.io/laravel-uploads/>, built from `docs/`.
