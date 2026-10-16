<?php
// Removes outer white background from each design PNG via corner flood-fill
// Preserves interior white (notification card body, etc.)
set_time_limit(120);

$src_dir = __DIR__ . '/Untitled design/';
$dst_dir = __DIR__ . '/assets/images/design/';

if (!is_dir($dst_dir)) mkdir($dst_dir, 0755, true);

// Marker color: pure cyan — almost certainly not in any of these images
define('MARKER_R', 0);
define('MARKER_G', 255);
define('MARKER_B', 255);

for ($i = 1; $i <= 4; $i++) {
    $src = $src_dir . $i . '.png';
    echo "Processing $i.png ... ";

    $orig = imagecreatefrompng($src);
    $w = imagesx($orig);
    $h = imagesy($orig);

    // Create truecolor RGBA output, pre-filled transparent
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $w, $h, $transparent);

    // Copy source pixels into output (alpha blending off so we keep exact pixels)
    imagecopy($out, $orig, 0, 0, 0, 0, $w, $h);

    // Allocate unique marker color in out (truecolor — no palette needed)
    // We'll identify it by its exact RGB values
    $marker = imagecolorallocate($out, MARKER_R, MARKER_G, MARKER_B);

    // Flood-fill from all 4 corners to mark outer background
    imagefill($out, 0,     0,     $marker);
    imagefill($out, $w-1,  0,     $marker);
    imagefill($out, 0,     $h-1,  $marker);
    imagefill($out, $w-1,  $h-1,  $marker);
    // Also seed from all 4 edge midpoints to catch any connected gaps
    imagefill($out, $w>>1, 0,     $marker);
    imagefill($out, $w>>1, $h-1,  $marker);
    imagefill($out, 0,     $h>>1, $marker);
    imagefill($out, $w-1,  $h>>1, $marker);

    // Swap marker pixels → fully transparent
    imagealphablending($out, false);
    $fully_transparent = imagecolorallocatealpha($out, 255, 255, 255, 127);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $px = imagecolorat($out, $x, $y);
            $r = ($px >> 16) & 0xFF;
            $g = ($px >> 8) & 0xFF;
            $b = $px & 0xFF;
            if ($r === MARKER_R && $g === MARKER_G && $b === MARKER_B) {
                imagesetpixel($out, $x, $y, $fully_transparent);
            }
        }
    }

    imagesavealpha($out, true);
    imagepng($out, $dst_dir . $i . '.png', 9);
    imagedestroy($orig);
    imagedestroy($out);
    echo "saved to assets/images/design/$i.png\n";
}

echo "\nAll done!\n";
