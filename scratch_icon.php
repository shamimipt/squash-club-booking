<?php
$img = imagecreatefrompng('C:/Users/Md Rejaul Islam/Local Sites/plugin-development/app/public/wp-content/plugins/squash-club-booking/contrast_debug.png');
$w = imagesx($img);
$h = imagesy($img);

// Find all red pixels (r=255, g=0, b=0) between y=160 and y=300
for ($y = 160; $y < 300; $y++) {
    for ($x = 20; $x < 250; $x++) {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        if ($r === 255 && $g === 0 && $b === 0) {
            echo "Red pixel in debug at ($x, $y)\n";
            break 2;
        }
    }
}
