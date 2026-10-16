<?php
// Router script for PHP built-in server
// Serves static files and routes requests appropriately

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

// If the requested file exists and is a file, serve it directly
if ($path !== '/' && file_exists($file) && is_file($file)) {
    return false;
}

// A folder such as /landing-admin/ must open that folder's own index.php (the CMS), not the site's home page.
// Without this, the home page HTML was served from /landing-admin/ and its relative CSS links broke.
if ($path !== '/' && is_dir(rtrim($file, '/\\'))) {
    $dir = rtrim($file, '/\\');
    foreach (['index.php', 'index.html'] as $indexName) {
        if (is_file($dir . '/' . $indexName)) {
            if (substr($path, -1) !== '/') {
                // /landing-admin -> /landing-admin/ so relative links (../assets/...) resolve correctly
                $qs = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
                header('Location: ' . $path . '/' . ($qs !== '' ? '?' . $qs : ''), true, 301);
                exit();
            }
            if ($indexName === 'index.html') {
                header('Content-Type: text/html; charset=UTF-8');
                readfile($dir . '/' . $indexName);
                exit();
            }
            $_SERVER['SCRIPT_NAME'] = $path . $indexName;
            $_SERVER['PHP_SELF'] = $path . $indexName;
            $_SERVER['SCRIPT_FILENAME'] = $dir . '/' . $indexName;
            chdir($dir);
            require $dir . '/' . $indexName;
            exit();
        }
    }
}

// If the path doesn't have an extension, try adding .php
if ($path !== '/' && !pathinfo($path, PATHINFO_EXTENSION)) {
    $phpFile = __DIR__ . $path . '.php';
    if (file_exists($phpFile) && is_file($phpFile)) {
        require $phpFile;
        exit();
    }
}

// For API webhook routes, try to find the corresponding PHP file
if (strpos($path, '/api/webhook/') === 0) {
    $webhookFile = __DIR__ . $path . '.php';
    if (file_exists($webhookFile)) {
        require $webhookFile;
        exit();
    }
}

// Otherwise, route to index.php
if (file_exists(__DIR__ . '/index.php')) {
    require __DIR__ . '/index.php';
    exit();
}

http_response_code(404);
echo '404 Not Found';
