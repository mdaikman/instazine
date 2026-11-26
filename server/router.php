<?php

$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$documentRoot = __DIR__;
$requestedFile = realpath($documentRoot.$requestPath);

if ($requestPath === '/') {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($documentRoot.'/index.html');

    return true;
}

// Let PHP's development server return existing files, including index.html.
if ($requestedFile !== false
    && str_starts_with($requestedFile, $documentRoot.DIRECTORY_SEPARATOR)
    && is_file($requestedFile)) {
    return false;
}

if ($requestPath === '/stories') {
    header('Location: /stories/', true, 301);

    return true;
}

if ($requestPath === '/stories/' || str_starts_with($requestPath, '/stories/')) {
    $frontController = $documentRoot.'/stories/index.php';

    $_SERVER['SCRIPT_NAME'] = '/stories/index.php';
    $_SERVER['PHP_SELF'] = '/stories/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $frontController;

    require $frontController;

    return true;
}

http_response_code(404);
echo 'Not Found';

return true;
