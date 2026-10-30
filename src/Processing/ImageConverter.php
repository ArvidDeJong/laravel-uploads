<?php

namespace Darvis\Uploads\Processing;

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Make one configured conversion of an image file with Intervention Image on GD.
 */
class ImageConverter
{
    /**
     * Formats a conversion may ask for, mapped to the file extension that is written.
     */
    public const FORMATS = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'];

    public function __construct(
        protected ?ImageManager $manager = null,
    ) {
        $this->manager ??= ImageManager::gd();
    }

    /**
     * Resize and encode a local image file as the conversion describes.
     *
     * @param  array{fit?: string, format?: string, height?: int|null, quality?: int, width?: int|null}  $conversion
     * @return array{0: string, 1: string} The encoded image and its file extension.
     */
    public function make(string $sourcePath, array $conversion): array
    {
        $width = $this->dimension($conversion['width'] ?? null);
        $height = $this->dimension($conversion['height'] ?? null);
        $format = $this->format($conversion['format'] ?? 'jpg');
        $quality = max(1, min(100, (int) ($conversion['quality'] ?? 85)));

        $image = $this->manager()->read($sourcePath);
        $image = $this->fit($image, $conversion['fit'] ?? 'contain', $width, $height);

        $encoded = $image->encodeByExtension($format, quality: $quality);

        return [(string) $encoded, $format];
    }

    /**
     * The file extension a conversion will be written with.
     *
     * @param  array{fit?: string, format?: string, height?: int|null, quality?: int, width?: int|null}  $conversion
     */
    public function extensionFor(array $conversion): string
    {
        return $this->format($conversion['format'] ?? 'jpg');
    }

    protected function fit(ImageInterface $image, string $fit, ?int $width, ?int $height): ImageInterface
    {
        // `cover` needs both sides to know what to crop to; with one side it behaves like `contain`.
        if ($fit === 'cover' && $width !== null && $height !== null) {
            return $image->cover($width, $height);
        }

        if ($width === null && $height === null) {
            return $image;
        }

        // scaleDown never enlarges a small image, so a thumbnail of a tiny file stays sharp.
        return $image->scaleDown($width, $height);
    }

    protected function format(string $format): string
    {
        $format = strtolower($format);

        if (! isset(self::FORMATS[$format])) {
            throw new \InvalidArgumentException("Conversion format [{$format}] is not supported; use jpg, png or webp.");
        }

        return self::FORMATS[$format];
    }

    protected function dimension(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    protected function manager(): ImageManager
    {
        /** @var ImageManager */
        return $this->manager;
    }
}
