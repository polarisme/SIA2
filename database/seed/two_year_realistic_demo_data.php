<?php
declare(strict_types=1);

/**
 * Generates a realistic, internally-consistent two-year operational dataset
 * (2024-10-01 .. 2026-09-30) for the fictional "South Valley Poultry Farm"
 * (South Cotabato, Philippines), replacing the old pms/ais/integrated_demo_data.sql
 * content across the live `sia2` database.
 *
 * Design: every entity (flocks, purchases, consumption, sales, payments,
 * replenishment snapshots) is first built as a dated "event" in PHP, then all
 * events are sorted chronologically and replayed in that order while this
 * script tracks running state (bird counts, stock levels, AR/AP balances) in
 * memory — the same invariants the app enforces at runtime (mortality cannot
 * exceed live birds, stock cannot go negative, no payment before its invoice)
 * are therefore satisfied by construction, not by hope.
 *
 * Preserves: users, accounts (chart of accounts — only appends new Expense
 * accounts for categories the existing chart has no account for), pms_audit_log,
 * user_management_audit_log. Everything else operational is replaced.
 *
 * Run: php database/seed/two_year_realistic_demo_data.php
 * Re-run safe: deletes the same operational tables first, every time.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../../shared/includes/bootstrap.php';

$_SESSION['user'] = [
    'user_id' => 0,
    'username' => 'data_migration',
    'display_name' => 'Two-Year Dataset Generator',
    'role' => 'System',
    'system_access' => 'pms',
];

$pdo = database_connection();

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'sia2') {
    fwrite(STDERR, "Refusing to run: connected database is '$dbName', expected 'sia2'.\n");
    exit(1);
}

mt_srand(20261017);

const WINDOW_START = '2024-10-01';
const WINDOW_END = '2026-09-30';

function addDays(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function dayDiff(string $a, string $b): int
{
    $start = new DateTimeImmutable($a);
    $end = new DateTimeImmutable($b);
    return (int) $start->diff($end)->format('%r%a');
}

function monthKey(string $date): string
{
    return substr($date, 0, 7);
}

function r(float $min, float $max): float
{
    return $min + mt_rand(0, 1000000) / 1000000 * ($max - $min);
}

function ri(int $min, int $max): int
{
    return mt_rand($min, $max);
}

function pick(array $items): mixed
{
    return $items[array_rand($items)];
}

function money2(float $v): float
{
    return round($v, 2);
}

/** Season for a South Cotabato date: wet (roughly May-Nov) vs dry (Dec-Apr). */
function season(string $date): string
{
    $month = (int) substr($date, 5, 2);
    return ($month >= 5 && $month <= 11) ? 'wet' : 'dry';
}

echo "=== South Valley Poultry Farm — two-year dataset generator ===\n";
echo "Target database: $dbName\n";

// ---------------------------------------------------------------------------
// STATIC REFERENCE DATA
// ---------------------------------------------------------------------------

$customers = [
    ['name' => 'Koronadal Fresh Meat Traders', 'type' => 'meat retailer'],
    ['name' => 'Marbel Grocers Mart', 'type' => 'grocery store'],
    ['name' => 'Dona Rosario Carinderia Supply', 'type' => 'carinderia/restaurant'],
    ['name' => 'Surallah Poultry Dealers', 'type' => 'meat retailer'],
    ['name' => 'Tantangan Egg Resellers Co-op', 'type' => 'egg reseller'],
    ['name' => 'Banga Public Market Vendors Assoc.', 'type' => 'wet-market vendor'],
    ['name' => 'Lake Sebu Highland Resort Kitchen', 'type' => 'institutional buyer'],
    ['name' => 'Polomolok Grocery & Trading', 'type' => 'grocery store'],
];

$suppliers = [
    'feed' => 'Cotabato Grain & Feed Mills Inc.',
    'chick' => 'SouthStar Chick Hatchery',
    'pullet' => 'Highland Pullet Farms',
    'vet' => 'Allah Valley Veterinary Supply',
    'equipment' => 'GenSan Poultry Equipment Traders',
    'packaging' => 'Marbel Packaging & Egg Trays Co.',
    'fuel' => 'Koronadal Fuel & Transport Services',
    'utility' => 'South Cotabato Rural Electric Cooperative',
];

// Chart of accounts: extend (never modify/delete) with a few Expense accounts
// the existing chart has no equivalent for, per the task's own "use/extend"
// instruction. Codes continue the existing 5000-5500 numbering.
$newAccounts = [
    ['5600', 'Chick & Pullet Purchases Expense', 'Expense'],
    ['5700', 'Fuel & Transport Expense', 'Expense'],
    ['5800', 'Repairs & Maintenance Expense', 'Expense'],
    ['5900', 'Administrative Expense', 'Expense'],
    ['6000', 'Permits & Compliance Expense', 'Expense'],
];
$insertAccount = $pdo->prepare(
    'INSERT IGNORE INTO accounts (account_code, account_name, account_type, description, status)
     VALUES (?, ?, ?, ?, ?)'
);
foreach ($newAccounts as [$code, $name, $type]) {
    $insertAccount->execute([$code, $name, $type, 'Added for two-year realistic dataset cost coverage.', 'Active']);
}
echo "Chart of accounts: ensured " . count($newAccounts) . " supplementary Expense accounts exist.\n";

// Inventory catalog — fresh identity, but at the same style/convention as the
// old demo catalog (item_code prefix by category).
$inventoryCatalog = [
    ['code' => 'FEED-STARTER-001', 'name' => 'Broiler Starter Mash', 'category' => 'Feed', 'unit' => 'sack (50kg)', 'base_cost' => 1480, 'reorder' => 40, 'begin_qty' => 60],
    ['code' => 'FEED-GROWER-001', 'name' => 'Broiler Grower Feed', 'category' => 'Feed', 'unit' => 'sack (50kg)', 'base_cost' => 1420, 'reorder' => 50, 'begin_qty' => 70],
    ['code' => 'FEED-FINISHER-001', 'name' => 'Broiler Finisher Feed', 'category' => 'Feed', 'unit' => 'sack (50kg)', 'base_cost' => 1390, 'reorder' => 50, 'begin_qty' => 70],
    ['code' => 'FEED-LAYER-001', 'name' => 'Layer Mash', 'category' => 'Feed', 'unit' => 'sack (50kg)', 'base_cost' => 1350, 'reorder' => 40, 'begin_qty' => 50],
    ['code' => 'VAC-ND-001', 'name' => 'Newcastle Disease Vaccine', 'category' => 'Vaccine', 'unit' => 'vial (1000 doses)', 'base_cost' => 950, 'reorder' => 3, 'begin_qty' => 6],
    ['code' => 'VAC-IB-001', 'name' => 'Infectious Bronchitis Vaccine', 'category' => 'Vaccine', 'unit' => 'vial (1000 doses)', 'base_cost' => 1050, 'reorder' => 2, 'begin_qty' => 4],
    ['code' => 'VAC-FP-001', 'name' => 'Fowl Pox Vaccine', 'category' => 'Vaccine', 'unit' => 'vial (500 doses)', 'base_cost' => 780, 'reorder' => 2, 'begin_qty' => 4],
    ['code' => 'MED-ELECTRO-001', 'name' => 'Electrolyte & Vitamin Supplement', 'category' => 'Medicine', 'unit' => 'kg', 'base_cost' => 320, 'reorder' => 10, 'begin_qty' => 15],
    ['code' => 'MED-DEWORM-001', 'name' => 'Poultry Dewormer', 'category' => 'Medicine', 'unit' => 'liter', 'base_cost' => 480, 'reorder' => 5, 'begin_qty' => 8],
    ['code' => 'MED-ANTIB-001', 'name' => 'Broad-Spectrum Antibiotic', 'category' => 'Medicine', 'unit' => 'bottle', 'base_cost' => 560, 'reorder' => 6, 'begin_qty' => 10],
    ['code' => 'MED-COCCI-001', 'name' => 'Anticoccidial Treatment', 'category' => 'Medicine', 'unit' => 'bottle', 'base_cost' => 610, 'reorder' => 6, 'begin_qty' => 8],
    ['code' => 'SUP-BEDDING-001', 'name' => 'Rice Hull Bedding/Litter', 'category' => 'Farm Supply', 'unit' => 'sack', 'base_cost' => 85, 'reorder' => 60, 'begin_qty' => 90],
    ['code' => 'SUP-TRAY-001', 'name' => 'Egg Trays', 'category' => 'Farm Supply', 'unit' => 'piece (30-egg tray)', 'base_cost' => 18, 'reorder' => 100, 'begin_qty' => 150],
    ['code' => 'SUP-DISINF-001', 'name' => 'Disinfectant Solution', 'category' => 'Farm Supply', 'unit' => 'liter', 'base_cost' => 210, 'reorder' => 15, 'begin_qty' => 20],
    ['code' => 'SUP-CALCIUM-001', 'name' => 'Calcium / Shell Grit Supplement', 'category' => 'Farm Supply', 'unit' => 'kg', 'base_cost' => 55, 'reorder' => 30, 'begin_qty' => 40],
    ['code' => 'SUP-PPE-001', 'name' => 'PPE Gloves & Boots', 'category' => 'Farm Supply', 'unit' => 'pair', 'base_cost' => 150, 'reorder' => 10, 'begin_qty' => 12],
    ['code' => 'OTH-FUEL-001', 'name' => 'Diesel Fuel (Generator/Transport)', 'category' => 'Other', 'unit' => 'liter', 'base_cost' => 65, 'reorder' => 50, 'begin_qty' => 80],
];

$inventoryCatalogByCode = array_column($inventoryCatalog, null, 'code');

echo "Reference data ready: " . count($customers) . " customers, " . count($suppliers) . " suppliers, " . count($inventoryCatalog) . " inventory items.\n";

// ---------------------------------------------------------------------------
// WIPE OPERATIONAL TABLES (preserving users, accounts, and both audit logs)
// ---------------------------------------------------------------------------

$pdo->beginTransaction();

try {
    $tablesToClear = [
        'transaction_documents', 'ar_ap_payments', 'accounts_receivable', 'accounts_payable',
        'financial_transactions', 'inventory_adjustments', 'batch_costs', 'batch_profitability',
        'inventory_replenishment', 'scenarios', 'health_records', 'production_records',
        'farm_activities', 'poultry_inventory', 'flocks',
    ];
    foreach ($tablesToClear as $table) {
        $pdo->exec("DELETE FROM `$table`");
    }
    echo "Cleared " . count($tablesToClear) . " operational tables (users, accounts, audit logs preserved).\n";

    // -----------------------------------------------------------------------
    // INVENTORY CATALOG — insert with beginning stock
    // -----------------------------------------------------------------------
    $itemIds = []; // code => item_id
    $itemMeta = []; // code => ['reorder'=>,'cost'=>current unit cost running value]
    $insertItem = $pdo->prepare(
        'INSERT INTO poultry_inventory (item_code, item_name, category, quantity, unit, reorder_level, unit_cost, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($inventoryCatalog as $item) {
        $status = $item['begin_qty'] <= 0 ? 'Out of Stock' : ($item['begin_qty'] <= $item['reorder'] ? 'Low Stock' : 'Available');
        $insertItem->execute([
            $item['code'], $item['name'], $item['category'], $item['begin_qty'], $item['unit'],
            $item['reorder'], $item['base_cost'], $status,
        ]);
        $itemIds[$item['code']] = (int) $pdo->lastInsertId();
        $itemMeta[$item['code']] = ['reorder' => $item['reorder'], 'cost' => (float) $item['base_cost'], 'qty' => (float) $item['begin_qty']];
    }
    echo "Inventory catalog: inserted " . count($inventoryCatalog) . " items.\n";

    // -----------------------------------------------------------------------
    // FLOCK PLANNING — broiler batches every ~14 days, 3 independent layer cohorts
    // -----------------------------------------------------------------------
    $broilerBreeds = ['Cobb 500', 'Ross 308'];
    $layerBreeds = ['Hy-Line Brown', 'Lohmann Brown'];

    $broilerBatches = [];
    $cursor = WINDOW_START;
    $batchNum = 0;
    // First batch: already a few days old on window start, so it harvests early
    // enough to cover Oct 2024 (the window's first month) with a completed harvest.
    $firstBatch = true;
    while (dayDiff($cursor, WINDOW_END) > 30) {
        $batchNum++;
        $ageAtArrival = $firstBatch ? 5 : 0;
        $placementDate = $cursor;
        $growthDays = ri(35, 49);
        $isIncident = ($batchNum % 9 === 0 || $batchNum % 13 === 0); // ~20% of batches hit a real health incident
        $broilerBatches[] = [
            'batch_code' => 'SVPF-BR-' . str_replace('-', '', $placementDate) . '-' . str_pad((string) $batchNum, 2, '0', STR_PAD_LEFT),
            'breed' => $broilerBreeds[$batchNum % 2],
            'supplier' => $suppliers['chick'],
            'start_date' => $placementDate,
            'age_at_arrival_days' => $ageAtArrival,
            'initial_birds' => ri(400, 600),
            'growth_days' => $growthDays,
            'harvest_date' => addDays($placementDate, $growthDays - $ageAtArrival),
            'is_incident' => $isIncident,
        ];
        $firstBatch = false;
        $cursor = addDays($cursor, 14);
    }

    // Self-check: every one of the 24 calendar months must have >=1 harvest.
    // Patch any gap by inserting one extra short-cycle batch placed to land in it.
    $monthsNeeded = [];
    $cursorMonth = WINDOW_START;
    while ($cursorMonth <= WINDOW_END) {
        $monthsNeeded[monthKey($cursorMonth)] = false;
        $cursorMonth = addDays($cursorMonth, 28);
    }
    $monthsNeeded[monthKey(WINDOW_END)] = $monthsNeeded[monthKey(WINDOW_END)] ?? false;
    foreach ($broilerBatches as $b) {
        if ($b['harvest_date'] >= WINDOW_START && $b['harvest_date'] <= WINDOW_END) {
            $monthsNeeded[monthKey($b['harvest_date'])] = true;
        }
    }
    $patchNum = 1000;
    foreach ($monthsNeeded as $month => $covered) {
        if ($covered) {
            continue;
        }
        $targetHarvest = $month . '-20';
        $growthDays = ri(35, 42);
        $naivePlacement = addDays($targetHarvest, -$growthDays);
        // Never place a patch batch before the window starts — if the naive
        // placement would land earlier than that, clamp placement to WINDOW_START
        // and make up the difference with age_at_arrival_days instead (the batch
        // is modeled as already partly grown when farm history tracking began).
        $placementDate = $naivePlacement < WINDOW_START ? WINDOW_START : $naivePlacement;
        $ageAtArrival = $naivePlacement < WINDOW_START ? ($growthDays - dayDiff(WINDOW_START, $targetHarvest)) : 0;
        $patchNum++;
        $broilerBatches[] = [
            'batch_code' => 'SVPF-BR-' . str_replace('-', '', $placementDate) . '-P' . $patchNum,
            'breed' => $broilerBreeds[$patchNum % 2],
            'supplier' => $suppliers['chick'],
            'start_date' => $placementDate,
            'age_at_arrival_days' => max(0, $ageAtArrival),
            'initial_birds' => ri(400, 600),
            'growth_days' => $growthDays,
            'harvest_date' => $targetHarvest,
            'is_incident' => false,
        ];
        echo "  (coverage patch) added batch to guarantee a harvest in $month\n";
    }
    usort($broilerBatches, fn($a, $b) => strcmp($a['start_date'], $b['start_date']));

    $layerCohorts = [
        ['start' => '2024-10-01', 'end' => '2025-05-31'],
        ['start' => '2025-06-01', 'end' => '2026-01-31'],
        ['start' => '2026-02-01', 'end' => '2026-09-30'],
    ];
    foreach ($layerCohorts as $i => &$cohort) {
        $cohort['batch_code'] = 'SVPF-LY-' . str_replace('-', '', $cohort['start']) . '-' . ($i + 1);
        $cohort['breed'] = $layerBreeds[$i % 2];
        $cohort['initial_birds'] = ri(200, 300);
        $cohort['age_at_arrival_days'] = ri(126, 140); // 18-20 weeks
    }
    unset($cohort);

    echo "Planned " . count($broilerBatches) . " broiler batches and " . count($layerCohorts) . " layer cohorts.\n";

    // -----------------------------------------------------------------------
    // EVENT QUEUE — every event is ['date'=>Y-m-d,'seq'=>intra-day order,'fn'=>callable]
    // -----------------------------------------------------------------------
    $events = [];
    $pushEvent = function (string $date, int $seq, callable $fn) use (&$events): void {
        $events[] = ['date' => $date, 'seq' => $seq, 'fn' => $fn];
    };

    // Running state mirrors what the DB will hold, so every generator function
    // below can enforce the same invariants the app enforces at runtime.
    $flockState = []; // flock_id (assigned lazily) => ['current_birds'=>, 'initial_birds'=>, 'breed'=>, 'kind'=>'broiler'|'layer']
    $stock = $itemMeta; // code => ['qty'=>,'cost'=>,'reorder'=>]
    $arOpen = []; // ar_id => ['amount_due'=>,'amount_paid'=>,'due_date'=>,'flock_id'=>,'category'=>]
    $apOpen = []; // ap_id => same shape with supplier
    // Running total of feed actually needed (kg), accumulated as each batch/cohort's
    // feed events are planned below, so purchase quantities can be sized to real
    // consumption instead of an arbitrary flat amount (avoids a farm that buys far
    // more feed than any batch could ever eat).
    $feedConsumptionKg = ['FEED-STARTER-001' => 0.0, 'FEED-GROWER-001' => 0.0, 'FEED-FINISHER-001' => 0.0, 'FEED-LAYER-001' => 0.0];

    $feedPriceDrift = function (string $date, float $base): float {
        $monthsIn = max(0, intdiv(dayDiff(WINDOW_START, $date), 30));
        $drift = 1 + ($monthsIn / 24) * 0.12; // up to +12% over 2 years
        $noise = r(-0.04, 0.05);
        return money2($base * $drift * (1 + $noise));
    };

    $broilerPricePerKg = function (string $date): float {
        $monthsIn = max(0, intdiv(dayDiff(WINDOW_START, $date), 30));
        $base = 128 + ($monthsIn / 24) * 16; // 128 -> ~144 over 2 years
        $month = (int) substr($date, 5, 2);
        $seasonal = in_array($month, [11, 12, 1], true) ? r(4, 10) : r(-3, 4); // holiday demand bump
        return money2($base + $seasonal);
    };

    $eggPricePerPiece = function (string $date): float {
        $monthsIn = max(0, intdiv(dayDiff(WINDOW_START, $date), 30));
        $base = 7.0 + ($monthsIn / 24) * 1.6;
        return money2($base + r(-0.3, 0.4));
    };

    echo "Building event timeline...\n";

    // --- Broiler batch events -------------------------------------------------
    foreach ($broilerBatches as $batch) {
        $flockKey = $batch['batch_code'];
        $placementDate = $batch['start_date'];
        $harvestDate = $batch['harvest_date'];
        // All date filters below use $effectiveEnd, not $harvestDate directly, so that
        // a batch whose natural harvest would fall after the dataset's own window end
        // simply stays Active/in-progress as of the snapshot date (realistic — real
        // operations always have something mid-cycle) instead of leaking records past
        // WINDOW_END. The actual harvest/sale event only fires if it's within the window.
        $effectiveEnd = $harvestDate < WINDOW_END ? $harvestDate : WINDOW_END;
        $initialBirds = $batch['initial_birds'];

        // Cumulative mortality target for this batch.
        $mortalityPct = $batch['is_incident'] ? r(8, 15) : r(2, 5);
        $totalMortality = (int) round($initialBirds * $mortalityPct / 100);
        $totalMortality = min($totalMortality, $initialBirds - 10);

        $pushEvent($placementDate, 0, function () use ($pdo, &$flockState, $batch, $flockKey) {
            $stmt = $pdo->prepare(
                'INSERT INTO flocks (batch_code, breed, start_date, age_at_arrival_days, initial_birds, current_birds, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $notes = sprintf(
                'Day-old broiler chicks sourced from %s. Housed in Grow-out House %d, Pen %s.',
                $batch['supplier'], ri(1, 3), chr(ri(65, 68))
            );
            $stmt->execute([
                $batch['batch_code'], $batch['breed'], $batch['start_date'], $batch['age_at_arrival_days'],
                $batch['initial_birds'], $batch['initial_birds'], 'Active', $notes,
            ]);
            $flockId = (int) $pdo->lastInsertId();
            $flockState[$flockKey]['id'] = $flockId;
            $flockState[$flockKey]['current_birds'] = $batch['initial_birds'];
            $flockState[$flockKey]['kind'] = 'broiler';
        });

        // Chick purchase expense (flock-specific — chicks are bought for this exact batch).
        $chickUnitCost = r(48, 60);
        $pushEvent($placementDate, 1, function () use ($pdo, &$flockState, $batch, $flockKey, $chickUnitCost) {
            $flockId = $flockState[$flockKey]['id'];
            $amount = money2($batch['initial_birds'] * $chickUnitCost);
            $stmt = $pdo->prepare(
                "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                 VALUES (?, 'Expense', 'Chick & Pullet Purchases Expense', ?, ?, ?, ?, 'Recorded')"
            );
            $stmt->execute([
                $batch['start_date'],
                "Day-old chick purchase ({$batch['initial_birds']} birds) from {$batch['supplier']} for batch {$batch['batch_code']}.",
                $amount, $flockId, pick(['Cash', 'Bank Transfer']),
            ]);
        });

        // Vaccination schedule: day 1, day 7, day 14 (typical broiler program).
        foreach ([1, 7, 14] as $offset) {
            $vDate = addDays($placementDate, $offset);
            if ($vDate > $effectiveEnd) {
                continue;
            }
            $vaccine = $offset === 1 ? 'Newcastle Disease Vaccine' : ($offset === 7 ? 'Infectious Bronchitis Vaccine' : 'Fowl Pox Vaccine');
            $itemCode = $offset === 1 ? 'VAC-ND-001' : ($offset === 7 ? 'VAC-IB-001' : 'VAC-FP-001');
            $pushEvent($vDate, 2, function () use ($pdo, &$flockState, &$stock, $flockKey, $vaccine, $itemCode, $vDate, $batch) {
                $flockId = $flockState[$flockKey]['id'];
                $birds = $flockState[$flockKey]['current_birds'];
                $stmt = $pdo->prepare(
                    "INSERT INTO health_records (flock_id, record_date, record_type, description, affected_birds, medicine_or_vaccine, quantity_used, unit, action_taken, status, remarks)
                     VALUES (?, ?, 'Vaccination', ?, ?, ?, ?, 'dose', 'Routine vaccination administered to flock.', 'Resolved', 'Scheduled program vaccination.')"
                );
                $doses = $birds;
                $stmt->execute([
                    $flockId, $vDate, "Routine $vaccine administration for batch {$batch['batch_code']}.",
                    $birds, $vaccine, $doses,
                ]);
                $healthId = (int) $pdo->lastInsertId();
                consumeInventory($pdo, $stock, $itemCode, $doses / 1000, "Vaccination — health record #$healthId", 'Health Record', $healthId, 'Vaccination');
            });
        }

        // Possible disease incident mid-cycle for flagged batches.
        if ($batch['is_incident']) {
            $incidentDay = ri(10, max(11, $batch['growth_days'] - 10));
            $incidentDate = addDays($placementDate, $incidentDay);
            if ($incidentDate < $effectiveEnd) {
                $season = season($incidentDate);
                $disease = $season === 'wet'
                    ? pick(['Coccidiosis', 'Suspected Infectious Coryza', 'Wet Litter / Secondary Bacterial Infection'])
                    : pick(['Heat Stress', 'Suspected Newcastle Disease (clinical signs, unconfirmed)']);
                $pushEvent($incidentDate, 3, function () use ($pdo, &$flockState, &$stock, $flockKey, $disease, $incidentDate, $batch) {
                    $flockId = $flockState[$flockKey]['id'];
                    $birds = $flockState[$flockKey]['current_birds'];
                    $affected = (int) round($birds * r(0.08, 0.22));
                    $treatment = str_contains($disease, 'Heat') ? 'Electrolyte & Vitamin Supplement' : 'Anticoccidial Treatment';
                    $itemCode = str_contains($disease, 'Heat') ? 'MED-ELECTRO-001' : 'MED-COCCI-001';
                    $qty = r(1.5, 4);
                    // Resolution timing is determined now (not via a second deferred
                    // event — this is a one-pass historical insert, not a live
                    // simulation) so the stored status reflects whether recovery
                    // happened before the batch's own harvest date.
                    $resolveDate = addDays($incidentDate, ri(4, 8));
                    $status = $resolveDate <= $batch['harvest_date'] ? 'Resolved' : 'Monitoring';
                    $actionTaken = "Quarantine of affected pen; increased ventilation and biosecurity checks; $treatment administered.";
                    $remarks = 'Simulated disease event for dataset realism — not a real-farm diagnosis.'
                        . ($status === 'Resolved' ? " Flock recovered by {$resolveDate}." : ' Ongoing monitoring through harvest.');
                    $stmt = $pdo->prepare(
                        "INSERT INTO health_records (flock_id, record_date, record_type, description, affected_birds, medicine_or_vaccine, quantity_used, unit, action_taken, status, remarks)
                         VALUES (?, ?, 'Disease', ?, ?, ?, ?, 'bottle', ?, ?, ?)"
                    );
                    $stmt->execute([
                        $flockId, $incidentDate, "$disease observed in batch {$batch['batch_code']}. (Simulated event.)",
                        $affected, $treatment, $qty, $actionTaken, $status, $remarks,
                    ]);
                    $healthId = (int) $pdo->lastInsertId();
                    consumeInventory($pdo, $stock, $itemCode, $qty, "Disease treatment — health record #$healthId", 'Health Record', $healthId, str_contains($disease, 'Heat') ? 'Other' : 'Medicine');
                });
            }
        }

        // Production records every ~4 days: mortality trickled out across the cycle,
        // heavier during the incident window for incident batches.
        $recordDates = [];
        for ($d = 3; $d < $batch['growth_days']; $d += 4) {
            $recordDates[] = addDays($placementDate, $d);
        }
        $mortalityRemaining = $totalMortality;
        $incidentWindow = $batch['is_incident'] ? [$incidentDate ?? null, addDays($incidentDate ?? $placementDate, 10)] : null;
        foreach ($recordDates as $idx => $rDate) {
            if ($rDate > $effectiveEnd) {
                continue;
            }
            $isLast = $idx === count($recordDates) - 1;
            $inIncidentWindow = $incidentWindow !== null && $incidentWindow[0] !== null && $rDate >= $incidentWindow[0] && $rDate <= $incidentWindow[1];
            $share = $isLast ? $mortalityRemaining : (int) round($totalMortality * ($inIncidentWindow ? r(0.12, 0.22) : r(0.02, 0.06)));
            $share = max(0, min($share, $mortalityRemaining));
            $mortalityRemaining -= $share;
            $sampleWeight = 0.0;
            if ($idx % 2 === 1) {
                $ageFrac = min(1.0, dayDiff($placementDate, $rDate) / max(1, $batch['growth_days']));
                $sampleWeight = money2(0.15 + $ageFrac * r(1.7, 2.3));
            }
            $pushEvent($rDate, 4, function () use ($pdo, &$flockState, $flockKey, $rDate, $share, $sampleWeight) {
                $flockId = $flockState[$flockKey]['id'];
                $stmt = $pdo->prepare(
                    'INSERT INTO production_records (flock_id, record_date, eggs_produced, good_eggs, damaged_eggs, sample_size, average_weight, mortality_count, remarks)
                     VALUES (?, ?, 0, 0, 0, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $flockId, $rDate, $sampleWeight > 0 ? 25 : 0, $sampleWeight, $share,
                    $sampleWeight > 0 ? 'Routine weight sampling.' : null,
                ]);
                if ($share > 0) {
                    pms_adjust_flock_birds($pdo, $flockId, -$share);
                    $flockState[$flockKey]['current_birds'] -= $share;
                }
            });
        }

        // Feed consumption + feeding activity, every ~5 days, stage-appropriate feed
        // (starter weeks 1-2, grower weeks 3-4, finisher after that). Total feed for
        // the batch is derived from an FCR (feed conversion ratio) of 1.7-2.0 against
        // the batch's own expected harvest weight, then split evenly across events —
        // this is what lets batch_costs show a real, varying Feed cost per batch.
        $expectedAvgWeight = 1.6 + max(0, $batch['growth_days'] - 35) / 14 * 0.5;
        $expectedTotalFeedKg = $batch['initial_birds'] * $expectedAvgWeight * r(1.7, 2.0);
        $feedDays = [];
        for ($d = 2; $d < $batch['growth_days']; $d += 5) {
            $feedDays[] = $d;
        }
        $feedPerEventKg = $feedDays !== [] ? $expectedTotalFeedKg / count($feedDays) : 0.0;
        foreach ($feedDays as $d) {
            $aDate = addDays($placementDate, $d);
            if ($aDate > $effectiveEnd) {
                continue;
            }
            $stage = $d <= 14 ? 'FEED-STARTER-001' : ($d <= 28 ? 'FEED-GROWER-001' : 'FEED-FINISHER-001');
            $feedConsumptionKg[$stage] += $feedPerEventKg;
            $pushEvent($aDate, 5, function () use ($pdo, &$flockState, &$stock, $flockKey, $aDate, $batch, $stage, $feedPerEventKg, $inventoryCatalogByCode) {
                $flockId = $flockState[$flockKey]['id'];
                $itemName = $inventoryCatalogByCode[$stage]['name'];
                $sacks = money2($feedPerEventKg / 50);
                $stmt = $pdo->prepare(
                    "INSERT INTO farm_activities (flock_id, activity_type, description, scheduled_date, completed_date, assigned_to, inventory_item_name, inventory_quantity_used, status, remarks)
                     VALUES (?, 'Feeding', ?, ?, ?, ?, ?, ?, 'Completed', 'Completed as scheduled.')"
                );
                $stmt->execute([
                    $flockId, "Feeding routine ($itemName) for batch {$batch['batch_code']}.", $aDate, $aDate,
                    pick(['Farm Supervisor', 'Poultry Worker A', 'Poultry Worker B']), $itemName, $sacks,
                ]);
                $activityId = (int) $pdo->lastInsertId();
                consumeInventory($pdo, $stock, $stage, $sacks, "Feed consumption — farm activity #$activityId", 'Farm Activity', $activityId, 'Feed');
            });
        }

        // Cleaning and one weighing check, sparser than feeding.
        foreach ([6, 20, 32] as $offset) {
            $aDate = addDays($placementDate, $offset);
            if ($aDate > $effectiveEnd) {
                continue;
            }
            $type = $offset === 20 ? 'Weighing' : 'Cleaning';
            $pushEvent($aDate, 5, function () use ($pdo, &$flockState, $flockKey, $aDate, $type, $batch) {
                $flockId = $flockState[$flockKey]['id'];
                $stmt = $pdo->prepare(
                    "INSERT INTO farm_activities (flock_id, activity_type, description, scheduled_date, completed_date, assigned_to, status, remarks)
                     VALUES (?, ?, ?, ?, ?, ?, 'Completed', ?)"
                );
                $stmt->execute([
                    $flockId, $type, "$type routine for batch {$batch['batch_code']}.", $aDate, $aDate,
                    pick(['Farm Supervisor', 'Poultry Worker A', 'Poultry Worker B']),
                    $type === 'Cleaning' ? 'Litter refreshed; pen disinfected.' : 'Completed as scheduled.',
                ]);
            });
        }

        // Harvest: either a single full harvest, or (for ~1 in 10 batches) a
        // two-stage partial harvest a few days apart for variety. Skipped entirely
        // (flock stays Active, still mid-cycle) if the natural harvest date would
        // fall after the dataset's window end.
        if ($harvestDate > WINDOW_END) {
            continue;
        }
        $twoStage = ($batchNumHash = crc32($flockKey)) % 10 === 0;
        $pushEvent($harvestDate, 6, function () use ($pdo, &$flockState, &$arOpen, $flockKey, $harvestDate, $batch, $broilerPricePerKg, $customers, $twoStage) {
            $flockId = $flockState[$flockKey]['id'];
            $liveBirds = $flockState[$flockKey]['current_birds'];
            if ($liveBirds <= 0) {
                return;
            }
            $stages = $twoStage ? [0.6, 0.4] : [1.0];
            $remainingBirds = $liveBirds;
            foreach ($stages as $i => $fraction) {
                $stageDate = $i === 0 ? $harvestDate : addDays($harvestDate, ri(3, 6));
                $harvestedQty = $i === count($stages) - 1 ? $remainingBirds : (int) round($liveBirds * $fraction);
                $harvestedQty = min($harvestedQty, $remainingBirds);
                if ($harvestedQty <= 0) {
                    continue;
                }
                $remainingBirds -= $harvestedQty;
                $avgWeight = money2(r(1.6, 2.3));
                $pricePerKg = $broilerPricePerKg($stageDate);
                $revenue = money2($harvestedQty * $avgWeight * $pricePerKg);
                $customer = pick($customers);
                $isCredit = r(0, 1) < 0.5;

                if (!$isCredit) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                         VALUES (?, 'Income', 'Poultry Sales', ?, ?, ?, ?, 'Recorded')"
                    );
                    $stmt->execute([
                        $stageDate,
                        sprintf('Cash sale of %d broilers (avg %.2fkg) from batch %s to %s.', $harvestedQty, $avgWeight, $batch['batch_code'], $customer['name']),
                        $revenue, $flockId, pick(['Cash', 'Bank Transfer']),
                    ]);
                } else {
                    $terms = pick([15, 20, 30]);
                    $dueDate = addDays($stageDate, $terms);
                    $amountPaid = 0.0;
                    $status = ar_ap_effective_status($revenue, $amountPaid, $dueDate);
                    $stmt = $pdo->prepare(
                        'INSERT INTO accounts_receivable (customer_name, invoice_number, transaction_id, flock_id, invoice_date, due_date, amount_due, amount_paid, status, remarks)
                         VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $invoiceNumber = 'SVPF-INV-' . str_replace('-', '', $stageDate) . '-' . ri(100, 999);
                    $stmt->execute([
                        $customer['name'], $invoiceNumber, $flockId, $stageDate, $dueDate,
                        $revenue, $amountPaid, $status, "Credit sale, {$terms}-day terms — batch {$batch['batch_code']}.",
                    ]);
                    $arId = (int) $pdo->lastInsertId();
                    $arOpen[$arId] = [
                        'amount_due' => $revenue, 'amount_paid' => $amountPaid, 'due_date' => $dueDate,
                        'flock_id' => $flockId, 'category' => 'Poultry Sales', 'invoice_date' => $stageDate,
                    ];
                }
            }
            $flockState[$flockKey]['current_birds'] = 0;
            $pdo->prepare("UPDATE flocks SET current_birds = 0, status = 'Sold' WHERE flock_id = ?")->execute([$flockId]);

            $completeStmt = $pdo->prepare(
                "INSERT INTO farm_activities (flock_id, activity_type, description, scheduled_date, completed_date, assigned_to, status, remarks)
                 VALUES (?, 'Other', ?, ?, ?, 'Farm Supervisor', 'Completed', 'Batch cycle closed out.')"
            );
            $completeStmt->execute([$flockId, "Harvest completed for batch {$batch['batch_code']}.", $harvestDate, $harvestDate]);
        });
    }

    // --- Layer cohort events ---------------------------------------------------
    foreach ($layerCohorts as $cohort) {
        $flockKey = $cohort['batch_code'];
        $start = $cohort['start'];
        $end = $cohort['end'];
        $initialBirds = $cohort['initial_birds'];

        $pushEvent($start, 0, function () use ($pdo, &$flockState, $cohort, $flockKey) {
            $stmt = $pdo->prepare(
                'INSERT INTO flocks (batch_code, breed, start_date, age_at_arrival_days, initial_birds, current_birds, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $notes = sprintf('Point-of-lay pullets (%d weeks) sourced from %s. Independent layer flock, housed separately from broilers.', (int) round($cohort['age_at_arrival_days'] / 7), $GLOBALS['suppliers']['pullet']);
            $stmt->execute([
                $cohort['batch_code'], $cohort['breed'], $cohort['start'], $cohort['age_at_arrival_days'],
                $cohort['initial_birds'], $cohort['initial_birds'], 'Active', $notes,
            ]);
            $flockId = (int) $pdo->lastInsertId();
            $flockState[$flockKey]['id'] = $flockId;
            $flockState[$flockKey]['current_birds'] = $cohort['initial_birds'];
            $flockState[$flockKey]['kind'] = 'layer';
        });

        $pulletUnitCost = r(190, 240);
        $pushEvent($start, 1, function () use ($pdo, &$flockState, $cohort, $flockKey, $pulletUnitCost) {
            $flockId = $flockState[$flockKey]['id'];
            $amount = money2($cohort['initial_birds'] * $pulletUnitCost);
            $stmt = $pdo->prepare(
                "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                 VALUES (?, 'Expense', 'Chick & Pullet Purchases Expense', ?, ?, ?, 'Bank Transfer', 'Recorded')"
            );
            $stmt->execute([
                $cohort['start'],
                "Point-of-lay pullet purchase ({$cohort['initial_birds']} birds) from {$GLOBALS['suppliers']['pullet']} for {$cohort['batch_code']}.",
                $amount, $flockId,
            ]);
        });

        // Initial vaccination.
        $pushEvent(addDays($start, 3), 2, function () use ($pdo, &$flockState, &$stock, $flockKey, $cohort) {
            $flockId = $flockState[$flockKey]['id'];
            $birds = $flockState[$flockKey]['current_birds'];
            $stmt = $pdo->prepare(
                "INSERT INTO health_records (flock_id, record_date, record_type, description, affected_birds, medicine_or_vaccine, quantity_used, unit, action_taken, status, remarks)
                 VALUES (?, ?, 'Vaccination', ?, ?, 'Newcastle Disease Vaccine', ?, 'dose', 'Routine layer vaccination program.', 'Resolved', NULL)"
            );
            $stmt->execute([$flockId, addDays($cohort['start'], 3), "Entry vaccination for layer flock {$cohort['batch_code']}.", $birds, $birds]);
            $healthId = (int) $pdo->lastInsertId();
            consumeInventory($pdo, $stock, 'VAC-ND-001', $birds / 1000, "Layer entry vaccination — health record #$healthId", 'Health Record', $healthId, 'Vaccination');
        });

        // Weekly egg production across the whole window, modeling a lay curve.
        $totalWeeks = intdiv(dayDiff($start, $end), 7);
        $hensAlive = $initialBirds;
        $cumulativeMortalityBudget = (int) round($initialBirds * r(4, 9) / 100);
        for ($week = 1; $week <= $totalWeeks; $week++) {
            $weekDate = addDays($start, $week * 7);
            if ($weekDate > $end) {
                break;
            }
            // Hen-day % curve: rising weeks 1-6, peak 7-40ish, gentle decline after.
            if ($week <= 6) {
                $henDayPct = 5 + ($week / 6) * 78;
            } elseif ($week <= min(40, $totalWeeks - 4)) {
                $henDayPct = r(85, 95);
            } else {
                $declineProgress = ($week - min(40, $totalWeeks - 4)) / max(1, $totalWeeks - min(40, $totalWeeks - 4));
                $henDayPct = 88 - $declineProgress * 25;
            }
            $seasonLabel = season($weekDate);
            if ($seasonLabel === 'dry' && (int) substr($weekDate, 5, 2) >= 3 && (int) substr($weekDate, 5, 2) <= 5) {
                $henDayPct -= r(2, 6); // hot-season dip
            }
            $henDayPct = max(3, min(96, $henDayPct));

            $weeklyMortality = $week < $totalWeeks - 2 ? min((int) round($cumulativeMortalityBudget / $totalWeeks * r(0.4, 1.6)), $hensAlive - 5) : 0;
            $weeklyMortality = max(0, $weeklyMortality);

            $eggs = (int) round($hensAlive * $henDayPct / 100 * 7);
            $damagedPct = r(3, 6);
            $damaged = (int) round($eggs * $damagedPct / 100);
            $good = $eggs - $damaged;
            $avgEggWeight = money2(52 + min(1.0, $week / 10) * r(6, 9));

            $pushEvent($weekDate, 4, function () use ($pdo, &$flockState, $flockKey, $weekDate, $eggs, $good, $damaged, $avgEggWeight, $weeklyMortality) {
                $flockId = $flockState[$flockKey]['id'];
                $stmt = $pdo->prepare(
                    'INSERT INTO production_records (flock_id, record_date, eggs_produced, good_eggs, damaged_eggs, sample_size, average_weight, mortality_count, remarks)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$flockId, $weekDate, $eggs, $good, $damaged, 30, $avgEggWeight, $weeklyMortality, 'Weekly egg collection summary.']);
                if ($weeklyMortality > 0) {
                    pms_adjust_flock_birds($pdo, $flockId, -$weeklyMortality);
                    $flockState[$flockKey]['current_birds'] -= $weeklyMortality;
                }
            });
            $hensAlive = max(0, $hensAlive - $weeklyMortality);

            // Egg sale for this week's good eggs.
            if ($good > 0) {
                $pushEvent($weekDate, 5, function () use ($pdo, &$flockState, &$arOpen, $flockKey, $weekDate, $good, $eggPricePerPiece, $customers, $cohort) {
                    $flockId = $flockState[$flockKey]['id'];
                    $price = $eggPricePerPiece($weekDate);
                    $revenue = money2($good * $price);
                    $customer = pick($customers);
                    $isCredit = r(0, 1) < 0.35;
                    if (!$isCredit) {
                        $pdo->prepare(
                            "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                             VALUES (?, 'Income', 'Egg Sales', ?, ?, ?, ?, 'Recorded')"
                        )->execute([
                            $weekDate, sprintf('Cash sale of %d eggs from %s to %s.', $good, $cohort['batch_code'], $customer['name']),
                            $revenue, $flockId, pick(['Cash', 'E-Wallet']),
                        ]);
                    } else {
                        $terms = pick([7, 15]);
                        $dueDate = addDays($weekDate, $terms);
                        $status = ar_ap_effective_status($revenue, 0.0, $dueDate);
                        $invoiceNumber = 'SVPF-EGGINV-' . str_replace('-', '', $weekDate) . '-' . ri(100, 999);
                        $pdo->prepare(
                            'INSERT INTO accounts_receivable (customer_name, invoice_number, transaction_id, flock_id, invoice_date, due_date, amount_due, amount_paid, status, remarks)
                             VALUES (?, ?, NULL, ?, ?, ?, ?, 0, ?, ?)'
                        )->execute([
                            $customer['name'], $invoiceNumber, $flockId, $weekDate, $dueDate, $revenue, $status,
                            "Credit egg sale, {$terms}-day terms — {$cohort['batch_code']}.",
                        ]);
                        $arId = (int) $pdo->lastInsertId();
                        $arOpen[$arId] = ['amount_due' => $revenue, 'amount_paid' => 0.0, 'due_date' => $dueDate, 'flock_id' => $flockId, 'category' => 'Egg Sales', 'invoice_date' => $weekDate];
                    }
                });
            }

            // Weekly layer feed consumption, scaled to the current live hen count.
            $weeklyFeedSacks = money2(($hensAlive * r(0.75, 0.85)) / 50);
            $feedConsumptionKg['FEED-LAYER-001'] += $weeklyFeedSacks * 50;
            $pushEvent($weekDate, 4, function () use ($pdo, &$flockState, &$stock, $flockKey, $weekDate, $weeklyFeedSacks, $cohort) {
                $flockId = $flockState[$flockKey]['id'];
                $stmt = $pdo->prepare(
                    "INSERT INTO farm_activities (flock_id, activity_type, description, scheduled_date, completed_date, assigned_to, inventory_item_name, inventory_quantity_used, status, remarks)
                     VALUES (?, 'Feeding', ?, ?, ?, ?, 'Layer Mash', ?, 'Completed', 'Completed as scheduled.')"
                );
                $stmt->execute([
                    $flockId, "Weekly layer mash feeding — {$cohort['batch_code']}.", $weekDate, $weekDate,
                    pick(['Farm Supervisor', 'Poultry Worker A', 'Poultry Worker B']), $weeklyFeedSacks,
                ]);
                $activityId = (int) $pdo->lastInsertId();
                consumeInventory($pdo, $stock, 'FEED-LAYER-001', $weeklyFeedSacks, "Layer feed consumption — farm activity #$activityId", 'Farm Activity', $activityId, 'Feed');
            });

            // Egg collection / cleaning activity every ~3 weeks to keep volume sane.
            if ($week % 3 === 0) {
                $pushEvent($weekDate, 6, function () use ($pdo, &$flockState, $flockKey, $weekDate, $cohort) {
                    $flockId = $flockState[$flockKey]['id'];
                    $pdo->prepare(
                        "INSERT INTO farm_activities (flock_id, activity_type, description, scheduled_date, completed_date, assigned_to, status, remarks)
                         VALUES (?, ?, ?, ?, ?, ?, 'Completed', NULL)"
                    )->execute([
                        $flockId, pick(['Egg Collection', 'Cleaning']), "Routine task for {$cohort['batch_code']}.",
                        $weekDate, $weekDate, pick(['Farm Supervisor', 'Poultry Worker A', 'Poultry Worker B']),
                    ]);
                });
            }

            // Occasional seasonal health check / heat stress note.
            if ($week % 10 === 0) {
                $isHeat = season($weekDate) === 'dry';
                $pushEvent($weekDate, 3, function () use ($pdo, &$flockState, &$stock, $flockKey, $weekDate, $isHeat, $cohort) {
                    $flockId = $flockState[$flockKey]['id'];
                    $birds = $flockState[$flockKey]['current_birds'];
                    $affected = (int) round($birds * r(0.03, 0.08));
                    $desc = $isHeat
                        ? "Heat stress observed during dry-season peak temperatures — {$cohort['batch_code']}. (Simulated event.)"
                        : "Routine wet-season biosecurity and parasite health check — {$cohort['batch_code']}. (Simulated event.)";
                    $pdo->prepare(
                        "INSERT INTO health_records (flock_id, record_date, record_type, description, affected_birds, medicine_or_vaccine, quantity_used, unit, action_taken, status, remarks)
                         VALUES (?, ?, 'Health Check', ?, ?, ?, ?, 'kg', 'Increased ventilation / electrolyte supplementation.', 'Resolved', NULL)"
                    )->execute([
                        $flockId, $weekDate, $desc, $affected, 'Electrolyte & Vitamin Supplement', r(1, 3),
                    ]);
                    $healthId = (int) $pdo->lastInsertId();
                    consumeInventory($pdo, $stock, 'MED-ELECTRO-001', r(1, 3), "Layer health check — health record #$healthId", 'Health Record', $healthId, 'Other');
                });
            }
        }

        // End-of-cycle: spent hens sold off.
        $pushEvent($end, 7, function () use ($pdo, &$flockState, $flockKey, $cohort) {
            $flockId = $flockState[$flockKey]['id'];
            $remaining = $flockState[$flockKey]['current_birds'];
            if ($remaining > 0) {
                $price = r(90, 130); // spent hen sale price, lower than dressed broiler
                $revenue = money2($remaining * $price);
                $pdo->prepare(
                    "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                     VALUES (?, 'Income', 'Poultry Sales', ?, ?, ?, 'Cash', 'Recorded')"
                )->execute([
                    $cohort['end'], "Sale of {$remaining} spent layer hens at end of lay cycle — {$cohort['batch_code']}.",
                    $revenue, $flockId,
                ]);
            }
            $pdo->prepare("UPDATE flocks SET current_birds = 0, status = 'Sold' WHERE flock_id = ?")->execute([$flockId]);
            $flockState[$flockKey]['current_birds'] = 0;
        });
    }

    echo "Flock events built: " . count($events) . " so far (before overhead/purchasing/replenishment).\n";

    // --- Farm-wide monthly overhead (Labor, Utilities, Fuel, Admin, Maintenance, Permits) ---
    $cursorMonth = WINDOW_START;
    while ($cursorMonth <= WINDOW_END) {
        $mDate = substr($cursorMonth, 0, 8) . '05'; // post around the 5th of the month
        $monthsIn = max(0, intdiv(dayDiff(WINDOW_START, $mDate), 30));
        $pushEvent($mDate, 2, function () use ($pdo, $mDate, $monthsIn) {
            $labor = money2(r(18000, 24000) * (1 + $monthsIn * 0.01));
            $pdo->prepare(
                "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                 VALUES (?, 'Expense', 'Labor Expense', 'Monthly farm labor wages.', ?, NULL, 'Cash', 'Recorded')"
            )->execute([$mDate, $labor]);

            $utilities = money2(r(4500, 7500) * (1 + $monthsIn * 0.015));
            $pdo->prepare(
                "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                 VALUES (?, 'Expense', 'Utilities Expense', 'Electricity and water for the farm.', ?, NULL, 'Bank Transfer', 'Recorded')"
            )->execute([$mDate, $utilities]);

            $fuel = money2(r(2000, 3800));
            $pdo->prepare(
                "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                 VALUES (?, 'Expense', 'Fuel & Transport Expense', ?, ?, NULL, 'Cash', 'Recorded')"
            )->execute([$mDate, 'Fuel for generator and delivery transport — ' . $GLOBALS['suppliers']['fuel'] . '.', $fuel]);

            if (mt_rand(1, 100) <= 40) {
                $maint = money2(r(1500, 9000));
                $pdo->prepare(
                    "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                     VALUES (?, 'Expense', 'Repairs & Maintenance Expense', ?, ?, NULL, 'Cash', 'Recorded')"
                )->execute([$mDate, 'Equipment/housing repair and maintenance — ' . $GLOBALS['suppliers']['equipment'] . '.', $maint]);
            }

            $admin = money2(r(800, 2200));
            $pdo->prepare(
                "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                 VALUES (?, 'Expense', 'Administrative Expense', 'Office supplies, communication, and recordkeeping costs.', ?, NULL, 'Cash', 'Recorded')"
            )->execute([$mDate, $admin]);

            if ($monthsIn % 12 === 0) {
                $permit = money2(r(3000, 8000));
                $pdo->prepare(
                    "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                     VALUES (?, 'Expense', 'Permits & Compliance Expense', 'Annual business/sanitary/environmental permit renewal.', ?, NULL, 'Bank Transfer', 'Recorded')"
                )->execute([$mDate, $permit]);
            }
        });
        $cursorMonth = addDays($cursorMonth, 32);
        $cursorMonth = substr($cursorMonth, 0, 8) . '01';
    }

    // --- Periodic farm-wide inventory purchases (Feed/Vaccine/Medicine/Farm Supply) ---
    foreach ($inventoryCatalog as $item) {
        $code = $item['code'];
        $intervalDays = $item['category'] === 'Feed' ? 12 : ($item['category'] === 'Farm Supply' ? 21 : 35);
        $startOffset = (int) (crc32($code) % 10);
        $pCursor = addDays(WINDOW_START, $startOffset);
        // For feed, size each purchase to the actual computed consumption need
        // (plus a 15% safety buffer) spread across however many purchase events
        // will fire for this item, instead of an arbitrary flat quantity — a
        // farm buys roughly what its flocks will eat, not a random amount.
        $feedQtyPerEvent = 0.0;
        if ($item['category'] === 'Feed') {
            $numPurchaseEvents = max(1, intdiv(dayDiff($pCursor, WINDOW_END), $intervalDays) + 1);
            $feedQtyPerEvent = ($feedConsumptionKg[$code] * 1.15 / 50) / $numPurchaseEvents;
        }
        while ($pCursor <= WINDOW_END) {
            $qty = match ($item['category']) {
                'Feed' => max(1.0, money2($feedQtyPerEvent * r(0.8, 1.2))),
                'Vaccine' => ri(2, 4),
                'Medicine' => ri(3, 8),
                'Farm Supply' => ri(20, 60),
                default => ri(20, 50),
            };
            $pushEvent($pCursor, 1, function () use ($pdo, &$stock, $code, $qty, $pCursor, $item, $feedPriceDrift, $suppliers, &$apOpen) {
                $unitCost = $feedPriceDrift($pCursor, $item['base_cost']);
                $stock[$code]['cost'] = $unitCost;
                $total = money2($qty * $unitCost);
                $supplierName = $item['category'] === 'Feed' ? $suppliers['feed'] : ($item['category'] === 'Farm Supply' ? $suppliers['packaging'] : $suppliers['vet']);
                $categoryAccount = match ($item['category']) {
                    'Feed' => 'Feed Expense',
                    'Vaccine' => 'Vaccination Expense',
                    'Medicine' => 'Medicine Expense',
                    'Farm Supply' => 'Farm Supplies Expense',
                    default => 'Fuel & Transport Expense',
                };
                $itemId = $GLOBALS['itemIds'][$code];
                $isCredit = r(0, 1) < 0.4;
                if (!$isCredit) {
                    $pdo->prepare(
                        "INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
                         VALUES (?, 'Expense', ?, ?, ?, NULL, ?, 'Recorded')"
                    )->execute([
                        $pCursor, $categoryAccount, "Purchase of {$qty} {$item['unit']} of {$item['name']} from {$supplierName}.",
                        $total, pick(['Cash', 'Bank Transfer']),
                    ]);
                } else {
                    $terms = pick([15, 30, 45]);
                    $dueDate = addDays($pCursor, $terms);
                    $status = ar_ap_effective_status($total, 0.0, $dueDate);
                    $invoiceNumber = 'SUP-INV-' . str_replace('-', '', $pCursor) . '-' . ri(100, 999);
                    $pdo->prepare(
                        'INSERT INTO accounts_payable (supplier_name, invoice_number, transaction_id, flock_id, invoice_date, due_date, amount_due, amount_paid, status, remarks)
                         VALUES (?, ?, NULL, NULL, ?, ?, ?, 0, ?, ?)'
                    )->execute([
                        $supplierName, $invoiceNumber, $pCursor, $dueDate, $total, $status,
                        "Credit purchase, {$terms}-day terms — {$item['name']}.",
                    ]);
                    $apId = (int) $pdo->lastInsertId();
                    $apOpen[$apId] = ['amount_due' => $total, 'amount_paid' => 0.0, 'due_date' => $dueDate, 'flock_id' => null, 'category' => $categoryAccount, 'invoice_date' => $pCursor];
                }
                // Stock received regardless of cash/credit — goods arrive on delivery.
                $stmt = $pdo->prepare(
                    "INSERT INTO inventory_adjustments (item_id, direction, quantity, quantity_before, quantity_after, reason, source, source_id, adjusted_by)
                     VALUES (?, 'Add', ?, ?, ?, ?, 'Manual', NULL, 'data_migration')"
                );
                $before = $stock[$code]['qty'];
                $after = $before + $qty;
                $stmt->execute([$itemId, $qty, $before, $after, "Delivery received from {$supplierName}."]);
                $stock[$code]['qty'] = $after;
                $newStatus = $after <= 0 ? 'Out of Stock' : ($after <= $stock[$code]['reorder'] ? 'Low Stock' : 'Available');
                $pdo->prepare('UPDATE poultry_inventory SET quantity = ?, unit_cost = ?, status = ? WHERE item_id = ?')
                    ->execute([$after, $unitCost, $newStatus, $itemId]);
            });
            $pCursor = addDays($pCursor, $intervalDays);
        }
    }

    // --- AR/AP payment events: settle a healthy majority, leave some overdue/partial ---
    // These are scheduled relative to invoice/due dates, so we add them as events too,
    // but they reference $arOpen/$apOpen which are only populated once the invoice
    // events above have actually executed — handled in the chronological replay below
    // by re-deriving payments directly from the live DB after the main pass.

    echo "Total events queued: " . count($events) . "\n";

    // -----------------------------------------------------------------------
    // REPLAY — sort chronologically and execute
    // -----------------------------------------------------------------------
    usort($events, function ($a, $b) {
        $cmp = strcmp($a['date'], $b['date']);
        return $cmp !== 0 ? $cmp : $a['seq'] <=> $b['seq'];
    });

    /** Shared inventory-consumption helper used by health/activity event closures above. */
    function consumeInventory(PDO $pdo, array &$stock, string $itemCode, float $qty, string $reason, string $source, int $sourceId, string $costCategory): void
    {
        global $itemIds;
        if ($qty <= 0 || !isset($stock[$itemCode])) {
            return;
        }
        $itemId = $itemIds[$itemCode];
        $before = $stock[$itemCode]['qty'];
        $qty = min($qty, $before); // never let stock go negative
        if ($qty <= 0) {
            return;
        }
        $after = money2($before - $qty);
        $pdo->prepare(
            "INSERT INTO inventory_adjustments (item_id, direction, quantity, quantity_before, quantity_after, reason, source, source_id, adjusted_by)
             VALUES (?, 'Reduce', ?, ?, ?, ?, ?, ?, 'data_migration')"
        )->execute([$itemId, money2($qty), $before, $after, $reason, $source, $sourceId]);
        $stock[$itemCode]['qty'] = $after;
        $newStatus = $after <= 0 ? 'Out of Stock' : ($after <= $stock[$itemCode]['reorder'] ? 'Low Stock' : 'Available');
        $pdo->prepare('UPDATE poultry_inventory SET quantity = ?, status = ? WHERE item_id = ?')->execute([$after, $newStatus, $itemId]);
    }

    $executed = 0;
    foreach ($events as $event) {
        ($event['fn'])();
        $executed++;
    }
    echo "Replayed $executed events.\n";

    // -----------------------------------------------------------------------
    // batch_costs: allocate farm-wide Labor/Utilities/Farm Supplies overhead
    // across flocks that were active that month, proportional to bird-days.
    // -----------------------------------------------------------------------
    echo "Allocating overhead to batch_costs...\n";
    $allFlocks = $pdo->query('SELECT flock_id, batch_code, start_date, current_birds, initial_birds FROM flocks ORDER BY flock_id')->fetchAll();
    $flockActivePeriods = [];
    foreach ($allFlocks as $f) {
        // Approximate the flock's active window from its own production_records span.
        $span = $pdo->prepare('SELECT MIN(record_date) AS first_d, MAX(record_date) AS last_d FROM production_records WHERE flock_id = ?');
        $span->execute([$f['flock_id']]);
        $row = $span->fetch();
        if ($row && $row['first_d'] !== null) {
            $flockActivePeriods[(int) $f['flock_id']] = ['start' => $row['first_d'], 'end' => $row['last_d'], 'birds' => max(1, (int) $f['initial_birds'])];
        }
    }

    $overheadStatement = $pdo->prepare(
        "SELECT transaction_id, transaction_date, category, amount FROM financial_transactions
         WHERE flock_id IS NULL AND category IN ('Labor Expense', 'Utilities Expense', 'Farm Supplies Expense') AND deleted_at IS NULL"
    );
    $overheadRows = $overheadStatement->execute() ? $overheadStatement->fetchAll() : [];
    $insertBatchCost = $pdo->prepare(
        'INSERT INTO batch_costs (flock_id, cost_date, cost_category, description, amount, source_reference)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $categoryMap = ['Labor Expense' => 'Labor', 'Utilities Expense' => 'Utilities', 'Farm Supplies Expense' => 'Farm Supplies'];
    foreach ($overheadRows as $row) {
        $month = monthKey($row['transaction_date']);
        $activeFlockIds = [];
        $totalBirdDays = 0;
        foreach ($flockActivePeriods as $flockId => $period) {
            if (monthKey($period['start']) <= $month && monthKey($period['end']) >= $month) {
                $activeFlockIds[] = $flockId;
                $totalBirdDays += $period['birds'];
            }
        }
        if ($activeFlockIds === [] || $totalBirdDays <= 0) {
            continue;
        }
        $costCategory = $categoryMap[$row['category']];
        foreach ($activeFlockIds as $flockId) {
            $share = money2((float) $row['amount'] * ($flockActivePeriods[$flockId]['birds'] / $totalBirdDays));
            if ($share <= 0) {
                continue;
            }
            $insertBatchCost->execute([
                $flockId, $row['transaction_date'], $costCategory,
                "Allocated share of farm-wide {$costCategory} expense (txn #{$row['transaction_id']}).",
                $share, 'overhead_allocation:' . $row['transaction_id'],
            ]);
        }
    }
    echo "Overhead allocation complete.\n";

    // -----------------------------------------------------------------------
    // Allocate feed/medicine/vaccination cost to batch_costs for consumption
    // events, based on inventory_adjustments 'Reduce' rows tied to a flock.
    // -----------------------------------------------------------------------
    $consumptionRows = $pdo->query(
        "SELECT ia.item_id, ia.quantity, ia.reason, ia.source, ia.source_id, pi.unit_cost, pi.category,
                COALESCE(hr.flock_id, fa.flock_id) AS flock_id, COALESCE(hr.record_date, fa.completed_date, fa.scheduled_date) AS cost_date
         FROM inventory_adjustments ia
         JOIN poultry_inventory pi ON pi.item_id = ia.item_id
         LEFT JOIN health_records hr ON ia.source = 'Health Record' AND ia.source_id = hr.health_id
         LEFT JOIN farm_activities fa ON ia.source = 'Farm Activity' AND ia.source_id = fa.activity_id
         WHERE ia.direction = 'Reduce' AND ia.source IN ('Health Record', 'Farm Activity')"
    )->fetchAll();
    $costCategoryByItemCategory = ['Vaccine' => 'Vaccination', 'Medicine' => 'Medicine', 'Feed' => 'Feed', 'Farm Supply' => 'Farm Supplies', 'Other' => 'Other'];
    foreach ($consumptionRows as $row) {
        if ($row['flock_id'] === null || $row['cost_date'] === null) {
            continue;
        }
        $amount = money2((float) $row['quantity'] * (float) $row['unit_cost']);
        if ($amount <= 0) {
            continue;
        }
        $costCategory = $costCategoryByItemCategory[$row['category']] ?? 'Other';
        $insertBatchCost->execute([
            $row['flock_id'], $row['cost_date'], $costCategory,
            'Allocated cost of ' . $row['reason'], $amount, $row['source'] . ':' . $row['source_id'],
        ]);
    }
    echo "Consumption-based batch cost allocation complete (" . count($consumptionRows) . " rows considered).\n";

    // -----------------------------------------------------------------------
    // AR/AP collections — settle a realistic mix: most paid on time, some
    // partial, a handful left genuinely overdue as of "today".
    // -----------------------------------------------------------------------

    /** Mirrors ais_post_ar_ap_payment() but lets the caller backdate payment_date for historical realism. */
    function postArApPayment(PDO $pdo, string $ledger, int $recordId, float $amount, string $paymentMethod, ?string $category, ?int $flockId, string $paymentDate): void
    {
        $table = $ledger === 'AR' ? 'accounts_receivable' : 'accounts_payable';
        $idCol = $ledger === 'AR' ? 'ar_id' : 'ap_id';
        $current = $pdo->prepare("SELECT amount_due, amount_paid, due_date FROM $table WHERE $idCol = ?");
        $current->execute([$recordId]);
        $row = $current->fetch();
        if (!$row) {
            return;
        }
        $newPaid = money2(min((float) $row['amount_due'], (float) $row['amount_paid'] + $amount));
        $newStatus = ar_ap_effective_status((float) $row['amount_due'], $newPaid, (string) $row['due_date']);
        $pdo->prepare("UPDATE $table SET amount_paid = ?, status = ? WHERE $idCol = ?")->execute([$newPaid, $newStatus, $recordId]);

        $transactionType = $ledger === 'AR' ? 'Income' : 'Expense';
        $category = $category ?? ($ledger === 'AR' ? 'Accounts Receivable Collection' : 'Accounts Payable Settlement');
        $description = ($ledger === 'AR' ? 'Payment received for receivable #' : 'Payment made for payable #') . $recordId;
        $pdo->prepare(
            'INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$paymentDate, $transactionType, $category, $description, $amount, $flockId, $paymentMethod, 'Recorded']);
        $transactionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO ar_ap_payments (ledger, record_id, amount, payment_date, payment_method, transaction_id, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$ledger, $recordId, $amount, $paymentDate, $paymentMethod, $transactionId, 'data_migration']);
    }

    echo "Posting AR/AP collections...\n";
    // Payment EVENTS are historical operational data and must stay inside the
    // dataset's own window (<= WINDOW_END), even though real "today" (used by
    // ar_ap_effective_status() for the live Unpaid/Overdue/Paid status) is later.
    $maxEventDate = WINDOW_END;

    $arRows = $pdo->query(
        "SELECT ar.ar_id, ar.invoice_date, ar.due_date, ar.amount_due, ar.amount_paid, ar.flock_id,
                IF(f.batch_code LIKE 'SVPF-LY-%', 'Egg Sales', 'Poultry Sales') AS sale_category
         FROM accounts_receivable ar LEFT JOIN flocks f ON f.flock_id = ar.flock_id
         WHERE ar.deleted_at IS NULL"
    )->fetchAll();
    foreach ($arRows as $ar) {
        $outcome = mt_rand(1, 100);
        $amountDue = (float) $ar['amount_due'];
        $saleCategory = $ar['sale_category'];
        if ($outcome <= 70) {
            // Paid in full, a few days before/at due date.
            $payDate = addDays($ar['due_date'], -ri(0, 4));
            if ($payDate > $maxEventDate) {
                $payDate = $maxEventDate;
            }
            if ($payDate >= $ar['invoice_date']) {
                postArApPayment($pdo, 'AR', (int) $ar['ar_id'], $amountDue, pick(['Cash', 'Bank Transfer', 'E-Wallet']), $saleCategory, $ar['flock_id'] !== null ? (int) $ar['flock_id'] : null, $payDate);
            }
        } elseif ($outcome <= 90) {
            // Partially paid.
            $partial = money2($amountDue * r(0.3, 0.7));
            $payDate = addDays($ar['invoice_date'], ri(3, 10));
            if ($payDate <= $maxEventDate) {
                postArApPayment($pdo, 'AR', (int) $ar['ar_id'], $partial, pick(['Cash', 'Bank Transfer']), $saleCategory, $ar['flock_id'] !== null ? (int) $ar['flock_id'] : null, $payDate);
            }
        }
        // Remaining ~10%: left untouched (unpaid/overdue) for realism.
    }

    $apRows = $pdo->query('SELECT ap_id, supplier_name, invoice_date, due_date, amount_due, amount_paid, flock_id FROM accounts_payable WHERE deleted_at IS NULL')->fetchAll();
    foreach ($apRows as $ap) {
        $outcome = mt_rand(1, 100);
        $amountDue = (float) $ap['amount_due'];
        if ($outcome <= 75) {
            $payDate = addDays($ap['due_date'], -ri(0, 5));
            if ($payDate > $maxEventDate) {
                $payDate = $maxEventDate;
            }
            if ($payDate >= $ap['invoice_date']) {
                postArApPayment($pdo, 'AP', (int) $ap['ap_id'], $amountDue, pick(['Cash', 'Bank Transfer']), null, $ap['flock_id'] !== null ? (int) $ap['flock_id'] : null, $payDate);
            }
        } elseif ($outcome <= 92) {
            $partial = money2($amountDue * r(0.3, 0.7));
            $payDate = addDays($ap['invoice_date'], ri(3, 10));
            if ($payDate <= $maxEventDate) {
                postArApPayment($pdo, 'AP', (int) $ap['ap_id'], $partial, pick(['Cash', 'Bank Transfer']), null, $ap['flock_id'] !== null ? (int) $ap['flock_id'] : null, $payDate);
            }
        }
    }

    echo "AR/AP collections posted.\n";

    // -----------------------------------------------------------------------
    // Replenishment snapshots: for each item, a handful of recommendation
    // snapshots over the 2 years using the app's own formula, reflecting that
    // item's state at roughly quarterly checkpoints.
    // -----------------------------------------------------------------------
    echo "Generating replenishment snapshots...\n";
    $quarterDates = [];
    $qCursor = WINDOW_START;
    while ($qCursor <= WINDOW_END) {
        $quarterDates[] = $qCursor;
        $qCursor = addDays($qCursor, 75);
    }
    $insertReplenishment = $pdo->prepare(
        'INSERT INTO inventory_replenishment
            (item_id, current_stock, expected_demand, recommended_quantity, unit_price, available_budget, recommendation, recommendation_status, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $workflowStatuses = ['Pending', 'Approved', 'Rejected', 'Completed'];
    foreach ($inventoryCatalog as $item) {
        $code = $item['code'];
        $itemId = $itemIds[$code];
        $liveRow = $pdo->prepare('SELECT quantity, reorder_level, unit_cost FROM poultry_inventory WHERE item_id = ?');
        $liveRow->execute([$itemId]);
        $live = $liveRow->fetch();
        if (!$live) {
            continue;
        }
        foreach (array_slice($quarterDates, 0, 6) as $qi => $qDate) {
            $currentStock = max(0, (float) $live['quantity'] + r(-0.3, 0.3) * (float) $live['quantity'] * ($qi < 5 ? 1 : 0));
            $expectedDemand = money2(max(1, (float) $live['reorder_level'] * r(0.8, 1.6)));
            $unitPrice = (float) $live['unit_cost'];
            $availableBudget = money2($expectedDemand * $unitPrice * r(0.7, 1.3));
            $estimatedCostForRecommendation = $expectedDemand * $unitPrice; // pre-formula estimate for the budget gate
            $rec = integrated_replenishment_recommendation($currentStock, (float) $live['reorder_level'], $expectedDemand, $estimatedCostForRecommendation, $availableBudget);
            $recommendedQty = money2($rec['recommended_quantity']);
            $recommendationText = $rec['status'] . ' — ' . $rec['reason'];
            $workflowStatus = $qi >= 5 ? 'Pending' : pick($workflowStatuses);
            $insertReplenishment->execute([
                $itemId, money2($currentStock), $expectedDemand, $recommendedQty, $unitPrice, $availableBudget,
                $recommendationText, $rec['status'], $workflowStatus, $qDate . ' 09:00:00',
            ]);
        }
    }
    echo "Replenishment snapshots generated.\n";

    // -----------------------------------------------------------------------
    // Scenario projections — demonstration scenarios using the app's own
    // integrated_scenario_projection() formula against real flock history.
    // Never written as actual transactions; is_auto_projected = 1 throughout.
    // -----------------------------------------------------------------------
    echo "Generating scenario projections...\n";
    // integrated_scenario_projection() requires current_birds > 0 on the base flock
    // (it scales the flock's OWN current size), so this can only use flocks that are
    // still mid-cycle as of the dataset's window end — not fully harvested/sold ones.
    $soldBroilerFlocks = $pdo->query(
        "SELECT flock_id, batch_code, initial_birds FROM flocks WHERE batch_code LIKE 'SVPF-BR-%' AND current_birds > 0 ORDER BY flock_id DESC LIMIT 10"
    )->fetchAll();
    if ($soldBroilerFlocks === []) {
        // Fall back to any flock with current birds (e.g. an in-progress layer cohort).
        $soldBroilerFlocks = $pdo->query(
            'SELECT flock_id, batch_code, initial_birds FROM flocks WHERE current_birds > 0 ORDER BY flock_id DESC LIMIT 10'
        )->fetchAll();
    }
    $scenarioDefs = [
        ['label' => '+10% Feed Price', 'feed' => 10, 'egg' => 0, 'mort' => 0],
        ['label' => '+15% Feed Price', 'feed' => 15, 'egg' => 0, 'mort' => 0],
        ['label' => '-5% Selling Price', 'feed' => 0, 'egg' => -5, 'mort' => 0],
        ['label' => 'Increased Mortality (+6pp)', 'feed' => 0, 'egg' => 0, 'mort' => 6],
        ['label' => 'Improved FCR / Reduced Feed Cost (-8%)', 'feed' => -8, 'egg' => 0, 'mort' => 0],
        ['label' => 'Reduced Mortality from Biosecurity (-3pp)', 'feed' => 0, 'egg' => 0, 'mort' => -3],
        ['label' => 'Increased Placement Volume (+20%)', 'feed' => 0, 'egg' => 0, 'mort' => 0, 'sizeMultiplier' => 1.2],
        ['label' => 'Higher Electricity Cost (feed-adjacent +5%)', 'feed' => 5, 'egg' => 0, 'mort' => 0],
    ];
    $insertScenario = $pdo->prepare(
        'INSERT INTO scenarios
            (scenario_name, description, current_flock_size, proposed_flock_size, base_flock_id,
             feed_price_change_percent, egg_price_change_percent, mortality_rate_percent, is_auto_projected,
             projected_production, projected_cost, projected_revenue)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
    );
    $scenarioCount = 0;
    foreach (array_slice($soldBroilerFlocks, 0, 6) as $flock) {
        foreach ($scenarioDefs as $def) {
            $proposedSize = (int) round((int) $flock['initial_birds'] * ($def['sizeMultiplier'] ?? 1.0));
            $projection = integrated_scenario_projection($pdo, (int) $flock['flock_id'], $proposedSize, (float) $def['feed'], (float) $def['egg'], (float) $def['mort']);
            if (!$projection['ok']) {
                continue;
            }
            $insertScenario->execute([
                "{$def['label']} — {$flock['batch_code']}",
                "Projection scenario applying {$def['label']} to batch {$flock['batch_code']}'s actual recorded performance. Demonstration projection only — not an actual transaction.",
                $projection['current_flock_size'], $proposedSize, $flock['flock_id'],
                $def['feed'], $def['egg'], $def['mort'],
                $projection['projected_production'], $projection['projected_cost'], $projection['projected_revenue'],
            ]);
            $scenarioCount++;
        }
    }
    echo "Generated $scenarioCount scenario projections.\n";

    // -----------------------------------------------------------------------
    // batch_profitability — recalculated via the app's own cache-refresh logic.
    // -----------------------------------------------------------------------
    integrated_recalculate_all_batch_profitability($pdo);
    echo "batch_profitability cache refreshed for all flocks.\n";

    $pdo->commit();
    echo "\n=== COMMIT SUCCESSFUL ===\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "FAILED — rolled back. Error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// SUMMARY COUNTS
// ---------------------------------------------------------------------------
$counts = [];
foreach (['flocks', 'poultry_inventory', 'farm_activities', 'production_records', 'health_records',
          'inventory_adjustments', 'financial_transactions', 'accounts_receivable', 'accounts_payable',
          'ar_ap_payments', 'batch_costs', 'batch_profitability', 'inventory_replenishment', 'scenarios'] as $table) {
    $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}
echo "\n=== RECORD COUNTS ===\n";
foreach ($counts as $table => $count) {
    echo sprintf("  %-28s %d\n", $table, $count);
}
echo "\nDone.\n";
