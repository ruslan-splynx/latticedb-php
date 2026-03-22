<?php

/**
 * LatticeDB Memory Profile
 *
 * Measures PHP RSS and heap at each stage to verify:
 *   1. No memory accumulation between searches
 *   2. No "cold start" penalty after idle
 *   3. Predictable memory per operation
 *
 * Usage: php benchmark/latticedb_memory_profile.php
 */

ini_set('memory_limit', '2G');
set_time_limit(0);

require_once __DIR__ . '/helpers.php';

use LatticeDB\Database;

const DATA_DIR = __DIR__ . '/data';
const DB_PATH = __DIR__ . '/data/memprofile.ltdb';

function get_rss_mb(): float
{
    // macOS: ps reports RSS in KB
    $pid = getmypid();
    $rss = trim(shell_exec("ps -o rss= -p {$pid}"));
    return ((int)$rss) / 1024;
}

function mem_snapshot(string $stage): array
{
    return [
        'stage' => $stage,
        'rss_mb' => round(get_rss_mb(), 1),
        'php_heap_mb' => round(memory_get_usage(true) / 1048576, 1),
        'php_used_mb' => round(memory_get_usage(false) / 1048576, 1),
    ];
}

function print_snapshot(array $snap, ?array $prev = null): void
{
    $delta = $prev ? sprintf(' (%+.1f)', $snap['rss_mb'] - $prev['rss_mb']) : '';
    printf("  %-35s RSS: %6.1f MB%s  |  PHP heap: %.1f MB  used: %.1f MB\n",
        $snap['stage'], $snap['rss_mb'], $delta, $snap['php_heap_mb'], $snap['php_used_mb']);
}

// ============================================================================

echo "=== LatticeDB Memory Profile ===\n\n";

$timeline = [];
$snap = mem_snapshot('1. Baseline (before loading data)');
$timeline[] = $snap;
print_snapshot($snap);

// Load dataset
$dataset = json_decode(file_get_contents(DATA_DIR . '/dataset_10k.json'), true);
$queries = json_decode(file_get_contents(DATA_DIR . '/queries_200.json'), true);
$dims = count($dataset[0]['vector']);

$snap = mem_snapshot('2. After loading JSON dataset');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

// Cleanup previous
foreach (glob(DB_PATH . '*') as $f) @unlink($f);

// Open DB
$db = Database::open(DB_PATH, [
    'create' => true,
    'enable_vector' => true,
    'vector_dimensions' => $dims,
]);

$snap = mem_snapshot('3. After DB open (empty)');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

// Batch insert 10k
echo "\n--- Inserting 10k records ---\n";
$start = timer_start();
$db->transaction(function ($txn) use ($dataset) {
    foreach (array_chunk($dataset, 1000) as $batch) {
        $items = [];
        foreach ($batch as $r) {
            $items[] = ['label' => 'Ticket', 'vector' => $r['vector']];
        }
        $txn->vectors()->batchInsert($items);
    }
});
printf("  Insert done: %.1fs\n", timer_s($start));

$snap = mem_snapshot('4. After 10k insert');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

// Free dataset from PHP memory
unset($dataset);
gc_collect_cycles();

$snap = mem_snapshot('5. After unset(dataset) + GC');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

// Search: first 200 (cold)
echo "\n--- Search batches ---\n";
foreach ($queries as $q) {
    $db->vectors()->search(vector: $q['vector'], k: 10, efSearch: 200);
}
$snap = mem_snapshot('6. After 200 searches (cold)');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

// Search: next 800
for ($i = 0; $i < 4; $i++) {
    foreach ($queries as $q) {
        $db->vectors()->search(vector: $q['vector'], k: 10, efSearch: 200);
    }
}
$snap = mem_snapshot('7. After 1000 total searches');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

// Search: next 1000
for ($i = 0; $i < 5; $i++) {
    foreach ($queries as $q) {
        $db->vectors()->search(vector: $q['vector'], k: 10, efSearch: 200);
    }
}
$snap = mem_snapshot('8. After 2000 total searches');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

// Search: next 3000
for ($i = 0; $i < 15; $i++) {
    foreach ($queries as $q) {
        $db->vectors()->search(vector: $q['vector'], k: 10, efSearch: 200);
    }
}
$snap = mem_snapshot('9. After 5000 total searches');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

// Idle 10s
echo "\n--- Idle 10s ---\n";
sleep(10);
$snap = mem_snapshot('10. After 10s idle');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

// More searches after idle (simulate "cold start" scenario)
echo "\n--- Post-idle searches ---\n";
foreach ($queries as $q) {
    $db->vectors()->search(vector: $q['vector'], k: 10, efSearch: 200);
}
$snap = mem_snapshot('11. After 200 post-idle searches');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

// Close and reopen (simulate new request)
echo "\n--- Close + reopen (new request simulation) ---\n";
$db->close();
gc_collect_cycles();

$snap = mem_snapshot('12. After DB close + GC');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

$db = Database::open(DB_PATH, [
    'enable_vector' => true,
    'vector_dimensions' => $dims,
]);

$snap = mem_snapshot('13. After DB reopen');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

// Searches on reopened DB
foreach ($queries as $q) {
    $db->vectors()->search(vector: $q['vector'], k: 10, efSearch: 200);
}
$snap = mem_snapshot('14. After 200 searches on reopened DB');
$timeline[] = $snap;
print_snapshot($snap, $timeline[count($timeline) - 2]);

$db->close();

// Summary
section("Memory Profile Summary");

$baseline = $timeline[4]['rss_mb']; // after unset(dataset)
$peak = max(array_column($timeline, 'rss_mb'));
$final = end($timeline)['rss_mb'];
$searchStart = $timeline[5]['rss_mb']; // after first 200 searches
$search5k = $timeline[8]['rss_mb']; // after 5000 searches
$searchGrowth = $search5k - $searchStart;

printf("  Baseline (no dataset):     %.1f MB\n", $baseline);
printf("  Peak RSS:                  %.1f MB\n", $peak);
printf("  Final RSS:                 %.1f MB\n", $final);
printf("  Search growth (200→5000):  %+.1f MB\n", $searchGrowth);
printf("  After close+reopen:        %.1f MB\n", $timeline[12]['rss_mb']);
printf("  Post-reopen searches:      %.1f MB\n", $timeline[13]['rss_mb']);
echo "\n";

if ($searchGrowth < 20) {
    echo "  PASS: Search memory is stable (growth < 20MB over 5000 searches)\n";
} else {
    echo "  WARN: Search memory grew {$searchGrowth}MB over 5000 searches\n";
}

if ($peak < 200) {
    echo "  PASS: Peak RSS < 200MB (without dataset in memory)\n";
} else {
    printf("  INFO: Peak RSS %.1fMB (dataset loading adds ~150MB to PHP heap)\n", $peak);
}

// Cleanup
foreach (glob(DB_PATH . '*') as $f) @unlink($f);
