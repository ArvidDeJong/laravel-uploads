<?php

namespace Darvis\Uploads\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;

/**
 * The only place in the package that reads the `uploads` config.
 *
 * Every consumer asks this class instead of calling config() itself, so a
 * renamed or defaulted key is changed in one spot and the analyser sees the
 * types.
 */
class UploadsConfig
{
    public function __construct(
        protected Repository $config,
    ) {}

    /**
     * Named image conversions, keyed by name.
     *
     * @return array<string, array{fit?: string, format?: string, height?: int|null, quality?: int, width?: int|null}>
     */
    public function conversions(): array
    {
        return (array) $this->config->get('uploads.conversions', []);
    }

    /**
     * One conversion by name, or null when it is not configured.
     *
     * @return array{fit?: string, format?: string, height?: int|null, quality?: int, width?: int|null}|null
     */
    public function conversion(string $name): ?array
    {
        return $this->conversions()[$name] ?? null;
    }

    /**
     * URL of the Cropper.js script; null when the host app loads it itself.
     */
    public function cropperScript(): ?string
    {
        $url = $this->config->get('uploads.cropper.script');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * URL of the Cropper.js stylesheet; null when the host app loads it itself.
     */
    public function cropperStyle(): ?string
    {
        $url = $this->config->get('uploads.cropper.style');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The disk for uploads that are only reachable through the signed route.
     */
    public function privateDisk(): string
    {
        return (string) $this->config->get('uploads.disks.private', 'local');
    }

    /**
     * The disk for uploads that get a permanent public URL.
     */
    public function publicDisk(): string
    {
        return (string) $this->config->get('uploads.disks.public', 'public');
    }

    /**
     * The disk that belongs to the given visibility.
     */
    public function diskFor(bool $public): string
    {
        return $public ? $this->publicDisk() : $this->privateDisk();
    }

    /**
     * The longest stored file name in characters, without the extension; at least 8.
     */
    public function fileNameMaxLength(): int
    {
        return max(8, (int) $this->config->get('uploads.file_names.max_length', 20));
    }

    /**
     * `original` for the original name as a slug, `random` for a random name.
     */
    public function fileNameStrategy(): string
    {
        return (string) $this->config->get('uploads.file_names.strategy', 'original') === 'random' ? 'random' : 'original';
    }

    /**
     * Minutes a signed download link stays valid.
     */
    public function linkExpiresAfterMinutes(): int
    {
        return (int) $this->config->get('uploads.link_expires_after_minutes', 30);
    }

    /**
     * The largest file the Livewire upload manager accepts, in kilobytes.
     */
    public function maxKilobytes(): int
    {
        return (int) $this->config->get('uploads.max_kilobytes', 512000);
    }

    /**
     * Folders below the prefix, with the placeholders `{module}`, `{id}`, `{collection}`,
     * `{year}`, `{month}` and `{day}`.
     */
    public function pathPattern(): string
    {
        return trim((string) $this->config->get('uploads.path_pattern', '{module}/{year}/{month}'), '/');
    }

    /**
     * Folder on the disk under which every upload is stored; empty for the disk root.
     */
    public function pathPrefix(): string
    {
        return trim((string) $this->config->get('uploads.path_prefix', 'uploads'), '/');
    }

    public function pdfDpi(): int
    {
        return (int) $this->config->get('uploads.pdf.dpi', 110);
    }

    /**
     * `imagick`, `pdftoppm` or `auto`.
     */
    public function pdfDriver(): string
    {
        return (string) $this->config->get('uploads.pdf.driver', 'auto');
    }

    /**
     * Image format of the rendered pages: `jpg` or `png`.
     */
    public function pdfFormat(): string
    {
        return (string) $this->config->get('uploads.pdf.format', 'jpg');
    }

    /**
     * Pages rendered per PDF at most; 0 renders none.
     */
    public function pdfMaxPages(): int
    {
        return (int) $this->config->get('uploads.pdf.max_pages', 50);
    }

    public function pdftoppmPath(): string
    {
        return (string) $this->config->get('uploads.pdf.pdftoppm_path', 'pdftoppm');
    }

    public function pdfQuality(): int
    {
        return (int) $this->config->get('uploads.pdf.quality', 85);
    }

    /**
     * Days a soft deleted upload stays in the trash before `uploads:prune` removes it.
     */
    public function pruneAfterDays(): int
    {
        return (int) $this->config->get('uploads.prune_after_days', 30);
    }

    /**
     * Queue connection for the processing job; null for the default connection.
     */
    public function queueConnection(): ?string
    {
        $connection = $this->config->get('uploads.queue.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * Queue name for the processing job; null for the default queue.
     */
    public function queueName(): ?string
    {
        $queue = $this->config->get('uploads.queue.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    /**
     * @return array<int, string>
     */
    public function routeMiddleware(): array
    {
        return (array) $this->config->get('uploads.route_middleware', ['web']);
    }

    public function routePrefix(): string
    {
        return (string) $this->config->get('uploads.route_prefix', 'uploads');
    }

    /**
     * Minutes a temporary URL from the disk itself stays valid.
     */
    public function temporaryUrlMinutes(): int
    {
        return (int) $this->config->get('uploads.temporary_url_minutes', 5);
    }

    /**
     * The user model of the host app, from the default auth provider.
     *
     * @return class-string<Model>
     */
    public function userModel(): string
    {
        $provider = (string) $this->config->get('auth.guards.web.provider', 'users');
        $model = $this->config->get("auth.providers.{$provider}.model");

        /** @var class-string<Model> */
        return is_string($model) ? $model : 'App\\Models\\User';
    }
}
