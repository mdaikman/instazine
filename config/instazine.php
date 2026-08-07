<?php

return [
    'mode' => env('INSTAZINE_MODE', 'EXPRESS'),
    'headers' => (int) env('INSTAZINE_HEADERS', 1),
    'articles' => (int) env('INSTAZINE_ARTICLES', 1),
    'middles' => (int) env('INSTAZINE_MIDDLES', 0),
    'footers' => (int) env('INSTAZINE_FOOTERS', 0),
    'banner' => env('INSTAZINE_BANNER', 'storage/app/private/banners/nada.png'),
];
