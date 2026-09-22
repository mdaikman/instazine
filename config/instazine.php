<?php

return [
    // LLL is short for "The least and latest lead article selection".
    'mode' => env('INSTAZINE_MODE', 'EXPRESS'),
    'headers' => (int) env('INSTAZINE_HEADERS', 1),
    'articles' => (int) env('INSTAZINE_ARTICLES', 1),
    'middles' => (int) env('INSTAZINE_MIDDLES', 0),
    'footers' => (int) env('INSTAZINE_FOOTERS', 0),
    'banner' => env('INSTAZINE_BANNER', 'storage/app/private/banners/nada.png'),
    'dividers' => [
        env('INSTAZINE_DIVIDER_A'),
        env('INSTAZINE_DIVIDER_B'),
        env('INSTAZINE_DIVIDER_C'),
        env('INSTAZINE_DIVIDER_D'),
    ],
    // Must match PRINTER_PIXEL_WIDTH in the ESP32 firmware.
    'printer_pixel_width' => (int) env('INSTAZINE_PRINTER_PIXEL_WIDTH', 576),
    'image_height_max' => (int) env('INSTAZINE_IMAGE_HEIGHT_MAX', 2304),
    // Keep this below PHP's 32 MiB limit so Laravel can return a useful error.
    'image_upload_kilobytes_max' => (int) env('INSTAZINE_IMAGE_UPLOAD_KILOBYTES_MAX', 31_744),
    'image_source_pixels_max' => (int) env('INSTAZINE_IMAGE_SOURCE_PIXELS_MAX', 20_000_000),
];
