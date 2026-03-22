<?php

/**
 * Shared helpers for LatticeDB benchmarks.
 */

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Get embedding vector from Ollama nomic-embed-text.
 * @return float[]
 */
function get_embedding(string $text): array
{
    $ch = curl_init('http://localhost:11434/api/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'nomic-embed-text',
            'prompt' => $text,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode !== 200) {
        throw new RuntimeException("Ollama error (HTTP {$httpCode}): {$response}");
    }

    $data = json_decode($response, true);
    return $data['embedding'];
}

/**
 * Compute dot product (cosine similarity for normalized vectors).
 */
function dot_product(array $a, array $b): float
{
    $sum = 0.0;
    for ($i = 0, $n = count($a); $i < $n; $i++) {
        $sum += $a[$i] * $b[$i];
    }
    return $sum;
}

/**
 * L2-normalize a vector in-place. Returns the normalized vector.
 * @param float[] $v
 * @return float[]
 */
function normalize_vector(array $v): array
{
    $norm = 0.0;
    foreach ($v as $x) {
        $norm += $x * $x;
    }
    $norm = sqrt($norm);
    if ($norm < 1e-12) {
        return $v;
    }
    foreach ($v as &$x) {
        $x /= $norm;
    }
    return $v;
}

/**
 * Cosine similarity between two vectors (handles unnormalized).
 */
function cosine_similarity(array $a, array $b): float
{
    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    for ($i = 0, $n = count($a); $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }
    $denom = sqrt($normA) * sqrt($normB);
    return $denom > 0 ? $dot / $denom : 0.0;
}

/**
 * Format bytes to human-readable.
 */
function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    return round($bytes / 1024, 1) . ' KB';
}

/**
 * Print a section header.
 */
function section(string $title): void
{
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "  {$title}\n";
    echo str_repeat('=', 60) . "\n\n";
}

/**
 * Timer helper.
 */
function timer_start(): float
{
    return microtime(true);
}

function timer_ms(float $start): float
{
    return (microtime(true) - $start) * 1000;
}

function timer_s(float $start): float
{
    return microtime(true) - $start;
}

/**
 * Calculate percentile from sorted array of values.
 */
function percentile(array $sorted, float $p): float
{
    $n = count($sorted);
    if ($n === 0) return 0;
    $idx = ($p / 100) * ($n - 1);
    $floor = (int)floor($idx);
    $frac = $idx - $floor;
    if ($floor + 1 < $n) {
        return $sorted[$floor] + $frac * ($sorted[$floor + 1] - $sorted[$floor]);
    }
    return $sorted[$floor];
}
