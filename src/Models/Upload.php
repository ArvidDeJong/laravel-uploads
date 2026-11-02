<?php

namespace Darvis\Uploads\Models;

use Darvis\Uploads\Database\Factories\UploadFactory;
use Darvis\Uploads\Jobs\ProcessUpload;
use Darvis\Uploads\Support\UploadsConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;

/**
 * One stored file: where it lives, what it belongs to and what was made from it.
 *
 * @property int $id
 * @property string|null $uploadable_type
 * @property int|null $uploadable_id
 * @property int|null $user_id
 * @property string $collection
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string|null $mime_type
 * @property int $size
 * @property bool $is_public
 * @property int $sort_order
 * @property array<string, mixed>|null $meta
 * @property array<string, mixed>|null $conversions
 * @property Carbon|null $processed_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $uploadable
 * @property-read Model|null $user
 */
class Upload extends Model
{
    /** @use HasFactory<UploadFactory> */
    use HasFactory;

    use Prunable;
    use SoftDeletes;

    /**
     * The key under `conversions` that holds the rendered PDF pages.
     */
    public const PAGES = 'pages';

    /**
     * The key under `meta` that holds the crop area of an image.
     */
    public const CROP = 'crop';

    protected $fillable = [
        'uploadable_type',
        'uploadable_id',
        'user_id',
        'collection',
        'disk',
        'path',
        'name',
        'mime_type',
        'size',
        'is_public',
        'sort_order',
        'meta',
        'conversions',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversions' => 'array',
            'is_public' => 'boolean',
            'meta' => 'array',
            'processed_at' => 'datetime',
            'size' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return UploadFactory
     */
    protected static function newFactory()
    {
        return UploadFactory::new();
    }

    protected static function booted(): void
    {
        // The job saves the conversions on its own copy of the row, so an instance loaded
        // before it ran does not know them. Read them while the row still exists.
        static::forceDeleting(function (Upload $upload): void {
            $stored = static::withTrashed()->whereKey($upload->getKey())->first();

            if ($stored !== null) {
                $upload->conversions = $stored->conversions;
            }
        });

        // A soft delete keeps the files so the upload can be restored; only a
        // hard delete removes the original and everything generated from it.
        static::forceDeleted(function (Upload $upload): void {
            $upload->deleteFiles();
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function uploadable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(app(UploadsConfig::class)->userModel());
    }

    /**
     * @param  Builder<Upload>  $query
     * @return Builder<Upload>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * @param  Builder<Upload>  $query
     * @return Builder<Upload>
     */
    public function scopePrivate(Builder $query): Builder
    {
        return $query->where('is_public', false);
    }

    /**
     * @param  Builder<Upload>  $query
     * @return Builder<Upload>
     */
    public function scopeCollection(Builder $query, string $collection): Builder
    {
        return $query->where('collection', $collection);
    }

    /**
     * @param  Builder<Upload>  $query
     * @return Builder<Upload>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  Builder<Upload>  $query
     * @return Builder<Upload>
     */
    public function scopeImages(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'image/%');
    }

    /**
     * @param  Builder<Upload>  $query
     * @return Builder<Upload>
     */
    public function scopeVideos(Builder $query): Builder
    {
        return $query->where('mime_type', 'like', 'video/%');
    }

    /**
     * @param  Builder<Upload>  $query
     * @return Builder<Upload>
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->whereNotNull('processed_at');
    }

    /**
     * Soft deleted uploads older than `prune_after_days` are hard deleted by `uploads:prune`.
     *
     * @return Builder<Upload>
     */
    public function prunable(): Builder
    {
        $days = app(UploadsConfig::class)->pruneAfterDays();

        return static::onlyTrashed()->where('deleted_at', '<=', now()->subDays($days));
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mime_type, 'video/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /**
     * Whether the processing job has run for this upload.
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * The URL of the original file: permanent for a public upload, a signed link otherwise.
     */
    public function url(?int $minutes = null): string
    {
        return $this->urlFor(null, null, $minutes);
    }

    /**
     * A signed link that downloads the original with its file name instead of showing it.
     * For a public upload this is the permanent URL; the browser decides what to do with it.
     */
    public function downloadUrl(?int $minutes = null): string
    {
        return $this->is_public ? $this->url() : $this->urlFor(null, null, $minutes, download: true);
    }

    /**
     * The path of a generated conversion on the same disk, or null when it is not there (yet).
     */
    public function conversionPath(string $name): ?string
    {
        $path = $this->conversions[$name] ?? null;

        return is_string($path) ? $path : null;
    }

    public function hasConversion(string $name): bool
    {
        return $this->conversionPath($name) !== null;
    }

    /**
     * The URL of a conversion, falling back to the original while the job has not run yet.
     */
    public function conversionUrl(string $name, ?int $minutes = null): string
    {
        return $this->hasConversion($name) ? $this->urlFor($name, null, $minutes) : $this->url($minutes);
    }

    /**
     * Paths of the rendered PDF pages, in page order.
     *
     * @return array<int, string>
     */
    public function pagePaths(): array
    {
        $pages = $this->conversions[self::PAGES] ?? [];

        return is_array($pages) ? array_values(array_filter($pages, 'is_string')) : [];
    }

    public function pageCount(): int
    {
        return count($this->pagePaths());
    }

    /**
     * The URL of one rendered PDF page, counted from 1; null when that page was not rendered.
     */
    public function pageUrl(int $page, ?int $minutes = null): ?string
    {
        if (! isset($this->pagePaths()[$page - 1])) {
            return null;
        }

        return $this->urlFor(null, $page, $minutes);
    }

    /**
     * A thumbnail that always resolves: the conversion when it exists, otherwise the original.
     */
    public function thumbnailUrl(string $conversion = 'thumb', ?int $minutes = null): string
    {
        return $this->conversionUrl($conversion, $minutes);
    }

    /**
     * The file size for people, for example "1.2 MB".
     */
    public function humanSize(): string
    {
        return (string) Number::fileSize($this->size, precision: 1);
    }

    /**
     * Read one key from `meta`, with a default when it is not set.
     */
    public function meta(string $key, mixed $default = null): mixed
    {
        return data_get($this->meta, $key, $default);
    }

    /**
     * The part of the image the conversions are made from, in pixels of the original;
     * null when the whole image is used.
     *
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    public function cropArea(): ?array
    {
        $area = $this->meta(self::CROP);

        return is_array($area) ? static::normalizeCropArea($area) : null;
    }

    /**
     * Keep only this part of the image for the conversions and make them again. The original
     * file is not changed, so null brings back the whole image.
     *
     * @param  array<string, mixed>|null  $area  `x`, `y`, `width` and `height` in pixels of the original.
     */
    public function setCropArea(?array $area): static
    {
        if (! $this->isImage()) {
            throw new \InvalidArgumentException("Upload [{$this->getKey()}] is not an image and cannot be cropped.");
        }

        $normalized = $area === null ? null : static::normalizeCropArea($area);

        if ($area !== null && $normalized === null) {
            throw new \InvalidArgumentException('A crop area needs a numeric x and y of at least 0 and a width and height of at least 1.');
        }

        $meta = $this->meta ?? [];
        data_set($meta, self::CROP, $normalized);
        $this->meta = $meta;
        $this->processed_at = null;
        $this->save();

        ProcessUpload::dispatchFor($this);

        return $this;
    }

    /**
     * Whole pixels, or null when a value is missing or out of range.
     *
     * @param  array<string, mixed>  $area
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    protected static function normalizeCropArea(array $area): ?array
    {
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (! isset($area[$key]) || ! is_numeric($area[$key])) {
                return null;
            }
        }

        $normalized = [
            'x' => (int) round((float) $area['x']),
            'y' => (int) round((float) $area['y']),
            'width' => (int) round((float) $area['width']),
            'height' => (int) round((float) $area['height']),
        ];

        if ($normalized['x'] < 0 || $normalized['y'] < 0 || $normalized['width'] < 1 || $normalized['height'] < 1) {
            return null;
        }

        return $normalized;
    }

    /**
     * Write one key in `meta` and save.
     */
    public function setMeta(string $key, mixed $value): static
    {
        $meta = $this->meta ?? [];
        data_set($meta, $key, $value);
        $this->meta = $meta;
        $this->save();

        return $this;
    }

    /**
     * Remove the original and every generated file from the disk.
     */
    public function deleteFiles(): void
    {
        $disk = Storage::disk($this->disk);

        $paths = array_merge([$this->path], $this->pagePaths());

        foreach ($this->conversions ?? [] as $name => $path) {
            if ($name !== self::PAGES && is_string($path)) {
                $paths[] = $path;
            }
        }

        $disk->delete($paths);
    }

    /**
     * Build the URL for the original, a conversion or a page.
     */
    protected function urlFor(?string $conversion, ?int $page, ?int $minutes, bool $download = false): string
    {
        if ($this->is_public) {
            $path = $conversion !== null
                ? ($this->conversionPath($conversion) ?? $this->path)
                : ($page !== null ? ($this->pagePaths()[$page - 1] ?? $this->path) : $this->path);

            $url = Storage::disk($this->disk)->url($path);

            // A conversion keeps its path when it is made again, after a new crop for example,
            // so the time of processing tells browsers and CDNs that the file changed.
            if ($conversion !== null && $path !== $this->path && $this->processed_at !== null) {
                $url .= (str_contains($url, '?') ? '&' : '?').'v='.$this->processed_at->getTimestamp();
            }

            return $url;
        }

        $parameters = array_filter([
            'upload' => $this->getKey(),
            'conversion' => $conversion,
            'page' => $page,
            'download' => $download ? 1 : null,
        ], fn (mixed $value): bool => $value !== null);

        $minutes ??= app(UploadsConfig::class)->linkExpiresAfterMinutes();

        return URL::temporarySignedRoute('uploads.show', now()->addMinutes($minutes), $parameters);
    }
}
