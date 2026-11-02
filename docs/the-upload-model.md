---
title: "The Upload model"
description: "The uploads table and the Upload model: columns, relations, scopes, URLs, meta data, the HasUploads trait, the StoreUpload action and the UploadOwner interface."
nav_order: 6
---

# The Upload model

## The table

| Column | Meaning |
| --- | --- |
| `uploadable_type`, `uploadable_id` | The model the upload belongs to; both `null` for an upload without owner |
| `user_id` | The user who uploaded it, from `auth()->id()` at the time |
| `collection` | The group the file is in: `photos`, `files`, `attachments`, any name you choose |
| `disk` | The disk the file is on, copied from the config when it was stored |
| `path` | The path on that disk, by default `uploads/<module>/<year>/<month>/<name>.<ext>`; it never changes after the file is stored |
| `name` | The original file name, used for downloads |
| `mime_type`, `size` | As detected when the file was stored; `size` in bytes |
| `is_public` | Which disk it went to and what kind of URL it gets |
| `sort_order` | Display order within its collection, from `0` |
| `meta` | JSON with whatever you keep with the upload |
| `conversions` | JSON with the paths of the generated files: the conversion names, and `pages` for a PDF |
| `processed_at` | When the processing job ran; `null` until then |
| `deleted_at` | Set by a soft delete |

The pair `disk` and `path` is unique.

## Relations and scopes

```php
$upload->uploadable;     // the owning model, or null
$upload->user;           // the uploader, resolved through the user model of the default auth provider

Upload::public();        // or ->private()
Upload::collection('photos');
Upload::ordered();       // sort_order, then id
Upload::images();        // mime type image/*
Upload::processed();     // the job has run
Upload::withTrashed();   // the usual soft delete scopes
```

## URLs

| Method | Returns |
| --- | --- |
| `url()` | The original: the disk URL for a public upload, a signed link for a private one |
| `downloadUrl()` | The same, but a private link forces a download with the original name |
| `conversionUrl('thumb')` | The conversion, or the original while it does not exist yet |
| `thumbnailUrl()` | `conversionUrl('thumb')` |
| `pageUrl(2)` | A rendered PDF page, or `null` when that page was not rendered |

Every method takes an optional `$minutes` that overrides `link_expires_after_minutes` for a signed link. Public uploads ignore it.

## Type and state

```php
$upload->isImage();      // image/*
$upload->isVideo();      // video/*
$upload->isPdf();        // application/pdf
$upload->isProcessed();  // processed_at is set
$upload->hasConversion('thumb');
$upload->conversionPath('thumb');   // path on the disk, or null
$upload->pagePaths();               // paths of the PDF pages
$upload->pageCount();
$upload->humanSize();               // "1.2 MB"
```

## Meta data

`meta` is a JSON column for everything a project wants to keep with a file: a caption, print settings, the source of a generated image, an approval flag. Read and write it through dot keys:

```php
$upload = $product->addUpload($file, 'photos', meta: ['caption' => 'Front', 'source' => 'phone']);

$upload->meta('caption');                  // Front
$upload->meta('print.layer_height', 0.2);  // default when the key is missing
$upload->setMeta('approved', true);        // writes and saves
```

Add a real column in your own migration only when you have to filter or sort on the value; everything else belongs in `meta`.

## The HasUploads trait

```php
$product->uploads();                  // MorphMany, ordered, without the trash
$product->uploadsIn('photos');        // one collection
$product->firstUpload('photos');      // the cover photo, or null
$product->addUpload($file, 'photos', public: true, meta: []);
$product->reorderUploads('photos', [12, 7, 9]);   // ids not in the list keep their place after these
```

`Darvis\Uploads\Contracts\UploadOwner` declares the same methods. Implement it on the model when you want to type hint "a model with uploads"; the trait satisfies it and the Livewire upload manager is annotated with it.

## The StoreUpload action

`addUpload()` calls `Darvis\Uploads\Actions\StoreUpload`. Use the action directly for an upload without an owner, or to set the uploader yourself in a job where nobody is logged in:

```php
use Darvis\Uploads\Actions\StoreUpload;

$upload = app(StoreUpload::class)->handle(
    $file,
    collection: 'attachments',
    public: false,
    meta: ['from' => 'contact form'],
    owner: null,
    userId: $customer->id,
);
```

The action chooses the disk from `is_public`, builds the directory (`uploads/<collection>` without an owner), stores the file with `storeAs()`, which streams it so a Livewire temporary file on S3 is copied bucket to bucket, writes the row and dispatches `Darvis\Uploads\Jobs\ProcessUpload`.

## Events

`Darvis\Uploads\Events\UploadProcessed` is dispatched when the job has written the conversions and pages, also for a file that gets none (`processed_at` is set anyway). Listen to it to react once a thumbnail exists, for example to send the photo to an image model or to notify someone.
