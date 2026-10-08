<?php
/**
 * Generate the complete PWA icon set from the master `icon.png` at the project
 * root.
 *
 * Usage:  php generate-pwa-icons.php
 *
 * Outputs (into public/):
 *   pwa-{48..512}.png    — standard "any" manifest icons
 *   maskable-{192,512}.png — safe-zone padded maskable icons
 *   apple-touch-icon.png — iOS home-screen icon (180x180)
 *   favicon-16/32/48.png — classic favicons
 *   favicon.ico          — multi-size ICO (16/32/48)
 *   icon.png             — verbatim copy of the master source
 */

$root = __DIR__;
$source = $root . '/icon.png';
$outDir = $root . '/public';

/** Standard "any" sizes emitted as pwa-{size}x{size}.png */
const ANY_SIZES = [48, 72, 96, 128, 144, 152, 192, 256, 384, 512];

/** Sizes emitted as padded, maskable-safe icons. */
const MASKABLE_SIZES = [192, 512];

/** Browsers that still fetch favicon.ico expect these sizes inside the ICO. */
const ICO_SIZES = [16, 32, 48];

if (! is_file($source)) {
    fwrite(STDERR, "Master icon not found: {$source}\n");
    exit(1);
}

$info = getimagesize($source);
if ($info === false || $info[2] !== IMAGETYPE_PNG) {
    fwrite(STDERR, "Master icon must be a PNG file.\n");
    exit(1);
}

$master = imagecreatefrompng($source);
if ($master === false) {
    fwrite(STDERR, "Unable to read master icon.\n");
    exit(1);
}

$masterW = imagesx($master);
$masterH = imagesy($master);

// Sample the master's own background so maskable padding blends seamlessly.
$corner = imagecolorat($master, 2, 2);
$bgR = ($corner >> 16) & 0xFF;
$bgG = ($corner >> 8) & 0xFF;
$bgB = $corner & 0xFF;

/**
 * Draw $master onto a transparent square canvas of $size.
 */
$drawTransparent = function ($canvas, int $size) use ($master, $masterW, $masterH) {
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefilledrectangle($canvas, 0, 0, $size, $size, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $master, 0, 0, 0, 0, $size, $size, $masterW, $masterH);
};

/**
 * Resize to a square PNG preserving transparency.
 */
$writeAny = function (int $size) use ($outDir, $drawTransparent) {
    $canvas = imagecreatetruecolor($size, $size);
    $drawTransparent($canvas, $size);
    $path = "{$outDir}/pwa-{$size}x{$size}.png";
    imagepng($canvas, $path, 9);
    imagedestroy($canvas);

    return $path;
};

/**
 * Maskable icon: opaque background + logo scaled to the 80% safe zone so
 * platform masks (circle/squircle) never clip the mark.
 */
$writeMaskable = function (int $size) use ($outDir, $master, $masterW, $masterH, $bgR, $bgG, $bgB) {
    $canvas = imagecreatetruecolor($size, $size);
    imagefilledrectangle($canvas, 0, 0, $size, $size, imagecolorallocate($canvas, $bgR, $bgG, $bgB));

    $inner = (int) round($size * 0.8);
    $offset = (int) round(($size - $inner) / 2);
    imagecopyresampled($canvas, $master, $offset, $offset, 0, 0, $inner, $inner, $masterW, $masterH);

    $path = "{$outDir}/maskable-{$size}x{$size}.png";
    imagepng($canvas, $path, 9);
    imagedestroy($canvas);

    return $path;
};

echo "Standard icons:\n";
foreach (ANY_SIZES as $size) {
    printf("  wrote %s (%d bytes)\n", basename($writeAny($size)), filesize("{$outDir}/pwa-{$size}x{$size}.png"));
}

echo "Maskable icons:\n";
foreach (MASKABLE_SIZES as $size) {
    printf("  wrote %s (%d bytes)\n", basename($writeMaskable($size)), filesize("{$outDir}/maskable-{$size}x{$size}.png"));
}

echo "Named icons:\n";
$named = [
    180 => 'apple-touch-icon.png',
    16 => 'favicon-16x16.png',
    32 => 'favicon-32x32.png',
    48 => 'favicon-48x48.png',
];
foreach ($named as $size => $filename) {
    $canvas = imagecreatetruecolor($size, $size);
    $drawTransparent($canvas, $size);
    imagepng($canvas, "{$outDir}/{$filename}", 9);
    imagedestroy($canvas);
    printf("  wrote %s (%d bytes)\n", $filename, filesize("{$outDir}/{$filename}"));
}

echo "Favicon:\n";
$payloads = [];
foreach (ICO_SIZES as $size) {
    $canvas = imagecreatetruecolor($size, $size);
    $drawTransparent($canvas, $size);
    ob_start();
    imagepng($canvas);
    $payloads[$size] = ob_get_clean();
    imagedestroy($canvas);
}

$count = count($payloads);
$header = pack('vvv', 0, 1, $count);
$offset = 6 + ($count * 16);
$entries = '';
$data = '';
foreach ($payloads as $size => $png) {
    $entries .= pack(
        'CCCCvvVV',
        $size === 256 ? 0 : $size,
        $size === 256 ? 0 : $size,
        0,
        0,
        1,
        32,
        strlen($png),
        $offset
    );
    $data .= $png;
    $offset += strlen($png);
}
file_put_contents("{$outDir}/favicon.ico", $header . $entries . $data);
printf("  wrote favicon.ico (%d bytes)\n", filesize("{$outDir}/favicon.ico"));

copy($source, "{$outDir}/icon.png");
printf("  wrote icon.png (%d bytes)\n", filesize("{$outDir}/icon.png"));

imagedestroy($master);

echo "Done. Full PWA icon set generated from icon.png.\n";
