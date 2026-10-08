<?php
/**
 * Generate every PWA icon from the master `icon.png` at the project root.
 *
 * Usage:  php generate-pwa-icons.php
 *
 * Outputs (into public/):
 *   pwa-192x192.png     — "any"      manifest icon
 *   pwa-512x512.png     — "any" + "maskable" manifest icon
 *   apple-touch-icon.png— iOS home-screen icon (180x180)
 *   favicon-32x32.png   — classic favicon
 *   favicon.ico         — multi-size ICO (16/32/48)
 *   icon.png            — verbatim copy of the master source
 */

$root = __DIR__;
$source = $root . '/icon.png';
$outDir = $root . '/public';

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

/**
 * Resize the master icon to a square PNG, preserving alpha.
 */
$resize = function (int $size) use ($master, $masterW, $masterH, $outDir, $root) {
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
    imagealphablending($canvas, true);

    imagecopyresampled(
        $canvas,
        $master,
        0, 0, 0, 0,
        $size, $size,
        $masterW, $masterH
    );

    $path = "{$outDir}/pwa-{$size}x{$size}.png";
    imagepng($canvas, $path, 9);
    imagedestroy($canvas);

    return $path;
};

foreach ([192, 512] as $size) {
    $path = $resize($size);
    printf("  wrote %s (%d bytes)\n", basename($path), filesize($path));
}

/**
 * Named single-size PNG outputs (apple-touch + classic favicon).
 */
$emitPng = function (int $size, string $filename) use ($master, $masterW, $masterH, $outDir) {
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
    imagealphablending($canvas, true);

    imagecopyresampled($canvas, $master, 0, 0, 0, 0, $size, $size, $masterW, $masterH);
    imagepng($canvas, "{$outDir}/{$filename}", 9);
    imagedestroy($canvas);

    printf("  wrote %s (%d bytes)\n", $filename, filesize("{$outDir}/{$filename}"));
};

$emitPng(180, 'apple-touch-icon.png');
$emitPng(32, 'favicon-32x32.png');

/**
 * Build a multi-size ICO (16/32/48) embedding PNG payloads. Valid per the
 * ICO spec and understood by every modern browser.
 */
$icoSizes = [16, 32, 48];
$payloads = [];

foreach ($icoSizes as $size) {
    $canvas = imagecreatetruecolor($size, $size);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $master, 0, 0, 0, 0, $size, $size, $masterW, $masterH);

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

/**
 * Keep a verbatim copy of the master at public/icon.png so the manifest
 * "id"/scope asset stays in sync with the source of truth.
 */
copy($source, "{$outDir}/icon.png");
printf("  wrote icon.png (%d bytes)\n", filesize("{$outDir}/icon.png"));

imagedestroy($master);

echo "Done. All PWA icons generated from icon.png.\n";
