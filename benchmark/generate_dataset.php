<?php

/**
 * Dataset Generator for LatticeDB Benchmark
 *
 * Generates pre-embedded dataset using Ollama nomic-embed-text (768-dim).
 * Run once, then use latticedb_benchmark.php many times.
 *
 * Output files (in benchmark/data/):
 *   dataset_10k.json       — 10,000 records with embeddings
 *   queries_200.json       — 200 query vectors
 *   ground_truth_200.json  — brute-force top-10 per query
 *
 * Usage: php benchmark/generate_dataset.php
 */

ini_set('memory_limit', '2G');
set_time_limit(0);

require_once __DIR__ . '/helpers.php';

const DATASET_SIZE = 10000;
const QUERY_COUNT = 200;
const TOP_K = 10;
const DATA_DIR = __DIR__ . '/data';

// 20 categories, 5 templates each = 100 unique base texts
$categoryTemplates = [
    'network' => [
        'Internet connection drops every few minutes and then comes back',
        'Network is completely unreachable since this morning',
        'Packet loss is very high on my connection causing timeouts',
        'My network cable shows no link light on the router port',
        'Connection speed is much lower than what I am paying for',
    ],
    'billing' => [
        'How can I pay my monthly bill using a credit card',
        'I was charged twice for the same invoice this month',
        'My account balance shows incorrect amount after payment',
        'I need a receipt for my last three payments for tax purposes',
        'The automatic payment from my bank account did not go through',
    ],
    'hardware' => [
        'Router is blinking red light since the thunderstorm last night',
        'My modem keeps restarting every hour by itself',
        'The power adapter for my router stopped working completely',
        'ONT device shows all lights off even though power is connected',
        'Router overheats and shuts down after a few hours of usage',
    ],
    'plans' => [
        'I want to upgrade my internet plan to the fastest available',
        'What plans do you offer for small business customers',
        'Can I downgrade my plan without paying a cancellation fee',
        'Is there a family bundle that includes internet and TV service',
        'What is the difference between your premium and standard plans',
    ],
    'gaming' => [
        'High ping and lag spikes in online games during evening hours',
        'Call of Duty keeps disconnecting me from game servers',
        'My NAT type shows as strict and I cannot join multiplayer',
        'Game downloads are extremely slow compared to speed test results',
        'Fortnite shows constant packet loss making it unplayable',
    ],
    'fiber' => [
        'My fiber optic cable was physically damaged by construction work',
        'When will fiber internet be available in my neighborhood',
        'The fiber connector on the wall outlet is broken and needs repair',
        'Fiber connection shows red light on the optical terminal unit',
        'I want to switch from copper DSL to fiber optic connection',
    ],
    'ip_config' => [
        'I need a static IP address configured for my home server',
        'My device is not getting an IP address from DHCP server',
        'There is an IP address conflict on my local network',
        'How do I set up port forwarding for my security cameras',
        'I need to change my public IP address for security reasons',
    ],
    'general' => [
        'Where is your nearest customer service office located',
        'What are the working hours of your technical support team',
        'I need to update my contact information and email address',
        'How do I contact your billing department for invoice questions',
        'Can you provide a reference letter confirming my account status',
    ],
    'portal' => [
        'I cannot log in to the customer self-service portal',
        'The portal shows error when I try to view my usage statistics',
        'How do I reset my customer portal password',
        'Portal payment page does not load properly in my browser',
        'I cannot download my invoices from the customer portal',
    ],
    'tv' => [
        'TV channels are freezing and pixelating on my set-top box',
        'I am missing several channels from my TV subscription package',
        'The electronic program guide is not showing correct schedule',
        'My set-top box remote control stopped working after update',
        'TV streaming quality is very poor with constant buffering',
    ],
    'wifi' => [
        'WiFi signal is very weak in the rooms far from the router',
        'Cannot connect my new laptop to the wireless network',
        'WiFi keeps disconnecting on all devices every few minutes',
        'The WiFi password was changed and I do not know the new one',
        'I need help setting up a WiFi mesh system in my house',
    ],
    'email' => [
        'I am not receiving any emails on my ISP email account',
        'Outgoing emails are being rejected by the mail server',
        'My email storage is full and I cannot receive new messages',
        'How do I configure my ISP email in Outlook or Thunderbird',
        'I keep getting spam emails despite having filters enabled',
    ],
    'voip' => [
        'VoIP phone has no dial tone and cannot make any calls',
        'Voice quality is very poor with echo and choppy audio',
        'Incoming calls go straight to voicemail without ringing',
        'I need to set up call forwarding to my mobile number',
        'My VoIP number is not working after the router was replaced',
    ],
    'dns' => [
        'Websites are not resolving and I get DNS error messages',
        'Some specific websites cannot be reached but others work fine',
        'DNS lookup takes very long time causing slow page loading',
        'I want to use custom DNS servers instead of the default ones',
        'After changing DNS settings the internet stopped working completely',
    ],
    'speed' => [
        'Speed test shows only half of the advertised download speed',
        'Upload speed is extremely slow even though download is fine',
        'Internet speed drops significantly during peak evening hours',
        'Speed is normal on ethernet but very slow over WiFi connection',
        'After the technician visit my speed became worse than before',
    ],
    'outage' => [
        'There is a complete internet outage in my entire neighborhood',
        'Service has been down for over six hours with no information',
        'Is there a planned maintenance scheduled for my area tonight',
        'The outage map shows my area is affected but no estimated time',
        'Internet went down after the power outage and has not recovered',
    ],
    'installation' => [
        'I want to schedule a new internet installation at my address',
        'The technician did not show up for the scheduled installation',
        'Installation was incomplete and I still have no internet access',
        'How long does it take to install fiber optic at a new location',
        'I need to move my internet service to a new apartment address',
    ],
    'contract' => [
        'I want to cancel my contract because I am moving to another city',
        'What is the early termination fee for my current contract',
        'My contract expired but I was not notified about renewal options',
        'I need a copy of my signed service agreement for my records',
        'Can I transfer my contract to a family member living at same address',
    ],
    'mobile' => [
        'My mobile data connection is very slow in my area',
        'I cannot send or receive SMS messages on my mobile plan',
        'The mobile app does not show my current data usage correctly',
        'I need to activate international roaming for my upcoming trip',
        'Mobile hotspot feature is not working on my phone after update',
    ],
    'monitoring' => [
        'I want to set up bandwidth usage alerts on my account',
        'The monitoring dashboard shows my router as offline incorrectly',
        'How can I check my historical bandwidth usage for last month',
        'SNMP monitoring is not collecting data from my network device',
        'I need to export my usage reports in CSV format for analysis',
    ],
];

$queryTemplates = [
    'network' => ['internet keeps disconnecting randomly', 'my connection is not working at all', 'losing packets on my broadband line', 'ethernet port has no activity light', 'bandwidth is less than subscribed', 'unstable network connection issues', 'cannot access any websites from home', 'constant network drops throughout the day', 'internet works intermittently with gaps', 'slow and unreliable data connection'],
    'billing' => ['pay invoice with card online', 'duplicate charge on my account', 'wrong balance after making payment', 'need proof of payment for taxes', 'autopay failed from checking account', 'billing dispute about extra charges', 'how to check my outstanding balance', 'payment was made but not reflected', 'monthly fee increased without notice', 'refund for service downtime period'],
    'hardware' => ['router shows red indicator light', 'modem reboots on its own repeatedly', 'power supply for networking equipment broken', 'optical terminal has no lights at all', 'equipment getting too hot and failing', 'need replacement device for my connection', 'blinking orange light on home gateway', 'hardware malfunction after power surge', 'router firmware needs to be updated', 'device warranty claim for defective unit'],
    'plans' => ['change to higher speed package', 'business internet subscription options', 'reduce my plan without penalties', 'combined internet TV deal available', 'compare different service tiers offered', 'cheapest plan for basic browsing', 'student discount on internet service', 'temporary plan upgrade for one month', 'unlimited data plan without throttling', 'promotional offers for existing customers'],
    'gaming' => ['latency too high for online gaming', 'getting kicked from game matches', 'NAT configuration for multiplayer', 'game updates download very slowly', 'losing connection during competitive games', 'jitter affecting real-time gameplay', 'gaming console cannot connect to server', 'optimize connection for streaming games', 'ping spikes during online matches', 'need lower latency for esports'],
    'fiber' => ['broken fiber cable at my house', 'fiber availability check for my street', 'damaged fiber wall connector needs fixing', 'optical line terminal flashing red', 'migrate from DSL to fiber technology', 'fiber installation cost and timeline', 'underground fiber cable was cut', 'single mode fiber patch cord replacement', 'GPON connection not establishing properly', 'fiber splitter in building needs service'],
    'ip_config' => ['assign fixed IP to my machine', 'no IP address being assigned automatically', 'two devices showing same IP conflict', 'forward port for remote access setup', 'request different public IP assignment', 'subnet mask configuration incorrect', 'IPv6 not working on my connection', 'DHCP lease time too short causing drops', 'reverse DNS setup for mail server', 'bridge mode configuration for own router'],
    'general' => ['find local service center address', 'support availability on weekends', 'update personal details on file', 'reach accounts department by phone', 'confirmation letter of active service', 'company contact email for complaints', 'schedule callback from support agent', 'language options for customer service', 'accessibility features for disabled users', 'provide feedback about recent experience'],
    'portal' => ['login page not accepting credentials', 'usage stats showing error on web portal', 'forgot password for online account', 'payment section broken in browser', 'unable to access billing documents online', 'two factor authentication not sending code', 'session expires too quickly on portal', 'mobile version of portal not loading', 'change notification preferences online', 'portal shows wrong service information'],
    'tv' => ['television picture breaking up and freezing', 'missing channels from subscription lineup', 'EPG program guide displaying wrong times', 'remote not responding after software update', 'IPTV streaming keeps stopping to buffer', 'no signal on certain TV channels', 'recording function not working properly', 'set top box stuck on boot screen', 'audio out of sync with video playback', 'parental controls not blocking content'],
    'wifi' => ['weak wireless signal in far rooms', 'new device will not join wifi network', 'wireless drops on all gadgets frequently', 'lost the current wifi access password', 'help with mesh wifi router setup', 'wifi range extender not connecting', 'slow speeds only on wireless devices', 'interference on 2.4ghz wifi band', 'guest wifi network setup instructions', 'smart home devices losing wifi connection'],
    'email' => ['not getting emails in my inbox', 'outbound mail bouncing back undelivered', 'mailbox quota exceeded no space left', 'set up email client with ISP mail', 'too much junk mail getting through filter', 'email attachment size limit too small', 'webmail interface not loading properly', 'email forwarding to another address', 'hacked email account need recovery', 'distribution list creation for business'],
    'voip' => ['phone line completely dead no tone', 'crackling noise and echo during calls', 'all calls going to voicemail directly', 'redirect calls to cell phone number', 'telephone stopped after equipment change', 'SIP registration failing on phone', 'one way audio problem on VoIP calls', 'fax machine not working over IP phone', 'international calling not enabled on line', 'phone number porting from another carrier'],
    'dns' => ['cannot resolve domain names in browser', 'certain sites unreachable but ping works', 'very slow name resolution on all devices', 'change to google or cloudflare DNS', 'no internet after DNS configuration change', 'NXDOMAIN errors for valid websites', 'DNS cache needs to be cleared', 'private DNS settings for mobile device', 'DNSSEC validation failures on some sites', 'local DNS server setup for home network'],
    'speed' => ['download rate half of what I pay for', 'upload throughput extremely low', 'performance degrades in evening time', 'wired fast but wireless connection slow', 'worse speeds after technician service call', 'speedtest results inconsistent every time', 'buffering video despite fast plan', 'throttling detected on certain websites', 'QoS settings for prioritizing traffic', 'speed guarantee and SLA for my plan'],
    'outage' => ['whole area has no internet service', 'prolonged downtime with no updates given', 'scheduled maintenance notification for tonight', 'outage reported but no restoration estimate', 'service not restored after power came back', 'fiber cut affecting multiple customers', 'intermittent outage lasting several days', 'emergency contact during service outage', 'compensation for extended service interruption', 'backup internet during planned maintenance'],
    'installation' => ['book new service setup appointment', 'missed installation window by technician', 'partial setup done internet still not working', 'timeline for new fiber installation project', 'relocate existing service to new home', 'pre-installation site survey scheduling', 'installation requirements for business premises', 'self-install kit instructions and guide', 'additional ethernet outlet installation needed', 'underground cable laying for new connection'],
    'contract' => ['terminate service due to relocation', 'penalty for breaking agreement early', 'auto-renewed without notification to me', 'get copy of original service contract', 'transfer account ownership to relative', 'contract minimum term length question', 'negotiate better price on renewal', 'cooling off period for new contract', 'bundled services contract modification', 'legal terms about service level guarantee'],
    'mobile' => ['cellular data very slow in my region', 'text messages not being delivered', 'app shows wrong data consumption info', 'enable roaming for travel abroad', 'tethering feature broken after phone update', 'no mobile coverage at my location', 'switch to eSIM from physical card', 'mobile voicemail setup and access', 'data rollover to next billing cycle', 'family plan sharing data between lines'],
    'monitoring' => ['set up usage threshold notifications', 'dashboard incorrectly reports device offline', 'view past bandwidth consumption history', 'SNMP polling not working for equipment', 'download usage data as spreadsheet file', 'real time traffic monitoring for network', 'configure alerting for service degradation', 'network topology map not showing correctly', 'API access for automated monitoring', 'integrate with external monitoring platform'],
];

// ============================================================================
echo "=== LatticeDB Benchmark Dataset Generator ===\n";
echo "Using Ollama nomic-embed-text (768-dim)\n\n";

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// Check Ollama
echo "Checking Ollama... ";
try {
    $testVec = get_embedding("test");
    echo "OK (" . count($testVec) . "-dim)\n\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    echo "Make sure Ollama is running: ollama serve\n";
    exit(1);
}

$dims = count($testVec);
$categories = array_keys($categoryTemplates);

// --- Phase 1: Generate dataset records ---
section("Phase 1: Generating " . DATASET_SIZE . " records");

$dataset = [];
$startTime = timer_start();
$recordId = 0;
$perCategory = (int)ceil(DATASET_SIZE / count($categories));

foreach ($categories as $category) {
    $templates = $categoryTemplates[$category];
    $templateIdx = 0;

    for ($i = 0; $i < $perCategory && $recordId < DATASET_SIZE; $i++) {
        $template = $templates[$templateIdx % count($templates)];
        $templateIdx++;
        $text = "Ticket #{$recordId}: {$template}";

        $vector = normalize_vector(get_embedding($text));

        $dataset[] = [
            'id' => $recordId,
            'text' => $text,
            'category' => $category,
            'vector' => $vector,
        ];

        $recordId++;

        if ($recordId % 100 === 0) {
            $elapsed = timer_s($startTime);
            $rate = $recordId / $elapsed;
            $eta = ($DATASET_SIZE - $recordId) / $rate;
            printf("  %d/%d records (%.1f rec/s, ETA: %s)\n",
                $recordId, DATASET_SIZE, $rate, gmdate('i:s', (int)$eta));
        }
    }
}

$datasetTime = timer_s($startTime);
printf("\nDataset: %d records in %.1fs (%.1f rec/s)\n", count($dataset), $datasetTime, count($dataset) / $datasetTime);

$datasetFile = DATA_DIR . '/dataset_10k.json';
file_put_contents($datasetFile, json_encode($dataset));
echo "Saved: {$datasetFile} (" . format_bytes(filesize($datasetFile)) . ")\n";

// --- Phase 2: Generate query vectors ---
section("Phase 2: Generating " . QUERY_COUNT . " queries");

$queries = [];
$queryId = 0;
$startTime = timer_start();

foreach ($categories as $category) {
    foreach ($queryTemplates[$category] as $queryText) {
        if ($queryId >= QUERY_COUNT) break 2;

        $queries[] = [
            'id' => $queryId,
            'text' => $queryText,
            'category' => $category,
            'vector' => normalize_vector(get_embedding($queryText)),
        ];
        $queryId++;

        if ($queryId % 20 === 0) {
            echo "  {$queryId}/{$QUERY_COUNT} queries\n";
        }
    }
}

$queryTime = timer_s($startTime);
printf("\nQueries: %d in %.1fs\n", count($queries), $queryTime);

$queriesFile = DATA_DIR . '/queries_200.json';
file_put_contents($queriesFile, json_encode($queries));
echo "Saved: {$queriesFile} (" . format_bytes(filesize($queriesFile)) . ")\n";

// --- Phase 3: Compute ground truth ---
section("Phase 3: Computing ground truth (brute-force top-" . TOP_K . ")");
echo count($queries) . " x " . count($dataset) . " = " . number_format(count($queries) * count($dataset)) . " dot products...\n\n";

$startTime = timer_start();
$groundTruth = [];
$datasetVectors = array_column($dataset, 'vector');
$datasetIds = array_column($dataset, 'id');

foreach ($queries as $qi => $query) {
    $qv = $query['vector'];
    $scores = [];

    foreach ($datasetVectors as $di => $dv) {
        $scores[$di] = dot_product($qv, $dv);
    }

    arsort($scores);

    $topK = [];
    $rank = 0;
    foreach ($scores as $di => $score) {
        if ($rank >= TOP_K) break;
        $topK[] = ['id' => $datasetIds[$di], 'score' => round($score, 6)];
        $rank++;
    }

    $groundTruth[] = ['query_id' => $query['id'], 'top_k' => $topK];

    if (($qi + 1) % 20 === 0) {
        printf("  %d/%d queries (%.1fs)\n", $qi + 1, count($queries), timer_s($startTime));
    }
}

$gtTime = timer_s($startTime);
printf("\nGround truth computed in %.1fs\n", $gtTime);

$gtFile = DATA_DIR . '/ground_truth_200.json';
file_put_contents($gtFile, json_encode($groundTruth));
echo "Saved: {$gtFile} (" . format_bytes(filesize($gtFile)) . ")\n";

// --- Summary ---
section("Generation Complete");
printf("  Dataset:      %d records (%s)\n", count($dataset), format_bytes(filesize($datasetFile)));
printf("  Queries:      %d vectors (%s)\n", count($queries), format_bytes(filesize($queriesFile)));
printf("  Ground truth: %d x top-%d (%s)\n", count($groundTruth), TOP_K, format_bytes(filesize($gtFile)));
printf("  Dimensions:   %d (nomic-embed-text)\n", $dims);
printf("  Total time:   %.1fs\n", $datasetTime + $queryTime + $gtTime);
echo "\nReady: php benchmark/latticedb_benchmark.php\n";
