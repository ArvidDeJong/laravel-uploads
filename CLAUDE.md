# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository. The conventions shared by every darvis package (language, releases, CI, docs site, Boost guidelines, public API policy) are in [../CLAUDE.md](../CLAUDE.md); this file only holds what is specific to this package.

## Package overview

`darvis/laravel-uploads` is a Laravel package (PHP 8.2+ with GD, Laravel 11/12/13) that stores every uploaded file as a row of one `uploads` table: a polymorphic owner, a collection, public or private, extra data in `meta`, conversions and PDF pages made by a queued job, signed links for private files, soft and hard delete. Two Livewire components (upload manager, overview) are registered when Livewire is installed. Host apps consume it via Composer; this repo only contains the library.

- Namespace: `Darvis\Uploads\` → `src/`
- Service provider auto-registered via `extra.laravel.providers` in [composer.json](composer.json); no facade
- Config key: `uploads`, file in [config/uploads.php](config/uploads.php); view namespace `uploads`; translations are JSON in `lang/`

## Commands

```bash
composer test                 # Pest suite
vendor/bin/pest --filter "renders the pages"
composer lint                 # Pint (check only); composer format fixes
composer analyse              # Larastan, level 8 (also analyses tests/Fixtures, so the trait is covered)
```

The PDF tests skip themselves without Imagick or `pdftoppm`; install `poppler-utils` to run them.

## Architecture

- [UploadsConfig](src/Support/UploadsConfig.php) is the only place that reads the package config; every class asks it. Never call `config('uploads.…')` elsewhere. `userModel()` resolves the user model from the default auth provider, so the `user()` relation works in any host app.
- Storing goes through [StoreUpload](src/Actions/StoreUpload.php): disk from `is_public`, directory `<prefix>/<models>/<id>/<collection>` (`uploads/<collection>` without owner), file name a uuid with the original extension, `storeAs()` so a Livewire temporary file on S3 streams bucket to bucket, then the row and `ProcessUpload::dispatchFor()`. [HasUploads](src/Models/Concerns/HasUploads.php) is a thin wrapper around it; [UploadOwner](src/Contracts/UploadOwner.php) declares the same methods so the Livewire component can type its `$model` property (`Model&UploadOwner`).
- [ProcessUpload](src/Jobs/ProcessUpload.php) downloads the original to a temp directory, renders PDF pages with [PdfRasterizer](src/Processing/PdfRasterizer.php) (Imagick, or `pdftoppm` through Symfony Process; `auto` picks the first available), makes every conversion with [ImageConverter](src/Processing/ImageConverter.php) (Intervention Image 3, GD driver, `cover` or `scaleDown`), writes them next to the original as `<basename>-<name>.<ext>` and `<basename>-page-<n>.<ext>`, stores the paths in the `conversions` JSON column (`pages` is the reserved key, `Upload::PAGES`), sets `processed_at`, cleans up and dispatches `UploadProcessed`. A file that is neither image nor PDF only gets `processed_at`. The job always writes to the upload's own disk, so private stays private.
- [Upload](src/Models/Upload.php) builds every URL in `urlFor()`: a public upload returns the disk URL of the asked file, a private upload a `temporarySignedRoute('uploads.show', …)` with `conversion`, `page` and `download` as signed query parameters. `conversionUrl()` falls back to the original while the conversion does not exist, so views never break. `deleteFiles()` is called from the `forceDeleted` event only; a soft delete keeps the files on purpose.
- [UploadController](src/Http/Controllers/UploadController.php) authorizes `view` through the policy, resolves the path (404 for a missing conversion or page), redirects to `temporaryUrl()` for a disk that provides one (S3) unless it is a `LocalFilesystemAdapter`, which is streamed with `response()` or `download()` (`?download=1`). Route model binding excludes the trash, so a trashed upload is a 404.
- [UploadPolicy](src/Policies/UploadPolicy.php): uploader, `user_id` on the owning model, or `is_admin` on the user. The provider registers it only when no policy is registered for `Upload`; a host app's `Gate::policy()` wins either way.
- [PruneUploads](src/Commands/PruneUploads.php) force deletes one model at a time with `chunkById`, never a mass delete, because the files are removed in the model event. The model's `prunable()` carries the same rule for `model:prune`.
- Livewire: [UploadManager](src/Livewire/UploadManager.php) calls `Gate::authorize($ability, $model)` on mount and before every change, but only when a policy exists for the model class; the actions only reach uploads of the model's own collection. [UploadsOverview](src/Livewire/UploadsOverview.php) authorizes `delete` on the upload for delete, restore and force delete. Views are plain Tailwind in `resources/views/livewire/`, no UI library, published with `uploads-views`. Both components are registered in the provider behind `class_exists(Livewire::class)`.

## Testing

- [TestCase](tests/TestCase.php) runs on in-memory SQLite with Testbench's `WithLaravelMigrations` (Laravel's own `users` table), a `products` table from `defineDatabaseMigrations()`, the fixture [Product](tests/Fixtures/Product.php) (uses the trait, implements the interface) and [User](tests/Fixtures/User.php). `setUp()` fakes the `public` and `local` disks; the queue is `sync`, so `addUpload()` runs the job inside the test unless a test calls `Queue::fake()`. Call `->fresh()` after `addUpload()` when you assert on conversions: the job updates its own copy.
- `fakeImage()` draws a PNG with GD and `fakePdf()` writes a valid PDF by hand (objects, xref, trailer), so no fixtures live on disk and no PDF library is needed. Livewire tests must pass `UploadedFile::fake()` instances to `set('files', …)`; Livewire's test helper reads `$file->name`, which only the fake has.
- Named fixture policies ([OpenUploadPolicy](tests/Fixtures/OpenUploadPolicy.php), [ClosedProductPolicy](tests/Fixtures/ClosedProductPolicy.php)) replace the package policy in tests; `Gate::policy()` needs a class name, not an instance.
- [DocsSiteTest](tests/DocsSiteTest.php) also checks that every top level config key is documented in `docs/installation.md` and that the keys are in alphabetical order. Blade examples in `docs/` are written with `<?= … ?>` instead of double curly braces, because Jekyll renders the braces.

## Conventions

- Keep the public API compatible within 1.x: the model and its methods, the trait, the interface, the action signature, the job's `dispatchFor()`, the event, the route name `uploads.show` and its query parameters, the policy abilities `view` and `delete`, the component names and parameters, the config keys and the view names.
- Config keys stay in alphabetical order, in the file and in `UploadsConfig`; nested arrays too.
- End user texts go through `__()`; add every new string to `lang/nl.json`.
