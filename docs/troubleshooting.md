---
title: "Troubleshooting"
description: "Error messages and symptoms with cause and fix: no thumbnails, PdfRenderingUnavailable, 403 on a private link, a broken public image, ACL errors on S3, Livewire upload problems."
nav_order: 10
---

# Troubleshooting

Each entry is a symptom, the cause and the fix. The messages are quoted as the code writes them, so you can search for them.

## Storing

### Upload [photo.jpg] could not be stored on disk [s3].

A `RuntimeException` from `StoreUpload`: the disk returned `false` for `storeAs()`. The credentials, the bucket or the region are wrong, or the bucket refuses the request. Set `'throw' => true` on the disk in `config/filesystems.php` to get the real S3 error instead.

### AccessControlListNotSupported: The bucket does not allow ACLs

Your disk config sends `'visibility' => 'public'` or an `ACL` option. Buckets created since 2023 have ACLs turned off. Remove the visibility and ACL settings and give the public bucket a bucket policy with `s3:GetObject` for everyone.

### no such table: uploads, or "Base table or view not found"

The migration has not run. Run `php artisan migrate`; the migration is loaded from the package, there is nothing to publish.

### Integrity constraint violation: UNIQUE constraint failed: uploads.disk, uploads.path

Two rows with the same disk and path. That only happens when you create `Upload` rows yourself with a path that already exists; let `StoreUpload` build the path, it uses a uuid.

## Conversions

### No thumbnails, `conversions` stays null and `processed_at` is null

The job has not run. With a queue connection other than `sync` a worker has to be running: `php artisan queue:work`. Look in `failed_jobs` when the worker runs and the column stays empty.

### Unable to decode input

An Intervention Image exception in the job: the file is not an image GD can read, although its mime type says `image/…`. Typical for a HEIC photo from an iPhone or an SVG. GD reads JPEG, PNG, GIF, WebP, BMP and AVIF; convert HEIC in the browser or on the phone, and leave SVG files out of the conversions by storing them in a collection you do not show through `thumbnailUrl()`.

### Conversion format [gif] is not supported; use jpg, png or webp.

An `InvalidArgumentException` from `ImageConverter`: a conversion in the config has a format the converter does not write. Use `jpg`, `png` or `webp`.

### The thumbnail is the full size image

`conversionUrl()` falls back to the original while the conversion does not exist. Either the job has not run yet (see above), or the conversion name is not in the config. Check `$upload->conversions`.

### A new conversion is missing for old uploads

Conversions are made when the upload is stored. Dispatch the job again for existing uploads, see [Adding a conversion later](conversions-and-pdf.md#adding-a-conversion-later).

## PDF

### No PDF driver is available: install the Imagick extension with Ghostscript, or poppler-utils for the pdftoppm binary.

`Darvis\Uploads\Exceptions\PdfRenderingUnavailable` from the job. Install one of the two, or set `UPLOADS_PDFTOPPM_PATH` to the absolute path of `pdftoppm` when it is installed but not in the `PATH` of the PHP process (MAMP on a Mac: `/opt/homebrew/bin/pdftoppm`). The upload itself is stored and can be downloaded; only the pages and the thumbnail are missing. Dispatch `ProcessUpload::dispatchFor($upload)` once the driver is there.

### The process "pdftoppm …" exceeded the timeout of 300 seconds.

A huge or broken PDF. Lower `pdf.max_pages` or `pdf.dpi`, or check the file with `pdfinfo`.

### attempt to perform an operation not allowed by the security policy `PDF'

Imagick refuses PDF files because of ImageMagick's policy. Edit `/etc/ImageMagick-6/policy.xml` (or `-7`) and change the `PDF` rights to `read|write`, or switch to `UPLOADS_PDF_DRIVER=pdftoppm`.

## Links

### 403 on a private link

Three causes, in order of likelihood:

1. The link expired. `link_expires_after_minutes` counts from the moment `url()` was called; a link rendered into a page that stays open for an hour is dead. Render the link when it is clicked, or raise the minutes.
2. The policy refused. The default allows the uploader, the owner of the model (`user_id`) and an administrator (`is_admin`), and never a guest. Replace the policy, see [Private files](private-files.md#who-may-open-a-private-upload).
3. The signature is invalid because `APP_URL` differs from the address in the browser. The signed URL is computed over the full address, so set `APP_URL` to what people use.

### 404 on a private link

The upload is in the trash, or the `conversion` or `page` in the link does not exist for this upload.

### A public image shows as broken

The public disk is not reachable from the browser. For the `public` disk run `php artisan storage:link` and check that `APP_URL` is right, because `Storage::url()` builds on it. For S3 the public bucket needs a bucket policy that allows `s3:GetObject` for everyone, and `AWS_URL` when you serve through a CDN.

## Livewire

### The upload fails silently and the file is not in the list

Livewire's temporary upload failed. Open the browser console: a 413 means the file is above `upload_max_filesize` or `post_max_size` in PHP, or above `temporary_file_upload.rules` in `config/livewire.php`; a CORS error with S3 means the bucket of `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` has no CORS rule for your origin.

### The files.0 field must be a file of type: jpg, png.

The `accept` parameter of the upload manager rejected the extension. Add it, or remove the parameter to accept any file.

### This action is unauthorized.

The upload manager checked `$ability` (`update`) on the model with the model's policy and it refused. Log in as a user who may update the model, or pass `ability=""` when the page is guarded by middleware instead.

### Undefined property: Illuminate\Http\UploadedFile::$name

In a test: `Livewire::test()->set('files', …)` needs `UploadedFile::fake()` instances, not a plain `UploadedFile`.
