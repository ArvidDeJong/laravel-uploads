<?php

namespace Darvis\Uploads\Processing;

use Darvis\Uploads\Exceptions\UnreadableFile;
use Intervention\Image\Exceptions\DecoderException;
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
     * Resize and encode a local image file as the conversion describes. With a `crop` area
     * (`x`, `y`, `width`, `height` in pixels of the original) only that part is used.
     *
     * @param  array{crop?: array{x: int, y: int, width: int, height: int}|null, fit?: string, format?: string, height?: int|null, quality?: int, width?: int|null}  $conversion
     * @return array{0: string, 1: string} The encoded image and its file extension.
     *
     * @throws UnreadableFile
     */
    public function make(string $sourcePath, array $conversion): array
    {
        $width = $this->dimension($conversion['width'] ?? null);
        $height = $this->dimension($conversion['height'] ?? null);
        $format = $this->format($conversion['format'] ?? 'jpg');
        $quality = max(1, min(100, (int) ($conversion['quality'] ?? 85)));

        try {
            $image = $this->manager()->read($sourcePath);
        } catch (DecoderException $exception) {
            throw new UnreadableFile('The image cannot be decoded: '.$exception->getMessage(), previous: $exception);
        }

        if (isset($conversion['crop'])) {
            $image = $this->crop($image, $conversion['crop']);
        }

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

    /**
     * Cut the area out of the image, kept inside the image when it reaches past an edge.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $area
     */
    protected function crop(ImageInterface $image, array $area): ImageInterface
    {
        $x = max(0, min($image->width() - 1, $area['x']));
        $y = max(0, min($image->height() - 1, $area['y']));
        $width = max(1, min($image->width() - $x, $area['width']));
        $height = max(1, min($image->height() - $y, $area['height']));

        return $image->crop($width, $height, $x, $y);
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
