# Changelog

All notable changes to `darvis/laravel-uploads` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
