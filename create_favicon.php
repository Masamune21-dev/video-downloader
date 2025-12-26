<?php
// Generate simple favicon programmatically
$size = 64;
$image = imagecreatetruecolor($size, $size);

// Background color (primary color)
$bgColor = imagecolorallocate($image, 99, 102, 241); // #6366f1
$iconColor = imagecolorallocate($image, 255, 255, 255);

// Fill background
imagefilledrectangle($image, 0, 0, $size, $size, $bgColor);

// Draw download icon
$centerX = $size / 2;
$centerY = $size / 2;
$radius = $size / 3;

// Draw arrow
imagesetthickness($image, 5);
imageline($image, $centerX, $centerY - $radius/2, $centerX, $centerY + $radius/2, $iconColor);
imageline($image, $centerX - $radius/3, $centerY, $centerX, $centerY + $radius/2, $iconColor);
imageline($image, $centerX + $radius/3, $centerY, $centerX, $centerY + $radius/2, $iconColor);

// Output as ICO
header('Content-Type: image/x-icon');
imagepng($image, 'assets/favicon.png');
imagepng($image); // Output to browser
imagedestroy($image);
?>
