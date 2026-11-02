---
title: "Administration & trash"
description: "Soft delete, restore, hard delete and the uploads:prune command; what happens to the files at each step; UploadStatistics for totals per disk and collection; building your own overview."
nav_order: 11
---

# Administration & trash

## Soft delete, restore, hard delete

| Call | Row | Files |
| --- | --- | --- |
| `$upload->delete()` | `deleted_at` set; gone from `uploads()`, the route (404) and the statistics | kept |
| `$upload->restore()` | back | untouched |
| `$upload->forceDelete()` | removed | the original, every conversion and every page are removed from the disk |

The Livewire upload manager and the overview use `delete()`; only the trash view of the overview offers "Delete for good". Deleting the owning model does **not** touch its uploads: add it to the model's `deleting` event when you want that, with `$product->uploads()->get()->each->delete()` (trash) or `->each->forceDelete()` (gone).

## Pruning the trash

```bash
php artisan uploads:prune            # older than prune_after_days (30)
php artisan uploads:prune --days=7
```

The command force deletes, one upload at a time so the files are removed too, everything that has been in the trash longer than the given days. Schedule it:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('uploads:prune')->daily();
```

The model also implements Laravel's `Prunable` with the same rule, so `php artisan model:prune --model="Darvis\Uploads\Models\Upload"` does the same thing.

## Statistics

`Darvis\Uploads\Support\UploadStatistics` gives the numbers for a dashboard:

```php
$statistics = app(UploadStatistics::class);

$statistics->total();          // ['files' => 120, 'bytes' => 734003200]
$statistics->trashed();        // the same for the trash
$statistics->perDisk();        // [['disk' => 'public', 'files' => 80, 'bytes' => …], …]
$statistics->perCollection();  // [['collection' => 'photos', 'files' => 70, 'bytes' => …], …]
```

The trash is left out of `total()`, `perDisk()` and `perCollection()`. Format the bytes with `Illuminate\Support\Number::fileSize($bytes, precision: 1)`, as the overview does.

## Your own overview

The overview component is a starting point; a project with its own table component only needs the query:

```php
Upload::query()
    ->with(['uploadable', 'user'])
    ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
    ->when($collection, fn ($q) => $q->collection($collection))
    ->latest('id')
    ->paginate(25);
```

`$upload->uploadable` is the owning model, so `class_basename($upload->uploadable)` and `$upload->uploadable->getKey()` tell what the file belongs to. Eager load it, or every row runs a query.
