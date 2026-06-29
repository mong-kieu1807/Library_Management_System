<?php

/**
 * list_gemini_models.php
 *
 * Standalone CLI script — không dùng Laravel.
 * Đọc GEMINI_API_KEY từ .env rồi gọi Gemini API để liệt kê
 * toàn bộ model mà API key hiện tại được phép sử dụng.
 *
 * Chạy: php scripts/list_gemini_models.php
 */

declare(strict_types=1);

// ── 1. Parse .env ─────────────────────────────────────────────────────────────

function parseEnvFile(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $values = [];
    $lines  = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\"'");   // strip surrounding quotes
        if ($key !== '') {
            $values[$key] = $val;
        }
    }

    return $values;
}

$envPath = __DIR__ . '/../.env';
$env     = parseEnvFile($envPath);

$apiKey      = $env['GEMINI_API_KEY'] ?? '';
$activeModel = $env['GEMINI_MODEL']   ?? 'gemini-2.5-flash';

if ($apiKey === '') {
    echo "[ERROR] GEMINI_API_KEY không tìm thấy trong {$envPath}\n";
    exit(1);
}

echo "API Key  : " . substr($apiKey, 0, 6) . str_repeat('*', 10) . substr($apiKey, -4) . "\n";
echo "Model đang dùng (.env): {$activeModel}\n";
echo str_repeat('─', 70) . "\n\n";

// ── 2. Gọi Gemini List Models ─────────────────────────────────────────────────

$url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $apiKey;

$caBundle = 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt';

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CAINFO         => file_exists($caBundle) ? $caBundle : null,
        CURLOPT_SSL_VERIFYPEER => file_exists($caBundle),
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        echo "[ERROR] curl failed: {$curlErr}\n";
        exit(1);
    }
} else {
    // Fallback: file_get_contents
    $ctx  = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);

    if ($body === false) {
        echo "[ERROR] file_get_contents failed. Hãy bật curl extension.\n";
        exit(1);
    }

    $httpCode = 0;
    if (isset($http_response_header)) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
        $httpCode = (int) ($m[1] ?? 0);
    }
}

// ── 3. Parse và in kết quả ────────────────────────────────────────────────────

$data = json_decode($body, true);

if ($data === null) {
    echo "[ERROR] Response không phải JSON hợp lệ:\n{$body}\n";
    exit(1);
}

if ($httpCode !== 200 || isset($data['error'])) {
    echo "[ERROR] HTTP {$httpCode}\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$models = $data['models'] ?? [];

if (empty($models)) {
    echo "Không có model nào được trả về.\n";
    exit(0);
}

printf("%-50s  %-15s  %s\n", 'NAME', 'VERSION', 'SUPPORTED METHODS');
echo str_repeat('─', 100) . "\n";

foreach ($models as $m) {
    $name    = str_replace('models/', '', $m['name'] ?? '');
    $version = $m['version'] ?? '-';
    $methods = implode(', ', $m['supportedGenerationMethods'] ?? []);

    // Đánh dấu model đang được dùng
    $marker = ($name === $activeModel) ? ' ◀ active' : '';

    printf("%-50s  %-15s  %s%s\n", $name, $version, $methods, $marker);
}

echo "\nTổng: " . count($models) . " model(s).\n";
