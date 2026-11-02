<?php

namespace Darvis\Uploads\Actions;

use Darvis\Uploads\Jobs\ProcessUpload;
use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Support\UploadsConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Store a file on the right disk, record it as an Upload and queue the processing.
 */
class StoreUpload
{
    public function __construct(
        protected UploadsConfig $config,
    ) {}

    /**
     * @param  array<string, mixed>  $meta  Extra data kept with the upload, such as a caption.
     */
    public function handle(
        UploadedFile $file,
        string $collection = 'files',
        bool $public = false,
        array $meta = [],
        ?Model $owner = null,
        ?int $userId = null,
    ): Upload {
        $disk = $this->config->diskFor($public);
        $directory = $this->directoryFor($owner, $collection);
        $filename = $this->filenameFor($file->getClientOriginalName(), $this->extensionOf($file), $disk, $directory);

        // storeAs streams the file to the disk, so a Livewire temporary file on S3 is copied
        // without passing through the web server's memory.
        $path = $file->storeAs($directory, $filename, ['disk' => $disk]);

        if (! is_string($path)) {
            throw new \RuntimeException("Upload [{$file->getClientOriginalName()}] could not be stored on disk [{$disk}].");
        }

        return $this->record($owner, $userId, $collection, $disk, $path, $file->getClientOriginalName(), $file->getMimeType() ?: $file->getClientMimeType(), (int) $file->getSize(), $public, $meta);
    }

    /**
     * Store file contents that did not come from a form, such as a generated image or a
     * downloaded PDF. The name is the original file name, its extension is kept.
     *
     * @param  array<string, mixed>  $meta  Extra data kept with the upload, such as a caption.
     */
    public function handleContents(
        string $contents,
        string $name,
        string $collection = 'files',
        bool $public = false,
        array $meta = [],
        ?Model $owner = null,
        ?int $userId = null,
        ?string $mimeType = null,
    ): Upload {
        $disk = $this->config->diskFor($public);
        $directory = $this->directoryFor($owner, $collection);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $filename = $this->filenameFor($name, $extension === '' ? '' : '.'.$extension, $disk, $directory);
        $path = ($directory === '' ? '' : $directory.'/').$filename;

        if (! Storage::disk($disk)->put($path, $contents)) {
            throw new \RuntimeException("Upload [{$name}] could not be stored on disk [{$disk}].");
        }

        $mimeType ??= (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: null;

        return $this->record($owner, $userId, $collection, $disk, $path, $name, $mimeType, strlen($contents), $public, $meta);
    }

    /**
     * Create the row for a stored file and queue its processing.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function record(
        ?Model $owner,
        ?int $userId,
        string $collection,
        string $disk,
        string $path,
        string $name,
        ?string $mimeType,
        int $size,
        bool $public,
        array $meta,
    ): Upload {
        $upload = Upload::create([
            'uploadable_type' => $owner?->getMorphClass(),
            'uploadable_id' => $owner?->getKey(),
            'user_id' => $userId ?? auth()->id(),
            'collection' => $collection,
            'disk' => $disk,
            'path' => $path,
            'name' => $name,
            'mime_type' => $mimeType,
            'size' => $size,
            'is_public' => $public,
            'sort_order' => $this->nextSortOrder($owner, $collection),
            'meta' => $meta === [] ? null : $meta,
        ]);

        ProcessUpload::dispatchFor($upload);

        return $upload;
    }

    /**
     * The prefix followed by the path pattern, by default `uploads/<module>/<year>/<month>`.
     */
    protected function directoryFor(?Model $owner, string $collection): string
    {
        $collection = Str::slug($collection) ?: 'files';
        $now = now();

        $pattern = strtr($this->config->pathPattern(), [
            '{module}' => $owner ? $this->moduleOf($owner) : $collection,
            '{id}' => $owner ? Str::slug((string) $owner->getKey()) : '',
            '{collection}' => $collection,
            '{year}' => $now->format('Y'),
            '{month}' => $now->format('m'),
            '{day}' => $now->format('d'),
        ]);

        $segments = array_filter(
            [$this->config->pathPrefix(), ...explode('/', $pattern)],
            fn (string $segment): bool => $segment !== '',
        );

        return implode('/', $segments);
    }

    /**
     * The folder name of the owner: what its `uploadsModule()` returns, or the model name in plural.
     */
    protected function moduleOf(Model $owner): string
    {
        $module = method_exists($owner, 'uploadsModule')
            ? (string) $owner->uploadsModule()
            : Str::plural(Str::kebab(class_basename($owner)));

        return Str::slug($module) ?: 'uploads';
    }

    /**
     * A file name that is free in the directory, with the given extension (dot included).
     */
    protected function filenameFor(string $originalName, string $extension, string $disk, string $directory): string
    {
        $original = $this->config->fileNameStrategy() === 'original' ? $this->originalBasename($originalName) : '';
        $basename = $original !== '' ? $original : $this->randomBasename();

        for ($number = 2; $this->isTaken($disk, $directory, $basename, $extension); $number++) {
            $basename = $original !== '' ? $this->numberedBasename($original, $number) : $this->randomBasename();
        }

        return $basename.$extension;
    }

    /**
     * The original name without extension as a slug, cut to the maximum length after the last
     * whole word when there is one; empty when nothing is left.
     */
    protected function originalBasename(string $originalName): string
    {
        $slug = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $length = $this->config->fileNameMaxLength();

        if (strlen($slug) <= $length) {
            return $slug;
        }

        $cut = substr($slug, 0, $length);

        if ($slug[$length] !== '-' && str_contains($cut, '-')) {
            $cut = substr($cut, 0, (int) strrpos($cut, '-'));
        }

        return rtrim($cut, '-');
    }

    protected function randomBasename(): string
    {
        return Str::lower(Str::random($this->config->fileNameMaxLength()));
    }

    /**
     * `<basename>-<number>`, with the basename shortened so the whole stays within the maximum length.
     */
    protected function numberedBasename(string $basename, int $number): string
    {
        $suffix = '-'.$number;
        $room = max(1, $this->config->fileNameMaxLength() - strlen($suffix));

        return rtrim(substr($basename, 0, $room), '-').$suffix;
    }

    /**
     * Whether the basename is in use in the directory, by any upload with any extension (the
     * trash included, its files are still there) or by a file on the disk, or looks like the
     * name of a generated file. The processing job names conversions `<basename>-<name>.<ext>`
     * regardless of the original extension, so `photo.jpg` and `photo.png` must not share a folder.
     */
    protected function isTaken(string $disk, string $directory, string $basename, string $extension): bool
    {
        $suffixes = ['page-\d+'];
        foreach (array_keys($this->config->conversions()) as $name) {
            $suffixes[] = preg_quote((string) $name, '/');
        }

        if (preg_match('/-('.implode('|', $suffixes).')$/', $basename) === 1) {
            return true;
        }

        $path = ($directory === '' ? '' : $directory.'/').$basename;

        return Upload::withTrashed()
            ->where('disk', $disk)
            ->where(fn ($query) => $query->where('path', $path)->orWhere('path', 'like', $path.'.%'))
            ->exists()
            || Storage::disk($disk)->exists($path.$extension);
    }

    /**
     * The lower-cased extension with its dot, or an empty string when the file has none.
     */
    protected function extensionOf(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());

        return $extension === '' ? '' : '.'.$extension;
    }

    /**
     * One past the highest position in the collection, so a new upload comes last even after
     * an upload in the middle was deleted and the positions have a gap.
     */
    protected function nextSortOrder(?Model $owner, string $collection): int
    {
        if ($owner === null) {
            return 0;
        }

        $highest = Upload::query()
            ->where('uploadable_type', $owner->getMorphClass())
            ->where('uploadable_id', $owner->getKey())
            ->collection($collection)
            ->max('sort_order');

        return $highest === null ? 0 : (int) $highest + 1;
    }
}
