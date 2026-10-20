<?php
declare(strict_types=1);

// Shared helpers for the Integrated System (Poultry Farm Operations & Accounting
// Management System). Mirrors the conventions of pms_records.php / ais_records.php.
//
// Design notes (read before changing the calculations below):
//   - financial_transactions.flock_id is the existing PMS<->AIS relationship. It is
//     the primary, always-live source of a batch's recorded revenue and cost —
//     nothing from it is copied into another table.
//   - batch_costs holds ADDITIONAL costs that are not (yet) a financial_transactions
//     row — typically farm-wide overhead (labor, utilities, farm supplies) allocated
//     to a specific batch. It supplements the AIS ledger; it never replaces it.
//   - batch_profitability is a refreshed CACHE of the calculation below (it has a
//     UNIQUE KEY on flock_id and a calculation_date column for exactly this). It is
//     recalculated live every time it's needed; nothing is ever hand-typed into it.

const INTEGRATED_COST_CATEGORIES = ['Feed', 'Medicine', 'Vaccination', 'Labor', 'Utilities', 'Farm Supplies', 'Other'];
const INTEGRATED_REPLENISHMENT_STATUSES = ['Pending', 'Approved', 'Rejected', 'Completed'];

/** Maps an AIS free-text transaction category ("Feed Expense") onto the batch_costs enum ("Feed"). */
function integrated_map_cost_category(string $aisCategory): string
{
    $normalized = trim(preg_replace('/\s*Expense$/i', '', $aisCategory) ?? $aisCategory);
    foreach (INTEGRATED_COST_CATEGORIES as $category) {
        if (strcasecmp($category, $normalized) === 0) {
            return $category;
        }
    }

    return 'Other';
}

/**
 * A flock's revenue and cost, from live source data only:
 *   - revenue: SUM(financial_transactions.amount) WHERE flock_id = X AND transaction_type = 'Income' AND status <> 'Cancelled'
 *   - AIS cost: same, but transaction_type = 'Expense' (broken down by mapped category)
 *   - allocated cost: SUM(batch_costs.amount) WHERE flock_id = X (broken down by cost_category)
 * Cancelled transactions are excluded, matching the rule already enforced in AIS.
 */
function integrated_flock_financials(PDO $pdo, int $flockId): array
{
    $revenueStatement = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM financial_transactions
         WHERE flock_id = ? AND transaction_type = 'Income' AND status <> 'Cancelled' AND deleted_at IS NULL"
    );
    $revenueStatement->execute([$flockId]);
    $revenue = (float) $revenueStatement->fetchColumn();

    $aisCostByCategory = array_fill_keys(INTEGRATED_COST_CATEGORIES, 0.0);
    $costStatement = $pdo->prepare(
        "SELECT category, amount FROM financial_transactions
         WHERE flock_id = ? AND transaction_type = 'Expense' AND status <> 'Cancelled' AND deleted_at IS NULL"
    );
    $costStatement->execute([$flockId]);
    $transactionCount = 0;
    foreach ($costStatement->fetchAll() as $row) {
        $aisCostByCategory[integrated_map_cost_category($row['category'])] += (float) $row['amount'];
        $transactionCount++;
    }
    $aisCost = array_sum($aisCostByCategory);

    $allocatedCostByCategory = array_fill_keys(INTEGRATED_COST_CATEGORIES, 0.0);
    $allocatedStatement = $pdo->prepare(
        'SELECT cost_category, amount FROM batch_costs WHERE flock_id = ? AND deleted_at IS NULL'
    );
    $allocatedStatement->execute([$flockId]);
    $allocatedCount = 0;
    foreach ($allocatedStatement->fetchAll() as $row) {
        $allocatedCostByCategory[$row['cost_category']] += (float) $row['amount'];
        $allocatedCount++;
    }
    $allocatedCost = array_sum($allocatedCostByCategory);

    $costByCategory = [];
    foreach (INTEGRATED_COST_CATEGORIES as $category) {
        $costByCategory[$category] = $aisCostByCategory[$category] + $allocatedCostByCategory[$category];
    }

    $totalCost = $aisCost + $allocatedCost;

    return [
        'revenue' => $revenue,
        'ais_cost' => $aisCost,
        'allocated_cost' => $allocatedCost,
        'total_cost' => $totalCost,
        'cost_by_category' => $costByCategory,
        'ais_cost_by_category' => $aisCostByCategory,
        'allocated_cost_by_category' => $allocatedCostByCategory,
        'has_data' => $revenue > 0 || $totalCost > 0,
        'has_ais_transactions' => $transactionCount > 0,
        'has_allocated_costs' => $allocatedCount > 0,
    ];
}

/**
 * Scenario Analysis auto-projection (5.1a/b): scales a real flock's actual
 * per-bird figures (eggs/bird, revenue/bird, cost/bird by category — from
 * integrated_flock_financials() and production_records) to a proposed
 * flock size, with optional feed-price / egg-price / mortality-rate
 * adjustments. Purely a read + calculation; never writes to flocks,
 * production_records, or any PMS/AIS table.
 *
 * Returns ['ok' => bool, 'reason' => ?string, 'current_flock_size' => int,
 * 'projected_production' => float, 'projected_cost' => float,
 * 'projected_revenue' => float]. ok is false (with a human-readable reason)
 * when the base flock has no current birds or no recorded production to
 * scale from — a scenario is never fabricated from a flock with no data.
 */
function integrated_scenario_projection(
    PDO $pdo,
    int $baseFlockId,
    int $proposedSize,
    float $feedPriceChangePercent,
    float $eggPriceChangePercent,
    float $mortalityRatePercent
): array {
    $flockStatement = $pdo->prepare('SELECT current_birds FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
    $flockStatement->execute([$baseFlockId]);
    $currentBirds = $flockStatement->fetchColumn();
    if ($currentBirds === false) {
        return ['ok' => false, 'reason' => 'The selected flock could not be found.', 'current_flock_size' => 0];
    }
    $currentBirds = (int) $currentBirds;
    if ($currentBirds <= 0) {
        return ['ok' => false, 'reason' => 'The selected flock has no current birds to base a projection on.', 'current_flock_size' => 0];
    }

    $eggsStatement = $pdo->prepare(
        'SELECT COALESCE(SUM(eggs_produced), 0) FROM production_records WHERE flock_id = ? AND deleted_at IS NULL'
    );
    $eggsStatement->execute([$baseFlockId]);
    $totalEggs = (float) $eggsStatement->fetchColumn();

    $financials = integrated_flock_financials($pdo, $baseFlockId);
    if ($totalEggs <= 0 && !$financials['has_data']) {
        return [
            'ok' => false,
            'reason' => 'The selected flock has no recorded production or financial history to project from.',
            'current_flock_size' => $currentBirds,
        ];
    }

    $effectiveBirds = $proposedSize * max(0.0, 1 - $mortalityRatePercent / 100);
    $eggsPerBird = $totalEggs / $currentBirds;
    $revenuePerBird = $financials['revenue'] / $currentBirds;

    $projectedCost = 0.0;
    foreach ($financials['cost_by_category'] as $category => $amount) {
        $perBird = $amount / $currentBirds;
        $priceMultiplier = $category === 'Feed' ? (1 + $feedPriceChangePercent / 100) : 1.0;
        $projectedCost += $perBird * $effectiveBirds * $priceMultiplier;
    }
    $projectedProduction = $eggsPerBird * $effectiveBirds;
    $projectedRevenue = $revenuePerBird * $effectiveBirds * (1 + $eggPriceChangePercent / 100);

    return [
        'ok' => true,
        'reason' => null,
        'current_flock_size' => $currentBirds,
        'projected_production' => round(max(0.0, $projectedProduction), 2),
        'projected_cost' => round(max(0.0, $projectedCost), 2),
        'projected_revenue' => round(max(0.0, $projectedRevenue), 2),
    ];
}

/** Recalculates and upserts the batch_profitability cache row for one flock. Returns the fresh figures. */
function integrated_recalculate_batch_profitability(PDO $pdo, int $flockId): array
{
    $financials = integrated_flock_financials($pdo, $flockId);
    $statement = $pdo->prepare(
        'INSERT INTO batch_profitability (flock_id, total_cost, total_revenue, calculation_date)
         VALUES (:flock_id, :total_cost, :total_revenue, CURDATE())
         ON DUPLICATE KEY UPDATE total_cost = VALUES(total_cost), total_revenue = VALUES(total_revenue), calculation_date = VALUES(calculation_date)'
    );
    $statement->execute([
        'flock_id' => $flockId,
        'total_cost' => $financials['total_cost'],
        'total_revenue' => $financials['revenue'],
    ]);

    return $financials;
}

/** Refreshes the batch_profitability cache for every non-deleted flock. Call before reading aggregate KPIs. */
function integrated_recalculate_all_batch_profitability(PDO $pdo): void
{
    $flockIds = $pdo->query('SELECT flock_id FROM flocks WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($flockIds as $flockId) {
        integrated_recalculate_batch_profitability($pdo, (int) $flockId);
    }
}

/**
 * The financially-aware replenishment recommendation. Every input is either a live
 * PMS fact (current stock, reorder level) or an explicit user-entered projection
 * (expected demand, available budget) — nothing here is invented.
 *
 *   recommended_quantity = max(0, expected_demand + reorder_level - current_stock)
 *     (enough to cover the expected demand AND restore the buffer above reorder level)
 *   estimated_cost        = recommended_quantity * unit_price   (a generated column in SQL)
 *
 * Status:
 *   Sufficient Stock          — current stock already covers the reorder buffer and expected demand
 *   High Priority Replenishment — stock is at/near zero or badly under the reorder level
 *   Insufficient Budget        — replenishment is needed but its estimated cost exceeds the stated budget
 *   Replenishment Recommended  — replenishment is needed and affordable
 */
function integrated_replenishment_recommendation(float $currentStock, float $reorderLevel, float $expectedDemand, float $estimatedCost, float $availableBudget): array
{
    $recommendedQuantity = max(0.0, $expectedDemand + $reorderLevel - $currentStock);
    $isUrgent = $currentStock <= 0 || ($reorderLevel > 0 && $currentStock < $reorderLevel * 0.25);

    if ($recommendedQuantity <= 0 && $currentStock >= $expectedDemand && $currentStock > $reorderLevel) {
        $status = 'Sufficient Stock';
        $reason = 'Current stock already covers both the reorder buffer and the expected demand.';
    } elseif ($isUrgent) {
        $status = 'High Priority Replenishment';
        $reason = 'Current stock is at or near zero, or well below the reorder level.';
    } elseif ($estimatedCost > $availableBudget) {
        $status = 'Insufficient Budget';
        $reason = 'Replenishment is needed, but the estimated cost exceeds the stated available budget.';
    } else {
        $status = 'Replenishment Recommended';
        $reason = 'Replenishment is needed and the estimated cost fits within the stated available budget.';
    }

    return ['recommended_quantity' => $recommendedQuantity, 'status' => $status, 'reason' => $reason, 'is_urgent' => $isUrgent];
}

/**
 * Pearson correlation coefficient between two equal-length numeric series, or null
 * if it cannot be computed (fewer than 2 points, or one series has zero variance).
 * Callers are expected to also apply their own minimum-sample-size gate before
 * presenting this as a meaningful statistical claim — see MIN_CORRELATION_SAMPLE.
 */
function pearson_correlation(array $x, array $y): ?float
{
    $n = count($x);
    if ($n < 2 || $n !== count($y)) {
        return null;
    }
    $meanX = array_sum($x) / $n;
    $meanY = array_sum($y) / $n;
    $covariance = 0.0;
    $varianceX = 0.0;
    $varianceY = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = $x[$i] - $meanX;
        $dy = $y[$i] - $meanY;
        $covariance += $dx * $dy;
        $varianceX += $dx * $dx;
        $varianceY += $dy * $dy;
    }
    if ($varianceX <= 0.0 || $varianceY <= 0.0) {
        return null;
    }

    return $covariance / sqrt($varianceX * $varianceY);
}

/** Below this many data points, a correlation coefficient is not shown as a statistical claim. */
const MIN_CORRELATION_SAMPLE = 5;

/**
 * Urgency (what to DO about an item) and workflow (where the analysis is in
 * the approval process) are deliberately two separate scales, styled so
 * they never compete for attention — urgency's own badge uses the "subtle"
 * variant (see replenishment_urgency_badge_class() below): a light tinted
 * background with coloured text and a matching border, never a solid fill,
 * so a list of these never reads as a wall of colour; workflow uses
 * neutral/outline styling (see replenishment_workflow_badge()). `class` here
 * is still a plain Bootstrap colour name/pair (e.g. 'danger', 'warning
 * text-dark'). The row/card's left-edge accent strip is bright/saturated by
 * design (unlike the muted badge) and uses its own status-keyed modifier
 * class — see replenishment_urgency_accent_class() below.
 */
function replenishment_urgency_badge(string $status): array
{
    return match ($status) {
        'Sufficient Stock' => ['class' => 'success', 'icon' => 'bi-check-circle-fill'],
        'Replenishment Recommended' => ['class' => 'primary', 'icon' => 'bi-cart-plus'],
        'Insufficient Budget' => ['class' => 'warning text-dark', 'icon' => 'bi-cash-coin'],
        'High Priority Replenishment' => ['class' => 'danger', 'icon' => 'bi-exclamation-octagon-fill'],
        default => ['class' => 'secondary', 'icon' => 'bi-question-circle'],
    };
}

/**
 * The actual CSS classes for the urgency status badge pill itself — Bootstrap
 * 5.3's "subtle" colour utilities (light tinted background, coloured text,
 * matching border), not the solid text-bg-* fill. Takes the same 'class'
 * value replenishment_urgency_badge() returns (its first word is the colour).
 */
function replenishment_urgency_badge_class(string $urgencyClass): string
{
    $color = explode(' ', $urgencyClass)[0];

    return "badge rounded-pill bg-{$color}-subtle text-{$color}-emphasis border border-{$color}-subtle";
}

/**
 * Semantic modifier class for the thick (6px) left-edge status strip on the
 * Inventory Replenishment table rows/cards. Deliberately bright/saturated —
 * the one spot on this page allowed to be — and deliberately a separate
 * class from the badge's colour-word classes so the strip's jewel-tone
 * palette can be tuned (see app.css) without touching the badge styling.
 */
function replenishment_urgency_accent_class(string $status): string
{
    return match ($status) {
        'High Priority Replenishment' => 'replenishment-row--urgent',
        'Insufficient Budget' => 'replenishment-row--warning',
        'Replenishment Recommended' => 'replenishment-row--recommended',
        'Sufficient Stock' => 'replenishment-row--sufficient',
        default => '',
    };
}

/** A fixed, deterministic sort order for urgency — most urgent first. */
function replenishment_urgency_rank(string $status): int
{
    return match ($status) {
        'High Priority Replenishment' => 0,
        'Insufficient Budget' => 1,
        'Replenishment Recommended' => 2,
        'Sufficient Stock' => 3,
        default => 4,
    };
}

/** Restrained, pill-shaped styling for the workflow status — Pending stays
 * neutral; Approved/Completed read as a calm, settled green; Rejected is the
 * only one that still needs to visually stand out. */
function replenishment_workflow_badge(string $status): string
{
    return match ($status) {
        'Pending' => 'rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
        'Approved' => 'rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle',
        'Completed' => 'rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle',
        'Rejected' => 'rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle text-decoration-line-through',
        default => 'rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
    };
}

// =============================================================================
// Integrated System Recycle Bin — soft delete / restore / permanent delete.
// Mirrors the PMS (pms_records.php) and AIS (ais_records.php) recycle-bin
// pattern exactly, for the three Integrated tables that have a user-facing
// delete action: batch_costs, inventory_replenishment, scenarios.
// batch_profitability is deliberately excluded — it is a system-managed cache
// (integrated_recalculate_batch_profitability()) with no user delete action,
// not a user record type, so it has nothing to soft-delete.
// None of these three tables is referenced by a foreign key from any other
// table, so restoring/permanently deleting one never orphans another record.
// =============================================================================

function integrated_record_types(): array
{
    return [
        'batch_costs' => [
            'table' => 'batch_costs', 'id' => 'cost_id', 'label' => 'Batch Cost',
            'identity_sql' => "CONCAT(COALESCE((SELECT batch_code FROM flocks WHERE flocks.flock_id = batch_costs.flock_id), 'Unknown batch'), ': ', cost_category, ' \u{2014} \u{20b1}', amount)",
        ],
        'inventory_replenishment' => [
            'table' => 'inventory_replenishment', 'id' => 'replenishment_id', 'label' => 'Inventory Replenishment Analysis',
            'identity_sql' => "CONCAT(COALESCE((SELECT item_name FROM poultry_inventory WHERE poultry_inventory.item_id = inventory_replenishment.item_id), 'Unknown item'), ' (recommended qty: ', recommended_quantity, ')')",
        ],
        'scenarios' => [
            'table' => 'scenarios', 'id' => 'scenario_id', 'label' => 'Scenario',
            'identity_sql' => 'scenario_name',
        ],
    ];
}

function integrated_soft_delete(PDO $pdo, string $type, int $recordId): bool
{
    $types = integrated_record_types();
    if (!isset($types[$type]) || $recordId < 1) {
        return false;
    }
    $table = $types[$type]['table'];
    $idColumn = $types[$type]['id'];
    $statement = $pdo->prepare("UPDATE `$table` SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? WHERE `$idColumn` = ? AND deleted_at IS NULL");
    $statement->execute([current_username(), $recordId]);
    if ($statement->rowCount() !== 1) {
        return false;
    }
    audit_pms_action($pdo, 'SOFT_DELETE', $table, $recordId);

    return true;
}

function integrated_restore_record(PDO $pdo, string $type, int $recordId): bool
{
    $types = integrated_record_types();
    if (!isset($types[$type]) || $recordId < 1) {
        return false;
    }
    $table = $types[$type]['table'];
    $idColumn = $types[$type]['id'];
    $statement = $pdo->prepare("UPDATE `$table` SET deleted_at = NULL, deleted_by = NULL WHERE `$idColumn` = ? AND deleted_at IS NOT NULL");
    $statement->execute([$recordId]);
    if ($statement->rowCount() !== 1) {
        return false;
    }
    audit_pms_action($pdo, 'RESTORE', $table, $recordId);

    return true;
}

/** Permanent delete is only ever called on a row already sitting in the recycle bin. */
function integrated_permanent_delete(PDO $pdo, string $type, int $recordId): array
{
    $types = integrated_record_types();
    if (!isset($types[$type]) || $recordId < 1) {
        return ['deleted' => false, 'blockers' => ['Invalid record type or ID.']];
    }
    $table = $types[$type]['table'];
    $idColumn = $types[$type]['id'];
    $statement = $pdo->prepare("DELETE FROM `$table` WHERE `$idColumn` = ? AND deleted_at IS NOT NULL");
    $statement->execute([$recordId]);
    if ($statement->rowCount() !== 1) {
        return ['deleted' => false, 'blockers' => ['The record is not in the Recycle Bin, or could not be found.']];
    }
    audit_pms_action($pdo, 'PERMANENT_DELETE', $table, $recordId);

    return ['deleted' => true, 'blockers' => []];
}
