<?php

/**
 * Downsample the 1024px masters rendered by bin/render-icons.sh into the icon
 * set, and pack the small sizes into a multi-resolution favicon.ico.
 *
 * Usage: php resize.php <master.png> <master-maskable.png> <public-dir>
 */
[, $masterPath, $maskablePath, $out] = $argv;

/** Load a PNG, preserving alpha. */
function load(string $path): GdImage
{
    $image = @imagecreatefrompng($path);

    if (! $image) {
        fwrite(STDERR, "error: could not read {$path}\n");
        exit(1);
    }

    return $image;
}

/** Square-resample to $size, keeping alpha intact. */
function resample(GdImage $source, int $size): GdImage
{
    $target = imagecreatetruecolor($size, $size);

    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagecopyresampled(
        $target, $source,
        0, 0, 0, 0,
        $size, $size,
        imagesx($source), imagesy($source),
    );

    return $target;
}

function writePng(GdImage $image, string $path): void
{
    imagepng($image, $path, 9);
    printf("    %-26s %dx%d\n", basename($path), imagesx($image), imagesy($image));
}

/**
 * Build a multi-resolution .ico from PNG payloads.
 *
 * Every browser in use reads PNG-compressed ICO entries, so each size is stored
 * as a whole PNG file rather than a raw BMP bitmap.
 *
 * @param  array<int, string>  $pngs  size => PNG binary
 */
function writeIco(array $pngs, string $path): void
{
    $count = count($pngs);
    $offset = 6 + ($count * 16);

    $directory = '';
    $payload = '';

    foreach ($pngs as $size => $data) {
        $directory .= pack(
            'CCCCvvVV',
            $size >= 256 ? 0 : $size,  // 0 means 256
            $size >= 256 ? 0 : $size,
            0,                          // palette size — 0 for truecolour
            0,                          // reserved
            1,                          // colour planes
            32,                         // bits per pixel
            strlen($data),
            $offset,
        );

        $payload .= $data;
        $offset += strlen($data);
    }

    file_put_contents($path, pack('vvv', 0, 1, $count).$directory.$payload);
    printf("    %-26s %s\n", basename($path), implode('/', array_keys($pngs)));
}

$master = load($masterPath);
$maskable = load($maskablePath);

writePng(resample($master, 180), "{$out}/apple-touch-icon.png");
writePng(resample($master, 192), "{$out}/icon-192.png");
writePng(resample($master, 512), "{$out}/icon-512.png");
writePng(resample($maskable, 512), "{$out}/icon-maskable-512.png");

// favicon.ico carries the sizes Windows and browser chrome actually ask for.
$ico = [];

foreach ([48, 32, 16] as $size) {
    ob_start();
    imagepng(resample($master, $size), null, 9);
    $ico[$size] = ob_get_clean();
}

writeIco($ico, "{$out}/favicon.ico");
