<?php
// Target Node.js server URL
// $targetServer = 'http://localhost';
$targetServer = 'http://209.97.146.104';

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Get the path from the URL (everything after proxy.php)
$uri = $_SERVER['REQUEST_URI'];
$path = '';

// Extract path after proxy.php (can be query param or URL path)
if (preg_match('/proxy\.php(?:\?path=([^&]*))?(.*)/', $uri, $matches)) {
    // Check if path comes from query parameter first
    if (!empty($matches[1])) {
        $path = '/' . urldecode($matches[1]);
    } else {
        $path = $matches[2];
    }
}

// Also check for query parameter 'path'
if (empty($path) && isset($_GET['path'])) {
    $path = '/' . $_GET['path'];
}

// Full target URL
$url = $targetServer . $path;

// Initialize cURL
$ch = curl_init();

// Set cURL options
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

// Forward request headers
$headers = [];
if (function_exists('getallheaders')) {
    $requestHeaders = getallheaders();
    foreach ($requestHeaders as $key => $value) {
        // Skip host header to avoid conflicts
        if (strtolower($key) !== 'host') {
            $headers[] = "$key: $value";
        }
    }
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Forward request body for POST, PUT, etc.
if ($method == 'POST' || $method == 'PUT' || $method == 'PATCH') {
    $body = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

// Execute request
$response = curl_exec($ch);
$responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

// Handle errors
if (curl_errno($ch)) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Proxy Error: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}

curl_close($ch);

// Set response headers
http_response_code($responseCode);
if ($contentType) {
    header("Content-Type: $contentType");
} else {
    header('Content-Type: application/json');
}

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Output response
echo $response;
?>