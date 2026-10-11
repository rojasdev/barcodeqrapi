<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['success' => false, 'message' => 'Use POST with a JSON request body.']);
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    respond(415, ['success' => false, 'message' => 'Content-Type must be application/json.']);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > MAX_REQUEST_BYTES) {
    respond(413, ['success' => false, 'message' => 'Request body is too large.']);
}
$request = json_decode($raw, true);
if (!is_array($request) || json_last_error() !== JSON_ERROR_NONE) {
    respond(400, ['success' => false, 'message' => 'Invalid JSON request body.']);
}

$type = strtolower(trim((string)($request['type'] ?? '')));
$value = $request['value'] ?? null;
$imageData = $request['image_data'] ?? null;

if (!in_array($type, ['qr', 'barcode'], true)) {
    respond(422, ['success' => false, 'message' => 'type must be "qr" or "barcode".']);
}
if (!is_string($value) || trim($value) === '' || strlen($value) > MAX_VALUE_LENGTH) {
    respond(422, ['success' => false, 'message' => 'value must be a non-empty string within the length limit.']);
}
$value = trim($value);
if (!is_string($imageData) || $imageData === '') {
    respond(422, ['success' => false, 'message' => 'image_data is required.']);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    respond(500, ['success' => false, 'message' => 'Could not resolve storage root.']);
}
$relativeDir = $type === 'qr' ? 'images/qr' : 'images/barcode';
$targetDir = $root . DIRECTORY_SEPARATOR . $relativeDir;
if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    respond(500, ['success' => false, 'message' => 'Could not create image directory.']);
}
if (!is_writable($targetDir)) {
    respond(500, ['success' => false, 'message' => 'Image directory is not writable by PHP.']);
}

if ($type === 'qr') {
    if (strlen($imageData) > MAX_IMAGE_BYTES * 1.5 ||
        !preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\r\n]+)$#', $imageData, $matches)) {
        respond(422, ['success' => false, 'message' => 'QR image_data must be a valid PNG data URL.']);
    }
    $binary = base64_decode($matches[1], true);
    if ($binary === false || strlen($binary) > MAX_IMAGE_BYTES) {
        respond(422, ['success' => false, 'message' => 'QR image is invalid or too large.']);
    }
    $info = @getimagesizefromstring($binary);
    if (!is_array($info) || ($info['mime'] ?? '') !== 'image/png') {
        respond(422, ['success' => false, 'message' => 'Uploaded QR image is not a valid PNG.']);
    }
    $extension = 'png';
} else {
    if (strlen($imageData) > MAX_IMAGE_BYTES ||
        stripos($imageData, '<svg') === false ||
        stripos($imageData, '</svg>') === false ||
        preg_match('/<\s*(script|foreignObject)\b/i', $imageData) ||
        preg_match('/\bon\w+\s*=/i', $imageData) ||
        preg_match('/(?:href|src)\s*=\s*["\']\s*(?:https?:|javascript:|data:)/i', $imageData)) {
        respond(422, ['success' => false, 'message' => 'Barcode SVG is invalid or contains disallowed content.']);
    }
    // Accept only SVG produced for this barcode workflow; prevent XML declarations/DOCTYPE/entity declarations.
    if (preg_match('/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet/i', $imageData)) {
        respond(422, ['success' => false, 'message' => 'Barcode SVG contains disallowed XML declarations.']);
    }
    $binary = $imageData;
    $extension = 'svg';
}

$filename = bin2hex(random_bytes(16)) . '.' . $extension;
$path = $targetDir . DIRECTORY_SEPARATOR . $filename;
if (file_put_contents($path, $binary, LOCK_EX) === false) {
    respond(500, ['success' => false, 'message' => 'Could not save image.']);
}

$imageUrl = rtrim(BASE_URL, '/') . '/' . $relativeDir . '/' . rawurlencode($filename);
respond(201, [
    'success' => true,
    'type' => $type,
    'format' => $extension,
    'value' => $value,
    'image_url' => $imageUrl
]);
