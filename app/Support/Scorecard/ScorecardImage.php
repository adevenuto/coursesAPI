<?php

namespace App\Support\Scorecard;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Normalises an uploaded scorecard photo for the vision call.
 *
 * Phone photos arrive at 4000px+ and rotated by EXIF alone. Claude's
 * high-resolution tier tops out at 2576px on the long edge — anything larger is
 * downsampled server-side, so sending it only inflates the bill (a full-res
 * image costs up to ~4784 input tokens). We therefore resize once, here, and
 * bake in the EXIF rotation because GD ignores the orientation flag and a
 * sideways scorecard parses badly.
 *
 * Output is always JPEG at a deliberately high quality: the whole feature turns
 * on reading small printed digits, so compression artifacts cost accuracy far
 * more than the extra bytes cost anything.
 */
class ScorecardImage
{
    /** Claude's high-resolution ceiling. Larger is downsampled server-side anyway. */
    public const MAX_EDGE = 2576;

    private const JPEG_QUALITY = 92;

    /**
     * Resize + orient an upload and write it to $destination.
     *
     * @return array{width:int, height:int, bytes:int, sha256:string}
     */
    public static function normalizeTo(UploadedFile $file, string $destination): array
    {
        self::assertGdAvailable();

        $source = self::open($file->getRealPath(), $file->getMimeType());

        try {
            $oriented = self::applyExifOrientation($source, $file->getRealPath());
            if ($oriented !== $source) {
                imagedestroy($source);
                $source = $oriented;
            }

            $resized = self::resizeWithinBounds($source);
            if ($resized !== $source) {
                imagedestroy($source);
                $source = $resized;
            }

            $directory = dirname($destination);
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException("Unable to create scorecard directory: {$directory}");
            }

            if (! imagejpeg($source, $destination, self::JPEG_QUALITY)) {
                throw new RuntimeException("Unable to write scorecard image: {$destination}");
            }

            return [
                'width' => imagesx($source),
                'height' => imagesy($source),
                'bytes' => filesize($destination) ?: 0,
                'sha256' => hash_file('sha256', $destination),
            ];
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Fail early and legibly rather than silently shipping a 12MB original,
     * which would risk the request size ceiling and triple the image tokens.
     */
    public static function assertGdAvailable(): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException(
                'The gd PHP extension is required to process scorecard images. '
                .'Enable it in hPanel → Advanced → PHP Configuration.'
            );
        }
    }

    /**
     * @return \GdImage
     */
    private static function open(string $path, ?string $mime)
    {
        $image = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => throw new RuntimeException("Unsupported scorecard image type: {$mime}"),
        };

        if ($image === false) {
            throw new RuntimeException('The uploaded file could not be read as an image.');
        }

        // A PNG/WebP may carry alpha. Flatten onto white so transparent regions
        // don't render as black once we encode to JPEG.
        if ($mime !== 'image/jpeg' && $mime !== 'image/jpg') {
            $image = self::flattenOntoWhite($image);
        }

        return $image;
    }

    /**
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private static function flattenOntoWhite($image)
    {
        $flattened = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flattened, 0, 0, imagecolorallocate($flattened, 255, 255, 255));
        imagecopy($flattened, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagedestroy($image);

        return $flattened;
    }

    /**
     * GD drops the EXIF orientation flag on re-encode, so a photo that displayed
     * upright in the phone gallery would reach the model rotated. Bake it in.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private static function applyExifOrientation($image, string $path)
    {
        if (! extension_loaded('exif')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 0);

        return match ($orientation) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }

    /**
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private static function resizeWithinBounds($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longEdge = max($width, $height);

        if ($longEdge <= self::MAX_EDGE) {
            return $image;
        }

        $scale = self::MAX_EDGE / $longEdge;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        // imagecopyresampled (not imagecopyresized) — interpolation matters when
        // the thing being read is 8pt printed digits.
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $resized;
    }
}
