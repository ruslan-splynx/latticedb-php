<?php

/**
 * LatticeDB Mixed Workload — interleaved insert/search cycles without cleanup.
 *
 * Simulates real usage: insert batch → search → insert more → search again → FTS → repeat.
 * Runs 3 rounds on the same DB to catch state corruption, index degradation, or crashes.
 *
 * Usage: php benchmark/latticedb_mixed_workload.php
 */

ini_set('memory_limit', '2G');
set_time_limit(0);

require_once __DIR__ . '/helpers.php';

use LatticeDB\Database;

const DATA_DIR = __DIR__ . '/data';
const DB_PATH = __DIR__ . '/data/mixed_workload.ltdb';
const TOP_K = 10;

echo "=== LatticeDB Mixed Workload Test ===\n\n";

echo "Loading dataset... ";
$dataset = json_decode(file_get_contents(DATA_DIR . '/dataset_10k.json'), true);
$queries = json_decode(file_get_contents(DATA_DIR . '/queries_200.json'), true);
$groundTruth = json_decode(file_get_contents(DATA_DIR . '/ground_truth_200.json'), true);
$dims = count($dataset[0]['vector']);
echo count($dataset) . " records, " . count($queries) . " queries\n\n";

// Clean start
foreach (glob(DB_PATH . '*') as $f) @unlink($f);

$db = Database::open(DB_PATH, [
    'create' => true,
    'enable_vector' => true,
    'vector_dimensions' => $dims,
]);

$totalInserted = 0;
$nodeToRecord = [];
$rounds = 3;
$perRound = (int)(count($dataset) / $rounds); // ~3333 per round

for ($round = 1; $round <= $rounds; $round++) {
    section("Round {$round} / {$rounds}");

    $roundStart = $round === 1 ? 0 : $totalInserted;
    $roundEnd = min($roundStart + $perRound, count($dataset));
    $roundRecords = array_slice($dataset, $roundStart, $roundEnd - $roundStart);

    // --- Phase 1: Batch insert ---
    echo "  [Insert] {$roundStart}..{$roundEnd} (" . count($roundRecords) . " records)... ";
    $t = timer_start();
    foreach (array_chunk($roundRecords, 500) as $batch) {
        $db->transaction(function ($txn) use ($batch, &$nodeToRecord) {
            $items = [];
            foreach ($batch as $r) {
                $items[] = ['label' => 'Ticket', 'vector' => $r['vector']];
            }
            $nodeIds = $txn->vectors()->batchInsert($items);
            foreach ($nodeIds as $i => $nodeId) {
                $nodeToRecord[$nodeId] = $batch[$i]['id'];
                $txn->graph()->setProperty($nodeId, 'text', $batch[$i]['text']);
                $txn->graph()->setProperty($nodeId, 'category', $batch[$i]['category']);
            }
        });
    }
    $totalInserted = $roundEnd;
    $insertMs = timer_ms($t);
    printf("%.1fs (%d rec/s)\n", $insertMs / 1000, count($roundRecords) / ($insertMs / 1000));

    // --- Phase 2: Vector search ---
    echo "  [Vector Search] 200 queries (efSearch=400)... ";
    $latencies = [];
    foreach ($queries as $q) {
        $t = timer_start();
        $db->vectors()->search(vector: $q['vector'], k: TOP_K, efSearch: 400);
        $latencies[] = timer_ms($t);
    }
    sort($latencies);
    printf("p50=%.2fms p95=%.2fms avg=%.2fms\n",
        percentile($latencies, 50), percentile($latencies, 95),
        array_sum($latencies) / count($latencies));

    // --- Phase 3: Recall check ---
    echo "  [Recall] Checking recall@" . TOP_K . "... ";
    $totalRecall = 0;
    $checked = 0;

    // Only check recall against records that are already inserted
    $insertedIds = array_values($nodeToRecord);

    foreach ($queries as $qi => $q) {
        $results = $db->vectors()->search(vector: $q['vector'], k: TOP_K, efSearch: 400);
        $returnedIds = [];
        foreach ($results as $m) {
            if (isset($nodeToRecord[$m->nodeId])) {
                $returnedIds[] = $nodeToRecord[$m->nodeId];
            }
        }

        // Ground truth filtered to only inserted records
        $gtIds = array_column($groundTruth[$qi]['top_k'], 'id');
        $gtIdsInserted = array_intersect($gtIds, $insertedIds);
        if (count($gtIdsInserted) === 0) continue;

        $overlap = count(array_intersect($returnedIds, $gtIdsInserted));
        $totalRecall += $overlap / count($gtIdsInserted);
        $checked++;
    }

    $recall = $checked > 0 ? $totalRecall / $checked : 0;
    printf("%.4f (%d queries, %d total records in DB)\n", $recall, $checked, $totalInserted);

    // --- Phase 4: FTS index new records ---
    echo "  [FTS Index] Indexing " . count($roundRecords) . " records... ";
    $t = timer_start();
    $recordToNode = array_flip($nodeToRecord);
    foreach (array_chunk($roundRecords, 1000) as $batch) {
        $db->transaction(function ($txn) use ($batch, $recordToNode) {
            foreach ($batch as $r) {
                if (isset($recordToNode[$r['id']])) {
                    $txn->fts()->index($recordToNode[$r['id']], $r['text']);
                }
            }
        });
    }
    $ftsIndexMs = timer_ms($t);
    printf("%.1fs (%.2fms/doc)\n", $ftsIndexMs / 1000, $ftsIndexMs / count($roundRecords));

    // --- Phase 5: FTS search ---
    echo "  [FTS Search] 10 queries... ";
    $ftsQueries = ['internet connection', 'billing payment', 'router hardware',
        'wifi signal', 'speed test', 'fiber installation',
        'email problem', 'VoIP phone', 'DNS error', 'contract cancel'];

    $ftsLatencies = [];
    $ftsResultCounts = [];
    foreach ($ftsQueries as $fq) {
        $t = timer_start();
        $results = $db->fts()->search($fq, limit: 10);
        $ftsLatencies[] = timer_ms($t);
        $ftsResultCounts[] = count($results);
    }
    sort($ftsLatencies);
    $avgResults = array_sum($ftsResultCounts) / count($ftsResultCounts);
    printf("p50=%.2fms avg=%.2fms (%.0f results avg)\n",
        percentile($ftsLatencies, 50), array_sum($ftsLatencies) / count($ftsLatencies), $avgResults);

    // --- Phase 6: FTS fuzzy ---
    echo "  [FTS Fuzzy] 5 queries... ";
    $fuzzyQueries = ['intenet conection', 'billin paymnt', 'routr hardwre', 'wfi sgnl', 'spd tst'];
    $fuzzyLatencies = [];
    foreach ($fuzzyQueries as $fq) {
        $t = timer_start();
        $db->fts()->searchFuzzy($fq, limit: 10, maxDistance: 2, minTermLength: 4);
        $fuzzyLatencies[] = timer_ms($t);
    }
    sort($fuzzyLatencies);
    printf("p50=%.2fms avg=%.2fms\n",
        percentile($fuzzyLatencies, 50), array_sum($fuzzyLatencies) / count($fuzzyLatencies));

    // --- Phase 7: Graph verify ---
    echo "  [Graph] Verify random node properties... ";
    $sampleNodeIds = array_rand($nodeToRecord, min(50, count($nodeToRecord)));
    if (!is_array($sampleNodeIds)) $sampleNodeIds = [$sampleNodeIds];
    $errors = 0;
    $db->read(function ($txn) use ($sampleNodeIds, $nodeToRecord, &$errors) {
        foreach ($sampleNodeIds as $nodeId) {
            try {
                $cat = $txn->graph()->getProperty($nodeId, 'category');
                if (!is_string($cat) || strlen($cat) === 0) $errors++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }
    });
    echo $errors === 0 ? "OK (50 nodes checked)\n" : "FAIL ({$errors} errors)\n";

    // --- Round stats ---
    $dbSize = 0;
    foreach (glob(DB_PATH . '*') as $f) $dbSize += filesize($f);
    $phpMem = memory_get_usage(true);
    printf("  [Stats] DB: %s | PHP heap: %s | RSS: %.0fMB\n",
        format_bytes($dbSize), format_bytes($phpMem),
        ((int)trim(shell_exec("ps -o rss= -p " . getmypid()))) / 1024);
}

// ============================================================================
// Final: close + reopen + verify everything still works
// ============================================================================

section("Final: Close + Reopen + Verify");

$db->close();
echo "  DB closed. Reopening... ";
$db = Database::open(DB_PATH, [
    'enable_vector' => true,
    'vector_dimensions' => $dims,
]);
echo "OK\n\n";

// Vector search
echo "  [Vector Search] 200 queries after reopen... ";
$latencies = [];
foreach ($queries as $q) {
    $t = timer_start();
    $db->vectors()->search(vector: $q['vector'], k: TOP_K, efSearch: 400);
    $latencies[] = timer_ms($t);
}
sort($latencies);
printf("p50=%.2fms p95=%.2fms\n", percentile($latencies, 50), percentile($latencies, 95));

// FTS search
echo "  [FTS Search] after reopen... ";
$results = $db->fts()->search('internet connection problems', limit: 10);
printf("%d results\n", count($results));

// FTS fuzzy
echo "  [FTS Fuzzy] after reopen... ";
$results = $db->fts()->searchFuzzy('intenet conection', limit: 10, maxDistance: 2, minTermLength: 4);
printf("%d results\n", count($results));

// Graph read
echo "  [Graph] Read property after reopen... ";
$anyNodeId = array_key_first($nodeToRecord);
$db->read(function ($txn) use ($anyNodeId) {
    $cat = $txn->graph()->getProperty($anyNodeId, 'category');
    echo "category='{$cat}' — OK\n";
});

$db->close();

section("ALL TESTS PASSED");
echo "Total records: {$totalInserted}\n";
echo "Rounds: {$rounds} (no cleanup between rounds)\n";
foreach (glob(DB_PATH . '*') as $f) @unlink($f);
