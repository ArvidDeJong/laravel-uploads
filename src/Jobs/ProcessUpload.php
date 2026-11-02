<?php

namespace Darvis\Uploads\Jobs;

use Darvis\Uploads\Events\UploadProcessed;
use Darvis\Uploads\Exceptions\UnreadableFile;
use Darvis\Uploads\Models\Upload;
use Darvis\Uploads\Processing\ImageConverter;
use Darvis\Uploads\Processing\PdfRasterizer;
use Darvis\Uploads\Support\UploadsConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generate the configured conversions of an image and the page images of a PDF.
 *
 * Everything generated is written next to the original on the same disk, so
 * a private upload keeps its conversions private.
 */
class ProcessUpload implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Upload $upload,
    ) {}

    /**
     * Dispatch on the connection and queue from the config.
     */
    public static function dispatchFor(Upload $upload): void
    {
        $config = app(UploadsConfig::class);

        $job = new self($upload);

        if ($config->queueConnection() !== null) {
            $job->onConnection($config->queueConnection());
        }

        if ($config->queueName() !== null) {
            $job->onQueue($config->queueName());
        }

        dispatch($job);
    }

    public function handle(UploadsConfig $config, ImageConverter $converter, PdfRasterizer $rasterizer): void
    {
        $upload = $this->upload->fresh();

        // The upload was hard deleted while the job waited; nothing to do.
        if ($upload === null) {
            return;
        }

        if (! $upload->isImage() && ! $upload->isPdf()) {
            $upload->update(['processed_at' => now()]);
            UploadProcessed::dispatch($upload);

            return;
        }

        $disk = Storage::disk($upload->disk);
        $workDirectory = $this->makeWorkDirectory($upload);

        try {
            $original = $this->downloadOriginal($disk, $upload, $workDirectory);
            $generated = [];

            $source = $original;

            try {
                if ($upload->isPdf()) {
                    $pages = $rasterizer->render($original, $workDirectory, $config->pdfMaxPages());
                    $generated[Upload::PAGES] = $this->storePages($disk, $upload, $pages);
                    $source = $pages[0] ?? null;
                }

                // The crop area is measured on the image itself, so it does not apply to PDF pages.
                $crop = $upload->isImage() ? $upload->cropArea() : null;

                if ($source !== null) {
                    foreach ($config->conversions() as $name => $conversion) {
                        [$binary, $extension] = $converter->make($source, $crop === null ? $conversion : $conversion + ['crop' => $crop]);

                        $path = $this->generatedPath($upload, (string) $name, $extension);
                        $disk->put($path, $binary);

                        $generated[(string) $name] = $path;
                    }
                }
            } catch (UnreadableFile $exception) {
                // A damaged image or PDF stays damaged, so a retry would only fail again. The upload
                // is marked as processed without conversions and the views show the original.
                Log::warning("Upload [{$upload->getKey()}] cannot be processed: {$exception->getMessage()}", [
                    'disk' => $upload->disk,
                    'path' => $upload->path,
                ]);
            }

            $upload->update([
                'conversions' => $generated === [] ? null : $generated,
                'processed_at' => now(),
            ]);
        } finally {
            File::deleteDirectory($workDirectory);
        }

        UploadProcessed::dispatch($upload);
    }

    /**
     * Copy the original to a local file, because the converters read from disk.
     */
    protected function downloadOriginal(Filesystem $disk, Upload $upload, string $directory): string
    {
        $extension = pathinfo($upload->path, PATHINFO_EXTENSION);
        $local = $directory.'/original'.($extension === '' ? '' : '.'.$extension);

        $stream = $disk->readStream($upload->path);

        if (! is_resource($stream)) {
            throw new \RuntimeException("Upload [{$upload->path}] is missing from disk [{$upload->disk}].");
        }

        $target = fopen($local, 'w');

        if ($target === false) {
            throw new \RuntimeException("Unable to write temporary file [{$local}].");
        }

        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);

        return $local;
    }

    /**
     * Store the rendered pages next to the original and return their paths in page order.
     *
     * @param  array<int, string>  $localPages
     * @return array<int, string>
     */
    protected function storePages(Filesystem $disk, Upload $upload, array $localPages): array
    {
        $stored = [];

        foreach ($localPages as $index => $local) {
            $extension = pathinfo($local, PATHINFO_EXTENSION);
            $path = $this->generatedPath($upload, 'page-'.($index + 1), $extension);

            $stream = fopen($local, 'r');

            if ($stream === false) {
                throw new \RuntimeException("Unable to read rendered page [{$local}].");
            }

            $disk->put($path, $stream);
            fclose($stream);

            $stored[] = $path;
        }

        return $stored;
    }

    /**
     * `<folder of the original>/<basename>-<suffix>.<extension>`.
     */
    protected function generatedPath(Upload $upload, string $suffix, string $extension): string
    {
        $directory = pathinfo($upload->path, PATHINFO_DIRNAME);
        $basename = pathinfo($upload->path, PATHINFO_FILENAME);

        return ($directory === '.' ? '' : $directory.'/').$basename.'-'.$suffix.'.'.$extension;
    }

    protected function makeWorkDirectory(Upload $upload): string
    {
        $directory = sys_get_temp_dir().'/laravel-uploads-'.$upload->getKey().'-'.uniqid();

        File::ensureDirectoryExists($directory);

        return $directory;
    }
}
