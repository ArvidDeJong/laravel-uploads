---
title: "Private files"
description: "How a private upload is served: the signed route uploads.show, the UploadPolicy and how to replace it, downloads, conversions of private files, and the S3 setup with two buckets."
nav_order: 6
---

# Private files

A private upload (`public: false`, the default) is stored on the private disk, which nobody can reach directly: a local disk is outside the web root, an S3 bucket has no public read. The only way in is the route of the package.

## The route

`GET /uploads/{upload}`, named `uploads.show`, with the middleware from `route_middleware` (`web`) plus `signed`. Every link to it is a temporary signed URL, so the id in the address cannot be changed and the link stops working after `link_expires_after_minutes` (30):

```php
$upload->url();                 // shows the file in the browser
$upload->downloadUrl();         // forces a download with the original name (?download=1)
$upload->conversionUrl('thumb'); // ?conversion=thumb
$upload->pageUrl(2);            // ?page=2
$upload->url(minutes: 5);       // a shorter life for this one link
```

The controller authorizes `view` on the upload, finds the path (original, conversion or page, 404 for one that does not exist) and then either streams the file through PHP, or redirects to a temporary URL of the disk when the disk provides one, as S3 does. The redirect is valid for `temporary_url_minutes` (5). An upload in the trash is a 404.

## Who may open a private upload

`Darvis\Uploads\Policies\UploadPolicy::view()` allows, for a logged in user:

1. the uploader (`uploads.user_id`),
2. a user whose id equals the `user_id` attribute of the model the upload belongs to,
3. a user with a truthy `is_admin` attribute.

A guest is always refused, and so is everybody else. The same rule is used for `delete` by the overview component.

Register your own policy in a service provider when your rules differ; the package only registers its policy when none is registered for `Upload` yet, and Laravel keeps the last registration anyway:

```php
use Darvis\Uploads\Models\Upload;
use Illuminate\Support\Facades\Gate;

Gate::policy(Upload::class, \App\Policies\UploadPolicy::class);
```

```php
class UploadPolicy
{
    public function view(?User $user, Upload $upload): bool
    {
        return $upload->uploadable instanceof Inquiry
            && $user?->id === $upload->uploadable->user_id;
    }

    public function delete(?User $user, Upload $upload): bool
    {
        return (bool) $user?->is_admin;
    }
}
```

A policy that returns `true` for `view` makes possession of the signed link enough, which is what you want for a link in a mail to a customer who has no account. Combine it with a short `$minutes` on the link.

## S3

Use two buckets per environment, one public and one private, with an IAM user that may only read, write and delete in those two. In `config/filesystems.php` add a second S3 disk for the private bucket:

```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'throw' => true,
],

's3_private' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_PRIVATE_BUCKET'),
    'throw' => true,
],
```

```
UPLOADS_PUBLIC_DISK=s3
UPLOADS_PRIVATE_DISK=s3_private
```

Do not send an ACL: buckets created today have ACLs turned off and refuse `public-read`, and the upload then fails. Public read on the public bucket is a bucket policy, not an ACL. The `throw => true` makes a failed upload throw instead of returning `false`, which the package would turn into a `RuntimeException` anyway.

For the private bucket the download route redirects to a presigned S3 URL, so the file never passes through your server. The Livewire upload manager can send files straight from the browser to S3 as well; see [Livewire components](livewire.md#direct-uploads-to-s3).

## Serving public files through the route

Sometimes a public file has to be downloaded with its original name. `downloadUrl()` on a public upload is the plain disk URL, because nothing has to be checked for a public file. The route serves public uploads too, so build a signed link yourself when you need the download header:

```php
URL::temporarySignedRoute('uploads.show', now()->addMinutes(5), ['upload' => $upload, 'download' => 1]);
```

The policy applies as usual.
