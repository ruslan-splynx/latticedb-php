<?php

/**
 * LatticeDB Benchmark — focus on insert, search, recall.
 *
 * Tests:
 *   1. Batch Insert 10k (various batch sizes)
 *   2. Search Latency (200 queries x 3 passes)
 *   3. Recall@10 (multiple efSearch values to find optimal)
 *   4. Full-Text Search (index 10k + search + fuzzy)
 *   5. Single Insert 1000
 *   6. Resource Usage
 *
 * Usage: php benchmark/latticedb_benchmark.php
 */

ini_set('memory_limit', '2G');
set_time_limit(0);

require_once __DIR__ . '/helpers.php';

use LatticeDB\Database;

const DATA_DIR = __DIR__ . '/data';
const RESULTS_DIR = __DIR__ . '/results';
const DB_PATH = __DIR__ . '/data/benchmark.ltdb';
const TOP_K = 10;

// ============================================================================
echo "=== LatticeDB Benchmark ===\n\n";

echo "Loading dataset... ";
$dataset = json_decode(file_get_contents(DATA_DIR . '/dataset_10k.json'), true);
$queries = json_decode(file_get_contents(DATA_DIR . '/queries_200.json'), true);
$groundTruth = json_decode(file_get_contents(DATA_DIR . '/ground_truth_200.json'), true);
echo count($dataset) . " records, " . count($queries) . " queries, " . count($dataset[0]['vector']) . "-dim\n";

$dims = count($dataset[0]['vector']);
$results = ['timestamp' => date('c'), 'dimensions' => $dims, 'tests' => []];

foreach (glob(DB_PATH . '*') as $f) @unlink($f);

// ============================================================================
// Test 1: Batch Insert 10k
// ============================================================================

section("Test 1: Batch Insert 10k records");

$db = Database::open(DB_PATH, [
    'create' => true,
    'enable_vector' => true,
    'vector_dimensions' => $dims,
]);

$batchSizes = [100, 500, 1000];
$insertResults = [];

foreach ($batchSizes as $batchSize) {
    foreach (glob(DB_PATH . '*') as $f) @unlink($f);
    $db->close();
    $db = Database::open(DB_PATH, [
        'create' => true,
        'enable_vector' => true,
        'vector_dimensions' => $dims,
    ]);

    $start = timer_start();
    foreach (array_chunk($dataset, $batchSize) as $batch) {
        $db->transaction(function ($txn) use ($batch) {
            $items = [];
            foreach ($batch as $record) {
                $items[] = ['label' => 'Ticket', 'vector' => $record['vector']];
            }
            $nodeIds = $txn->vectors()->batchInsert($items);

            foreach ($nodeIds as $i => $nodeId) {
                $txn->graph()->setProperty($nodeId, 'record_id', $batch[$i]['id']);
                $txn->graph()->setProperty($nodeId, 'text', $batch[$i]['text']);
                $txn->graph()->setProperty($nodeId, 'category', $batch[$i]['category']);
            }
        });
    }

    $elapsed = timer_s($start);
    $rps = count($dataset) / $elapsed;
    $insertResults["batch_{$batchSize}"] = [
        'time_s' => round($elapsed, 2),
        'rps' => round($rps),
    ];
    printf("  Batch %4d: %.2fs (%d rec/s)\n", $batchSize, $elapsed, $rps);
}

$results['tests']['batch_insert'] = $insertResults;

// ============================================================================
// Test 2: Search Latency (3 passes)
// ============================================================================

section("Test 2: Search Latency (200 queries x 3 passes)");

$db->close();
foreach (glob(DB_PATH . '*') as $f) @unlink($f);
$db = Database::open(DB_PATH, [
    'create' => true,
    'enable_vector' => true,
    'vector_dimensions' => $dims,
]);

echo "Inserting 10k records... ";
$start = timer_start();
foreach (array_chunk($dataset, 1000) as $batch) {
    $db->transaction(function ($txn) use ($batch) {
        $items = [];
        foreach ($batch as $r) {
            $items[] = ['label' => 'Ticket', 'vector' => $r['vector']];
        }
        $txn->vectors()->batchInsert($items);
    });
}
printf("done (%.1fs)\n\n", timer_s($start));

$allLatencies = [];
for ($pass = 1; $pass <= 3; $pass++) {
    $latencies = [];
    foreach ($queries as $q) {
        $t = timer_start();
        $db->vectors()->search(vector: $q['vector'], k: TOP_K, efSearch: 200);
        $latencies[] = timer_ms($t);
    }
    sort($latencies);
    printf("  Pass %d: p50=%.2fms  p95=%.2fms  p99=%.2fms  avg=%.2fms\n",
        $pass, percentile($latencies, 50), percentile($latencies, 95),
        percentile($latencies, 99), array_sum($latencies) / count($latencies));
    $allLatencies = array_merge($allLatencies, $latencies);
}

sort($allLatencies);
$results['tests']['search_latency'] = [
    'queries' => count($queries) * 3,
    'p50_ms' => round(percentile($allLatencies, 50), 2),
    'p95_ms' => round(percentile($allLatencies, 95), 2),
    'p99_ms' => round(percentile($allLatencies, 99), 2),
    'avg_ms' => round(array_sum($allLatencies) / count($allLatencies), 2),
];

// ============================================================================
// Test 3: Recall@10 with different efSearch values
// ============================================================================

section("Test 3: Recall@10 vs brute-force (multiple efSearch)");

$db->close();
foreach (glob(DB_PATH . '*') as $f) @unlink($f);
$db = Database::open(DB_PATH, [
    'create' => true,
    'enable_vector' => true,
    'vector_dimensions' => $dims,
]);

$nodeToRecord = [];
echo "Inserting 10k records... ";
$start = timer_start();
foreach (array_chunk($dataset, 1000) as $batch) {
    $db->transaction(function ($txn) use ($batch, &$nodeToRecord) {
        $items = [];
        foreach ($batch as $r) {
            $items[] = ['label' => 'Ticket', 'vector' => $r['vector']];
        }
        $nodeIds = $txn->vectors()->batchInsert($items);
        foreach ($nodeIds as $i => $nodeId) {
            $nodeToRecord[$nodeId] = $batch[$i]['id'];
        }
    });
}
printf("done (%.1fs)\n\n", timer_s($start));

$efSearchValues = [0, 50, 100, 200, 400, 800];
$recallResults = [];

foreach ($efSearchValues as $ef) {
    $totalRecall = 0;

    foreach ($queries as $qi => $q) {
        $searchResults = $db->vectors()->search(vector: $q['vector'], k: TOP_K, efSearch: $ef);
        $returnedIds = [];
        foreach ($searchResults as $match) {
            if (isset($nodeToRecord[$match->nodeId])) {
                $returnedIds[] = $nodeToRecord[$match->nodeId];
            }
        }

        $gtIds = array_column($groundTruth[$qi]['top_k'], 'id');
        $overlap = count(array_intersect($returnedIds, $gtIds));
        $totalRecall += $overlap / count($gtIds);
    }

    $avgRecall = $totalRecall / count($queries);
    $recallResults["ef_{$ef}"] = round($avgRecall, 4);
    printf("  efSearch=%4d → Recall@%d: %.4f\n", $ef, TOP_K, $avgRecall);
}

$results['tests']['recall'] = $recallResults;

// ============================================================================
// Test 4: Full-Text Search
// ============================================================================

section("Test 4: Full-Text Search (10k docs)");

echo "Indexing 10k records for FTS... ";
$start = timer_start();
$recordToNode = array_flip($nodeToRecord);
foreach (array_chunk($dataset, 1000) as $batch) {
    $db->transaction(function ($txn) use ($batch, $recordToNode) {
        foreach ($batch as $r) {
            if (isset($recordToNode[$r['id']])) {
                $txn->fts()->index($recordToNode[$r['id']], $r['text']);
            }
        }
    });
}
$ftsIndexTime = timer_s($start);
printf("done (%.1fs, %.2fms/doc)\n\n", $ftsIndexTime, $ftsIndexTime / count($dataset) * 1000);

$ftsQueries = ['internet connection problems', 'billing payment issue', 'router hardware failure',
    'wifi signal weak', 'speed test slow', 'fiber optic installation',
    'email not working', 'VoIP phone no dial tone', 'DNS resolution error', 'contract cancellation',
    'modem keeps restarting', 'plan upgrade options', 'static IP address setup',
    'customer portal login', 'TV channels freezing', 'network outage in area',
    'slow download speed', 'fiber cable damaged', 'cancel my contract', 'mobile data not working'];

$ftsLatencies = [];
foreach ($ftsQueries as $fq) {
    $t = timer_start();
    $ftsResults = $db->fts()->search($fq, limit: 10);
    $ftsLatencies[] = timer_ms($t);
}

sort($ftsLatencies);
printf("  FTS search (20 queries):\n");
printf("    p50=%.2fms  p95=%.2fms  avg=%.2fms\n",
    percentile($ftsLatencies, 50), percentile($ftsLatencies, 95),
    array_sum($ftsLatencies) / count($ftsLatencies));

// Fuzzy search
$fuzzyLatencies = [];
$fuzzyQueries = ['intenet conection', 'billin paymnt', 'routr hardwre', 'wfi sgnl', 'spd tst'];
foreach ($fuzzyQueries as $fq) {
    $t = timer_start();
    $db->fts()->searchFuzzy($fq, limit: 10, maxDistance: 2, minTermLength: 4);
    $fuzzyLatencies[] = timer_ms($t);
}
sort($fuzzyLatencies);
printf("  Fuzzy search (5 queries):\n");
printf("    p50=%.2fms  p95=%.2fms  avg=%.2fms\n",
    percentile($fuzzyLatencies, 50), percentile($fuzzyLatencies, 95),
    array_sum($fuzzyLatencies) / count($fuzzyLatencies));

$results['tests']['fts'] = [
    'index_time_s' => round($ftsIndexTime, 2),
    'index_ms_per_doc' => round($ftsIndexTime / count($dataset) * 1000, 2),
    'search_p50_ms' => round(percentile($ftsLatencies, 50), 2),
    'search_p95_ms' => round(percentile($ftsLatencies, 95), 2),
    'fuzzy_p50_ms' => round(percentile($fuzzyLatencies, 50), 2),
    'fuzzy_p95_ms' => round(percentile($fuzzyLatencies, 95), 2),
];

// ============================================================================
// Test 5: Single Insert 1000
// ============================================================================

section("Test 5: Single Insert (1000 records one-at-a-time)");

$singleLatencies = [];
$start = timer_start();

$db->transaction(function ($txn) use ($dataset, &$singleLatencies) {
    for ($i = 0; $i < 1000; $i++) {
        $t = timer_start();
        $nodeId = $txn->graph()->createNode('Single', ['record_id' => $dataset[$i]['id']]);
        $txn->vectors()->setVector($nodeId, 'embedding', $dataset[$i]['vector']);
        $singleLatencies[] = timer_ms($t);
    }
});

sort($singleLatencies);
$singleTime = timer_s($start);
printf("  1000 single inserts: %.2fs (%.0f rec/s)\n", $singleTime, 1000 / $singleTime);
printf("  p50=%.2fms  p95=%.2fms  p99=%.2fms\n",
    percentile($singleLatencies, 50), percentile($singleLatencies, 95), percentile($singleLatencies, 99));

$results['tests']['single_insert'] = [
    'count' => 1000,
    'time_s' => round($singleTime, 2),
    'rps' => round(1000 / $singleTime),
    'p50_ms' => round(percentile($singleLatencies, 50), 2),
    'p95_ms' => round(percentile($singleLatencies, 95), 2),
    'p99_ms' => round(percentile($singleLatencies, 99), 2),
];

// ============================================================================
// Test 5: Resource Usage
// ============================================================================

section("Test 6: Resource Usage");

$dbFiles = glob(DB_PATH . '*');
$diskUsage = 0;
foreach ($dbFiles as $f) {
    $diskUsage += filesize($f);
}
$phpMemory = memory_get_peak_usage(true);

printf("  Database size: %s\n", format_bytes($diskUsage));
printf("  PHP peak memory: %s\n", format_bytes($phpMemory));
printf("  LatticeDB version: %s\n", Database::version());

$results['tests']['resources'] = [
    'disk_bytes' => $diskUsage,
    'disk_human' => format_bytes($diskUsage),
    'php_peak_memory_bytes' => $phpMemory,
    'php_peak_memory_human' => format_bytes($phpMemory),
    'version' => Database::version(),
];

// ============================================================================
// Save & Summary
// ============================================================================

$db->close();

if (!is_dir(RESULTS_DIR)) mkdir(RESULTS_DIR, 0755, true);
$resultsFile = RESULTS_DIR . '/benchmark_' . date('Ymd_His') . '.json';
file_put_contents($resultsFile, json_encode($results, JSON_PRETTY_PRINT));

section("Benchmark Complete");
echo "Results saved: {$resultsFile}\n\n";

echo "Summary:\n";
echo str_repeat('-', 55) . "\n";
printf("  Batch insert (best):    %d rec/s\n", max(array_column($insertResults, 'rps')));
printf("  Search p95 (ef=200):    %.2f ms\n", $results['tests']['search_latency']['p95_ms']);
foreach ($recallResults as $ef => $recall) {
    printf("  Recall@10 %-12s  %.4f\n", "({$ef}):", $recall);
}
printf("  FTS index:              %.2f ms/doc\n", $results['tests']['fts']['index_ms_per_doc']);
printf("  FTS search p50:         %.2f ms\n", $results['tests']['fts']['search_p50_ms']);
printf("  FTS fuzzy p50:          %.2f ms\n", $results['tests']['fts']['fuzzy_p50_ms']);
printf("  Single insert p95:      %.2f ms\n", $results['tests']['single_insert']['p95_ms']);
printf("  Database size:          %s\n", format_bytes($diskUsage));
printf("  PHP peak memory:        %s\n", format_bytes($phpMemory));
echo str_repeat('-', 55) . "\n";
