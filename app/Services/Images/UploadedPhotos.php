<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Log;

/**
 * Shrinks the photos clients upload before they are kept or passed on.
 *
 * A phone photo is often 4–8 MB at 4000px wide, which is slow to send to
 * Propertyware and the hub and slow to open again later. Each picture is
 * turned upright, scaled to fit within MAX_EDGE and re-encoded, which also
 * drops its EXIF data (camera details and the GPS spot it was taken at).
 *
 * Anything that isn't a still picture, or that can't be read (HEIC on GD),
 * is passed on exactly as it came.
 */
class UploadedPhotos
{
    /** The longest side a kept photo may have, in pixels. */
    public const MAX_EDGE = 2000;

    /** JPEG and WebP quality: no visible loss on a photo, a fraction of the size. */
    public const QUALITY = 82;

    /** Pictures larger than this are left alone, since decoding them could run out of memory. */
    private const MAX_PIXELS = 32_000_000;

    /** Bytes GD holds for each pixel of a decoded picture. */
    private const BYTES_PER_PIXEL = 4;

    /** Memory kept free for the encoder, the scaled copy and the rest of the request. */
    private const HEADROOM_BYTES = 48 * 1024 * 1024;

    /**
     * The format each kind of picture is re-encoded to. Screenshots stay PNG
     * so their text stays sharp; formats few viewers open become JPEG.
     *
     * @var array<string, array{format: string, extension: string, mimeType: string}>
     */
    private const OUTPUTS = [
        'image/jpeg' => ['format' => 'jpeg', 'extension' => 'jpg', 'mimeType' => 'image/jpeg'],
        'image/webp' => ['format' => 'webp', 'extension' => 'webp', 'mimeType' => 'image/webp'],
        'image/png' => ['format' => 'png', 'extension' => 'png', 'mimeType' => 'image/png'],
        'image/heic' => ['format' => 'jpeg', 'extension' => 'jpg', 'mimeType' => 'image/jpeg'],
        'image/heif' => ['format' => 'jpeg', 'extension' => 'jpg', 'mimeType' => 'image/jpeg'],
        'image/avif' => ['format' => 'jpeg', 'extension' => 'jpg', 'mimeType' => 'image/jpeg'],
        'image/bmp' => ['format' => 'jpeg', 'extension' => 'jpg', 'mimeType' => 'image/jpeg'],
    ];

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, UploadedFile>
     */
    public function optimizeAll(array $files): array
    {
        return array_map($this->optimize(...), $files);
    }

    /**
     * The smaller copy of a photo, or the file itself when it isn't one.
     */
    public function optimize(UploadedFile $file): UploadedFile
    {
        $output = self::OUTPUTS[$file->getMimeType()] ?? null;

        if ($output === null || $this->isTooLarge($file)) {
            return $file;
        }

        try {
            // Scaled before it is turned upright: the bounds are square, so the
            // result is the same, and only the small copy is rotated, never a
            // second full-size one.
            $image = Image::fromUpload($file)
                ->scale(self::MAX_EDGE, self::MAX_EDGE)
                ->orient()
                ->toFormat($output['format'])
                ->quality(self::QUALITY);

            $contents = $image->toBytes();
        } catch (ImageException $exception) {
            Log::warning('Could not optimise an uploaded picture; keeping the original.', [
                'mimeType' => $file->getMimeType(),
                'message' => $exception->getMessage(),
            ]);

            return $file;
        }

        // A PNG that wasn't resized can come out larger than it went in, and
        // carries no location to strip, so the original is the better copy.
        if ($output['format'] === 'png' && strlen($contents) >= (int) $file->getSize()) {
            return $file;
        }

        return $this->temporaryUpload($contents, $this->renamed($file->getClientOriginalName(), $output['extension']), $output['mimeType']);
    }

    /**
     * Whether decoding the picture could exhaust the pixel cap or the memory still free.
     */
    private function isTooLarge(UploadedFile $file): bool
    {
        $size = @getimagesize($file->getRealPath());

        if (! is_array($size)) {
            return false;
        }

        $pixels = $size[0] * $size[1];

        return $pixels > self::MAX_PIXELS
            || $pixels * self::BYTES_PER_PIXEL + self::HEADROOM_BYTES > $this->memoryAvailable();
    }

    /**
     * Bytes this request may still allocate, or PHP_INT_MAX when there is no limit.
     */
    private function memoryAvailable(): int
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));

        return $limit <= 0 ? PHP_INT_MAX : $limit - memory_get_usage();
    }

    private function renamed(string $name, string $extension): string
    {
        $current = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($current === $extension || ($extension === 'jpg' && $current === 'jpeg')) {
            return $name;
        }

        return pathinfo($name, PATHINFO_FILENAME).'.'.$extension;
    }

    /**
     * Hold the new bytes in a temporary file that is removed once the request is done.
     */
    private function temporaryUpload(string $contents, string $name, string $mimeType): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($path, $contents);

        app()->terminating(fn () => @unlink($path));

        return new UploadedFile($path, $name, $mimeType, null, true);
    }
}
