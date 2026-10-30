---
title: "Livewire components"
description: "The upload manager (upload, caption, drag to sort, remove for one collection) and the uploads overview (totals, filters, trash): parameters, authorization, events, publishing the views and direct uploads to S3."
nav_order: 9
---

# Livewire components

Both components are registered only when `livewire/livewire` (3.6 or newer, or 4) is installed. Their views use plain Tailwind classes and no UI library, so they fit any Tailwind project and can be published and restyled:

```bash
php artisan vendor:publish --tag=uploads-views
```

The texts go through `__()` with a Dutch translation in the package; set the app locale to `nl` for Dutch labels.

## Upload manager

```blade
<livewire:uploads.upload-manager :model="$product" collection="photos" :public="true" accept="jpg,jpeg,png,webp" />
```

| Parameter | Default | Meaning |
| --- | --- | --- |
| `model` | required | The model with the `HasUploads` trait |
| `collection` | `files` | Which collection the component manages |
| `public` | `false` | Whether new uploads go to the public disk |
| `accept` | empty | Comma separated extensions; used for the file input and the `extensions` validation rule |
| `ability` | `update` | Ability checked on the model before anything changes; empty string to skip |

What it does:

- A file input (multiple) and an upload button. Files are validated with `file|max:<max_kilobytes>` plus `extensions:` when `accept` is set, then stored with `addUpload()`.
- The list of the collection, in display order, with a thumbnail for images and PDFs, a video preview for videos and the extension for other files, plus name, size, "Processing" while the job has not run, and the page count of a PDF.
- A caption field per upload, saved to `meta.caption` on blur.
- Drag and drop sorting with Alpine's Sort plugin, which Livewire bundles; the new order is saved with `reorderUploads()`.
- A remove button that soft deletes the upload after a confirm.
- It polls every five seconds while an upload in the list is not processed, so the thumbnails appear without a reload.
- It dispatches the browser event `uploads-changed` with the collection after an upload or a removal, for a parent that shows a count or a cover.

### Authorization

On mount and before every change the component calls `Gate::authorize($ability, $model)`, but only when a policy is registered for the model's class. Without a policy, anyone who can reach the page can upload, so put the component on pages that are already behind your own middleware, or register a policy for the model. The caption, reorder and delete actions only touch uploads of the model's own collection; an id from another model is ignored.

## Uploads overview

```blade
<livewire:uploads.uploads-overview />
```

The administration of every upload in the application:

- Four cards: total files and bytes, the trash, per disk and per collection, from `UploadStatistics`.
- Filters on file name, collection and disk, and a checkbox that switches to the trash. The filters are in the URL, so a filtered view can be bookmarked.
- A table with thumbnail, name, collection, the owning model (`Product #12`), size, disk and visibility, uploader, date.
- Per row: open (a signed link) and remove (soft delete); in the trash: restore and delete for good (hard delete, files included).

Every delete, restore and hard delete authorizes `delete` on the upload through `UploadPolicy`, so by default only the uploader, the owner of the model and an administrator may remove a file. Put the component behind your admin middleware; it has no further access check of its own.

## Direct uploads to S3

Livewire stores a picked file in a temporary location before your component sees it. With the temporary disk on S3, the browser uploads straight to the bucket through a presigned URL and your server never touches the bytes, which is the only sensible way to accept a 400 MB video:

```php
// config/livewire.php
'temporary_file_upload' => [
    'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
    'directory' => 'livewire-tmp',
    'rules' => 'file|max:512000',
],
```

```
LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=s3_private
```

Use the private bucket, so half uploaded files are never publicly readable. The bucket then needs a CORS rule for `PUT` and `POST` from your site's origin and a lifecycle rule that removes `livewire-tmp/` after a day. `StoreUpload` copies the temporary file to its final place with `storeAs()`, which streams from bucket to bucket.

Leave the variable empty locally; Livewire then uses the local disk and the usual `upload_max_filesize` and `post_max_size` of PHP apply.
