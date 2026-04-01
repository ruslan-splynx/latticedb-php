<?php

/**
 * FTS Stress Test — reproduces LatticeDB unit test, then scales up to find the breaking point.
 *
 * Based on: tests/unit/search_test.zig "fts: limit caps results correctly"
 *
 * Usage: php benchmark/fts_stress_test.php
 */

ini_set('memory_limit', '512M');
set_time_limit(0);

require_once __DIR__ . '/helpers.php';

use LatticeDB\Database;

const DB_PATH = __DIR__ . '/data/fts_stress.ltdb';

foreach (glob(DB_PATH . '*') as $f) @unlink($f);

$db = Database::open(DB_PATH, ['create' => true]);

// ============================================================================
// Test 1: Reproduce their unit test — 20 documents (should pass)
// ============================================================================

section("Test 1: 20 documents (reproducing upstream unit test)");

$nodeIds = [];
$db->transaction(function ($txn) use (&$nodeIds) {
    for ($i = 1; $i <= 20; $i++) {
        $nodeId = $txn->graph()->createNode('Doc');
        $txn->fts()->index($nodeId, "common term document number {$i}");
        $nodeIds[] = $nodeId;
    }
});

$results = $db->fts()->search('common', limit: 5);
$count = count($results);
echo "  Indexed: 20 docs\n";
echo "  Search 'common' limit 5: got {$count} results\n";
echo "  " . ($count === 5 ? "PASS" : "FAIL (expected 5)") . "\n";

// ============================================================================
// Test 2: Scale up in steps — find where it breaks
// ============================================================================

section("Test 2: Scale up — indexing in batches, timing each");

// Reset DB
$db->close();
foreach (glob(DB_PATH . '*') as $f) @unlink($f);
$db = Database::open(DB_PATH, ['create' => true]);

$batchSize = 100;
$totalIndexed = 0;
$maxDocs = 5000;

$texts = [
    'Internet connection drops every few minutes and then comes back',
    'How can I pay my monthly bill using a credit card',
    'Router is blinking red light since the thunderstorm last night',
    'WiFi signal is very weak in the rooms far from the router',
    'Speed test shows only half of the advertised download speed',
    'I want to upgrade my internet plan to the fastest available',
    'VoIP phone has no dial tone and cannot make any calls',
    'Websites are not resolving and I get DNS error messages',
    'My fiber optic cable was physically damaged by construction work',
    'I want to cancel my contract because I am moving to another city',
];

while ($totalIndexed < $maxDocs) {
    $t = timer_start();
    $db->transaction(function ($txn) use ($batchSize, $totalIndexed, $texts) {
        for ($i = 0; $i < $batchSize; $i++) {
            $id = $totalIndexed + $i;
            $text = "Ticket #{$id}: " . $texts[$id % count($texts)];
            $nodeId = $txn->graph()->createNode('Doc');
            $txn->fts()->index($nodeId, $text);
        }
    });
    $ms = timer_ms($t);
    $totalIndexed += $batchSize;

    printf("  %4d docs: batch took %7.0fms (%.2fms/doc)\n", $totalIndexed, $ms, $ms / $batchSize);

    // Bail if a batch takes > 10 seconds — something is wrong
    if ($ms > 10000) {
        echo "\n  BAIL: batch took > 10s — FTS is hanging/degrading badly\n";
        echo "  Breaking point: ~{$totalIndexed} documents\n";
        break;
    }
}

if ($totalIndexed >= $maxDocs) {
    echo "\n  All {$maxDocs} documents indexed successfully!\n";
}

// Quick search test
$t = timer_start();
$results = $db->fts()->search('internet connection', limit: 10);
$ms = timer_ms($t);
echo "\n  Search after {$totalIndexed} docs: {$ms}ms, " . count($results) . " results\n";

$db->close();
foreach (glob(DB_PATH . '*') as $f) @unlink($f);
