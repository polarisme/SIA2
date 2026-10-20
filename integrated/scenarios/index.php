<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$activeSystem = 'integrated';
$activeNavigation = 'integrated_scenarios';
$appBrandName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';
$appFooterName = 'YIELD Integrated Poultry Farm Operations & Accounting Management System';

function scenario_form_data(array $source): array
{
    return [
        'scenario_name' => trim((string) ($source['scenario_name'] ?? '')),
        'description' => trim((string) ($source['description'] ?? '')),
        'current_flock_size' => trim((string) ($source['current_flock_size'] ?? '')),
        'proposed_flock_size' => trim((string) ($source['proposed_flock_size'] ?? '')),
        'base_flock_id' => trim((string) ($source['base_flock_id'] ?? '')),
        'feed_price_change_percent' => trim((string) ($source['feed_price_change_percent'] ?? '0')),
        'egg_price_change_percent' => trim((string) ($source['egg_price_change_percent'] ?? '0')),
        'mortality_rate_percent' => trim((string) ($source['mortality_rate_percent'] ?? '0')),
        'is_auto_projected' => trim((string) ($source['is_auto_projected'] ?? '0')),
        'projected_production' => trim((string) ($source['projected_production'] ?? '')),
        'projected_cost' => trim((string) ($source['projected_cost'] ?? '')),
        'projected_revenue' => trim((string) ($source['projected_revenue'] ?? '')),
    ];
}

function validate_scenario(array $data): array
{
    $errors = [];
    if ($data['scenario_name'] === '' || mb_strlen($data['scenario_name']) > 150) {
        $errors['scenario_name'] = 'Scenario name is required (up to 150 characters).';
    }
    foreach (['current_flock_size', 'proposed_flock_size'] as $field) {
        if ($data[$field] !== '' && filter_var($data[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            $errors[$field] = 'Enter a whole number of 0 or more.';
        }
    }
    foreach (['projected_production', 'projected_cost', 'projected_revenue'] as $field) {
        if ($data[$field] !== '' && ais_parse_nonnegative_amount($data[$field]) === false) {
            $errors[$field] = 'Enter a valid non-negative number.';
        }
    }
    if ($data['base_flock_id'] !== '' && filter_var($data['base_flock_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        $errors['base_flock_id'] = 'Select a valid flock.';
    }
    foreach (['feed_price_change_percent', 'egg_price_change_percent'] as $field) {
        if (!preg_match('/\A-?\d{1,3}(?:\.\d{1,2})?\z/', $data[$field])) {
            $errors[$field] = 'Enter a percentage between -999 and 999.';
        }
    }
    if (!preg_match('/\A\d{1,3}(?:\.\d{1,2})?\z/', $data['mortality_rate_percent']) || (float) $data['mortality_rate_percent'] > 100) {
        $errors['mortality_rate_percent'] = 'Enter a mortality rate between 0 and 100.';
    }

    return $errors;
}

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Integrated scenarios database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your form session expired. Please try again.');
        redirect_to('index.php');
    }

    if ($action === 'calculate') {
        // Read-only: computes a projection from a real flock's actual per-bird
        // figures and re-renders the add form with it pre-filled. Never saves
        // anything, and never touches PMS/AIS data — see
        // integrated_scenario_projection() for the calculation itself.
        $formData = scenario_form_data($_POST);
        $errors = [];
        $baseFlockId = $formData['base_flock_id'] !== '' ? (int) $formData['base_flock_id'] : null;
        $proposedSize = $formData['proposed_flock_size'] !== '' ? (int) $formData['proposed_flock_size'] : 0;
        if ($baseFlockId === null) {
            set_flash('danger', 'Select a flock to base the projection on.');
        } elseif ($proposedSize <= 0) {
            set_flash('danger', 'Enter a proposed flock size greater than zero.');
        } else {
            $projection = integrated_scenario_projection(
                $pdo, $baseFlockId, $proposedSize,
                (float) $formData['feed_price_change_percent'],
                (float) $formData['egg_price_change_percent'],
                (float) $formData['mortality_rate_percent']
            );
            if (!$projection['ok']) {
                set_flash('danger', $projection['reason']);
            } else {
                $formData['current_flock_size'] = (string) $projection['current_flock_size'];
                $formData['projected_production'] = (string) $projection['projected_production'];
                $formData['projected_cost'] = (string) $projection['projected_cost'];
                $formData['projected_revenue'] = (string) $projection['projected_revenue'];
                $formData['is_auto_projected'] = '1';
                set_flash('success', 'Projection calculated from flock #' . $baseFlockId . '\'s actual figures. Review before saving — you can still edit any value.');
            }
        }
        $action = 'add';
    } elseif ($action === 'create' || $action === 'update') {
        $isEditing = $action === 'update';
        $scenarioId = $isEditing ? filter_var($_POST['scenario_id'] ?? null, FILTER_VALIDATE_INT) : null;
        if ($isEditing && ($scenarioId === false || $scenarioId < 1)) {
            set_flash('danger', 'The selected scenario could not be found.');
            redirect_to('index.php');
        }
        $formData = scenario_form_data($_POST);
        $errors = validate_scenario($formData);
        if ($errors === []) {
            $values = [
                'scenario_name' => $formData['scenario_name'],
                'description' => $formData['description'] !== '' ? $formData['description'] : null,
                'current_flock_size' => $formData['current_flock_size'] !== '' ? (int) $formData['current_flock_size'] : 0,
                'proposed_flock_size' => $formData['proposed_flock_size'] !== '' ? (int) $formData['proposed_flock_size'] : 0,
                'base_flock_id' => $formData['base_flock_id'] !== '' ? (int) $formData['base_flock_id'] : null,
                'feed_price_change_percent' => (float) $formData['feed_price_change_percent'],
                'egg_price_change_percent' => (float) $formData['egg_price_change_percent'],
                'mortality_rate_percent' => (float) $formData['mortality_rate_percent'],
                'is_auto_projected' => $formData['is_auto_projected'] === '1' ? 1 : 0,
                'projected_production' => $formData['projected_production'] !== '' ? (float) $formData['projected_production'] : 0,
                'projected_cost' => $formData['projected_cost'] !== '' ? (float) $formData['projected_cost'] : 0,
                'projected_revenue' => $formData['projected_revenue'] !== '' ? (float) $formData['projected_revenue'] : 0,
            ];
            if ($isEditing) {
                $statement = $pdo->prepare(
                    'UPDATE scenarios SET
                        scenario_name = :scenario_name, description = :description,
                        current_flock_size = :current_flock_size, proposed_flock_size = :proposed_flock_size,
                        base_flock_id = :base_flock_id, feed_price_change_percent = :feed_price_change_percent,
                        egg_price_change_percent = :egg_price_change_percent, mortality_rate_percent = :mortality_rate_percent,
                        is_auto_projected = :is_auto_projected, projected_production = :projected_production,
                        projected_cost = :projected_cost, projected_revenue = :projected_revenue
                     WHERE scenario_id = :scenario_id AND deleted_at IS NULL'
                );
                $statement->execute($values + ['scenario_id' => $scenarioId]);
                audit_pms_action($pdo, 'UPDATE', 'scenarios', $scenarioId);
                set_flash('success', 'Scenario updated. This is a simulation only — no PMS or AIS records were changed.');
                redirect_to('index.php');
            }

            $statement = $pdo->prepare(
                'INSERT INTO scenarios
                    (scenario_name, description, current_flock_size, proposed_flock_size, base_flock_id,
                     feed_price_change_percent, egg_price_change_percent, mortality_rate_percent, is_auto_projected,
                     projected_production, projected_cost, projected_revenue)
                 VALUES (:scenario_name, :description, :current_flock_size, :proposed_flock_size, :base_flock_id,
                     :feed_price_change_percent, :egg_price_change_percent, :mortality_rate_percent, :is_auto_projected,
                     :projected_production, :projected_cost, :projected_revenue)'
            );
            $statement->execute($values);
            audit_pms_action($pdo, 'CREATE', 'scenarios', (int) $pdo->lastInsertId());
            set_flash('success', 'Scenario saved. This is a simulation only — no PMS or AIS records were changed.');
            redirect_to('index.php');
        }
        $action = $isEditing ? 'edit' : 'add';
        if ($isEditing) {
            $scenario = ['scenario_id' => $scenarioId] + $formData;
        }
    } elseif ($action === 'delete') {
        $scenarioId = filter_var($_POST['scenario_id'] ?? null, FILTER_VALIDATE_INT);
        if ($scenarioId !== false && $scenarioId >= 1) {
            if (integrated_soft_delete($pdo, 'scenarios', $scenarioId)) {
                set_flash('success', 'Scenario moved to the Recycle Bin.');
            } else {
                set_flash('danger', 'The scenario could not be found.');
            }
        }
        redirect_to('index.php');
    }
}

if (in_array($action, ['add', 'edit'], true) && $pdo instanceof PDO) {
    $isEditing = $action === 'edit';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($isEditing) {
            $scenarioId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($scenarioId === false || $scenarioId < 1) {
                set_flash('danger', 'The selected scenario could not be found.');
                redirect_to('index.php');
            }
            $statement = $pdo->prepare('SELECT * FROM scenarios WHERE scenario_id = ? AND deleted_at IS NULL');
            $statement->execute([$scenarioId]);
            $scenario = $statement->fetch();
            if (!$scenario) {
                set_flash('danger', 'The selected scenario could not be found.');
                redirect_to('index.php');
            }
            $formData = scenario_form_data($scenario);
        } else {
            $formData = scenario_form_data([]);
        }
        $errors = [];
    } else {
        $formData = $formData ?? scenario_form_data([]);
        $errors = $errors ?? [];
    }

    $flocks = $pdo->query("SELECT flock_id, batch_code, current_birds FROM flocks WHERE deleted_at IS NULL ORDER BY batch_code")->fetchAll();

    $pageTitle = $isEditing ? 'Edit Scenario' : 'New Scenario';
    require __DIR__ . '/../../shared/includes/header.php';
    ?>
    <div class="page-heading">
        <div class="eyebrow">FARM DECISION SUPPORT — SCENARIO ANALYSIS</div>
        <h1><?= $isEditing ? 'Edit Scenario' : 'New Scenario' ?></h1>
        <a class="btn btn-outline-secondary btn-sm mt-2" href="index.php">Back to scenarios</a>
    </div>
    <div class="alert alert-info" role="status"><strong>PROJECTED / SCENARIO:</strong> figures entered here are a simulation only. Saving a scenario never modifies any real PMS or AIS record.</div>
    <section class="content-panel mb-4" style="max-width: 760px">
        <div class="panel-heading"><h2 class="h5 mb-0">Base this projection on a real flock (optional)</h2></div>
        <div class="panel-body">
            <p class="small text-secondary">Choosing a flock and clicking "Calculate Projection" scales that batch's actual eggs/bird, revenue/bird, and cost/bird (by category) to the proposed size below, adjusted by the percentages you enter. Nothing is saved until you click "Save scenario", and nothing here ever changes a real PMS or AIS record.</p>
        </div>
    </section>
    <section class="content-panel" style="max-width: 760px">
        <div class="panel-body">
            <?php if ($formData['is_auto_projected'] === '1'): ?>
                <div class="alert alert-success">
                    <span class="badge text-bg-success">AUTO-PROJECTED</span>
                    Production, cost, and revenue below were calculated from flock history. You can still edit any value before saving.
                </div>
            <?php endif; ?>
            <form method="post" novalidate id="scenario-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <?php if ($isEditing): ?><input type="hidden" name="scenario_id" value="<?= (int) $scenario['scenario_id'] ?>"><?php endif; ?>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="scenario_name">Scenario name <span class="text-danger">*</span></label>
                        <input class="form-control<?= isset($errors['scenario_name']) ? ' is-invalid' : '' ?>" id="scenario_name" name="scenario_name" maxlength="150" required value="<?= h($formData['scenario_name']) ?>" placeholder="e.g. Increase Flock A size by 20%">
                        <?php if (isset($errors['scenario_name'])): ?><div class="invalid-feedback"><?= h($errors['scenario_name']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?= h($formData['description']) ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="base_flock_id">Base on flock</label>
                        <select class="form-select<?= isset($errors['base_flock_id']) ? ' is-invalid' : '' ?>" id="base_flock_id" name="base_flock_id">
                            <option value="">— None (enter figures manually) —</option>
                            <?php foreach ($flocks as $flockOption): ?>
                                <option value="<?= (int) $flockOption['flock_id'] ?>" <?= $formData['base_flock_id'] === (string) $flockOption['flock_id'] ? 'selected' : '' ?>><?= h($flockOption['batch_code']) ?> (<?= number_format((int) $flockOption['current_birds']) ?> birds)</option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['base_flock_id'])): ?><div class="invalid-feedback"><?= h($errors['base_flock_id']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="proposed_flock_size">Proposed flock size (birds)</label>
                        <input class="form-control<?= isset($errors['proposed_flock_size']) ? ' is-invalid' : '' ?>" type="number" id="proposed_flock_size" name="proposed_flock_size" min="0" step="1" value="<?= h($formData['proposed_flock_size']) ?>">
                        <?php if (isset($errors['proposed_flock_size'])): ?><div class="invalid-feedback"><?= h($errors['proposed_flock_size']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="feed_price_change_percent">Feed price change (%)</label>
                        <input class="form-control<?= isset($errors['feed_price_change_percent']) ? ' is-invalid' : '' ?>" type="number" id="feed_price_change_percent" name="feed_price_change_percent" step="0.01" value="<?= h($formData['feed_price_change_percent']) ?>">
                        <?php if (isset($errors['feed_price_change_percent'])): ?><div class="invalid-feedback"><?= h($errors['feed_price_change_percent']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="egg_price_change_percent">Egg price change (%)</label>
                        <input class="form-control<?= isset($errors['egg_price_change_percent']) ? ' is-invalid' : '' ?>" type="number" id="egg_price_change_percent" name="egg_price_change_percent" step="0.01" value="<?= h($formData['egg_price_change_percent']) ?>">
                        <?php if (isset($errors['egg_price_change_percent'])): ?><div class="invalid-feedback"><?= h($errors['egg_price_change_percent']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="mortality_rate_percent">Mortality rate (%)</label>
                        <input class="form-control<?= isset($errors['mortality_rate_percent']) ? ' is-invalid' : '' ?>" type="number" id="mortality_rate_percent" name="mortality_rate_percent" min="0" max="100" step="0.01" value="<?= h($formData['mortality_rate_percent']) ?>">
                        <?php if (isset($errors['mortality_rate_percent'])): ?><div class="invalid-feedback"><?= h($errors['mortality_rate_percent']) ?></div><?php endif; ?>
                    </div>
                    <input type="hidden" name="is_auto_projected" value="<?= h($formData['is_auto_projected']) ?>">
                    <div class="col-12">
                        <button class="btn btn-outline-primary" type="submit" formaction="index.php?action=calculate" onclick="document.querySelector('[name=is_auto_projected]').value='0';">Calculate Projection</button>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="current_flock_size">Current flock size (birds)</label>
                        <input class="form-control<?= isset($errors['current_flock_size']) ? ' is-invalid' : '' ?>" type="number" id="current_flock_size" name="current_flock_size" min="0" step="1" value="<?= h($formData['current_flock_size']) ?>">
                        <?php if (isset($errors['current_flock_size'])): ?><div class="invalid-feedback"><?= h($errors['current_flock_size']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="projected_production">Projected production (eggs)</label>
                        <input class="form-control<?= isset($errors['projected_production']) ? ' is-invalid' : '' ?>" type="number" id="projected_production" name="projected_production" min="0" step="0.01" value="<?= h($formData['projected_production']) ?>">
                        <?php if (isset($errors['projected_production'])): ?><div class="invalid-feedback"><?= h($errors['projected_production']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="projected_cost">Projected cost (₱)</label>
                        <input class="form-control<?= isset($errors['projected_cost']) ? ' is-invalid' : '' ?>" type="number" id="projected_cost" name="projected_cost" min="0" step="0.01" value="<?= h($formData['projected_cost']) ?>">
                        <?php if (isset($errors['projected_cost'])): ?><div class="invalid-feedback"><?= h($errors['projected_cost']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="projected_revenue">Projected revenue (₱)</label>
                        <input class="form-control<?= isset($errors['projected_revenue']) ? ' is-invalid' : '' ?>" type="number" id="projected_revenue" name="projected_revenue" min="0" step="0.01" value="<?= h($formData['projected_revenue']) ?>">
                        <?php if (isset($errors['projected_revenue'])): ?><div class="invalid-feedback"><?= h($errors['projected_revenue']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-secondary mb-0">Projected Profit will be calculated automatically as <strong>Projected Revenue − Projected Cost</strong> once saved.</div>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit" formaction="index.php?action=<?= $isEditing ? 'update' : 'create' ?>"><?= $isEditing ? 'Save changes' : 'Save scenario' ?></button>
                        <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </section>
    <?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
    <?php exit; ?>
<?php }

if ($action === 'compare' && $pdo instanceof PDO) {
    $compareIds = array_values(array_unique(array_filter(array_map(
        static fn ($value) => filter_var($value, FILTER_VALIDATE_INT),
        (array) ($_GET['scenario_id'] ?? [])
    ), static fn ($value) => $value !== false && $value >= 1)));

    $pageTitle = 'Compare Scenarios';
    require __DIR__ . '/../../shared/includes/header.php';
    ?>
    <div class="page-heading">
        <div class="eyebrow">FARM DECISION SUPPORT — SCENARIO ANALYSIS</div>
        <h1>Compare Scenarios</h1>
        <a class="btn btn-outline-secondary btn-sm mt-2" href="index.php">Back to scenarios</a>
    </div>
    <?php if (count($compareIds) < 2 || count($compareIds) > 3): ?>
        <div class="alert alert-danger" role="alert">Select 2 or 3 scenarios from the list to compare.</div>
    <?php else: ?>
        <?php
        $placeholders = implode(',', array_fill(0, count($compareIds), '?'));
        $compareStatement = $pdo->prepare("SELECT * FROM scenarios WHERE scenario_id IN ($placeholders) AND deleted_at IS NULL");
        $compareStatement->execute($compareIds);
        $compareScenarios = $compareStatement->fetchAll();
        ?>
        <?php if (count($compareScenarios) < 2): ?>
            <div class="alert alert-danger" role="alert">At least two of the selected scenarios could not be found.</div>
        <?php else: ?>
            <section class="content-panel">
                <div class="panel-heading"><h2 class="h5 mb-0">Side-by-side comparison</h2></div>
                <div class="table-responsive">
                    <table class="table table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <?php foreach ($compareScenarios as $compareScenario): ?>
                                    <th><?= h($compareScenario['scenario_name']) ?><?php if ((int) $compareScenario['is_auto_projected'] === 1): ?> <span class="badge text-bg-success">AUTO</span><?php endif; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Current size (birds)</td><?php foreach ($compareScenarios as $s): ?><td class="text-end"><?= number_format((int) $s['current_flock_size']) ?></td><?php endforeach; ?></tr>
                            <tr><td>Proposed size (birds)</td><?php foreach ($compareScenarios as $s): ?><td class="text-end"><?= number_format((int) $s['proposed_flock_size']) ?></td><?php endforeach; ?></tr>
                            <tr><td>Projected production (eggs)</td><?php foreach ($compareScenarios as $s): ?><td class="text-end"><?= number_format((float) $s['projected_production']) ?></td><?php endforeach; ?></tr>
                            <tr><td>Projected cost</td><?php foreach ($compareScenarios as $s): ?><td class="text-end">₱<?= money($s['projected_cost']) ?></td><?php endforeach; ?></tr>
                            <tr><td>Projected revenue</td><?php foreach ($compareScenarios as $s): ?><td class="text-end">₱<?= money($s['projected_revenue']) ?></td><?php endforeach; ?></tr>
                            <tr>
                                <td>Projected profit</td>
                                <?php foreach ($compareScenarios as $s): ?>
                                    <td class="text-end <?= (float) $s['projected_profit'] >= 0 ? 'text-success' : 'text-danger' ?> fw-bold">₱<?= money($s['projected_profit']) ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <td>Profit per bird</td>
                                <?php foreach ($compareScenarios as $s): ?>
                                    <?php $perBird = (int) $s['proposed_flock_size'] > 0 ? (float) $s['projected_profit'] / (int) $s['proposed_flock_size'] : null; ?>
                                    <td class="text-end"><?= $perBird !== null ? '₱' . money($perBird) : '—' ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <td>ROI (%)</td>
                                <?php foreach ($compareScenarios as $s): ?>
                                    <?php $roi = (float) $s['projected_cost'] > 0 ? (float) $s['projected_profit'] / (float) $s['projected_cost'] * 100 : null; ?>
                                    <td class="text-end"><?= $roi !== null ? number_format($roi, 1) . '%' : '—' ?></td>
                                <?php endforeach; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
    <?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
    <?php exit; ?>
<?php }

$scenarios = [];
$totalRecords = 0;
['page' => $pageNum, 'per_page' => $perPage, 'offset' => $offset] = paginate_params();
if ($pdo instanceof PDO && $databaseError === null) {
    try {
        $totalRecords = (int) $pdo->query('SELECT COUNT(*) FROM scenarios WHERE deleted_at IS NULL')->fetchColumn();
        $scenarios = $pdo->query(
            "SELECT * FROM scenarios WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT $perPage OFFSET $offset"
        )->fetchAll();
    } catch (PDOException $exception) {
        error_log('Integrated scenarios list query failed: ' . $exception->getMessage());
        $databaseError = 'Scenarios could not be loaded because of a database error.';
    }
}

$pageTitle = 'Scenario Analysis';
require __DIR__ . '/../../shared/includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">FARM DECISION SUPPORT AND SCENARIO ANALYSIS</div>
    <h1>Scenario Analysis</h1>
    <p>Simulate operational and financial changes before committing to a real decision.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<section class="content-panel">
    <div class="panel-heading d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Saved Scenarios <span class="badge text-bg-secondary">PROJECTED / SCENARIO — simulation only</span></h2>
        <a class="btn btn-primary btn-sm" href="index.php?action=add">New scenario</a>
    </div>
    <form method="get" action="index.php">
        <input type="hidden" name="action" value="compare">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 table-compact">
                <colgroup>
                    <col style="width: 6%"><col style="width: 28%"><col style="width: 10%"><col style="width: 10%">
                    <col style="width: 14%"><col style="width: 12%"><col style="width: 20%">
                </colgroup>
                <thead><tr><th>Compare</th><th>Scenario</th><th class="text-end">Current Size</th><th class="text-end">Proposed Size</th><th class="text-end">Proj. Profit</th><th>Saved</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    <?php if ($scenarios === []): ?>
                        <tr><td colspan="7" class="text-center text-secondary py-4">No scenarios saved yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($scenarios as $scenario): ?>
                            <tr>
                                <td><input class="form-check-input" type="checkbox" name="scenario_id[]" value="<?= (int) $scenario['scenario_id'] ?>"></td>
                                <td class="cell-wrap">
                                    <strong><?= h($scenario['scenario_name']) ?></strong>
                                    <?php if ((int) $scenario['is_auto_projected'] === 1): ?><span class="badge text-bg-success">AUTO-PROJECTED</span><?php endif; ?>
                                    <?php if ($scenario['description']): ?><div class="small text-secondary"><?= h($scenario['description']) ?></div><?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format((int) $scenario['current_flock_size']) ?></td>
                                <td class="text-end"><?= number_format((int) $scenario['proposed_flock_size']) ?></td>
                                <td class="text-end <?= (float) $scenario['projected_profit'] >= 0 ? 'text-success' : 'text-danger' ?> fw-bold">₱<?= money($scenario['projected_profit']) ?></td>
                                <td><span class="cell-id" title="<?= h((string) $scenario['created_at']) ?>"><?= h((string) $scenario['created_at']) ?></span></td>
                                <td class="text-end table-detail-toggle">
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#scenario-detail-<?= (int) $scenario['scenario_id'] ?>" aria-expanded="false" aria-label="Show more detail for scenario <?= (int) $scenario['scenario_id'] ?>">
                                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                        </button>
                                        <a class="btn btn-sm btn-outline-primary" href="index.php?action=edit&amp;id=<?= (int) $scenario['scenario_id'] ?>">Edit</a>
                                        <form method="post" action="index.php?action=delete" class="d-inline" onsubmit="return confirm('Move this scenario to the Recycle Bin?\nIt can be restored later.');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="scenario_id" value="<?= (int) $scenario['scenario_id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr class="collapse table-detail-row" id="scenario-detail-<?= (int) $scenario['scenario_id'] ?>">
                                <td colspan="7">
                                    <div class="table-detail-body row g-3">
                                        <div class="col-sm-4"><div class="detail-label">Projected Production (eggs)</div><div class="detail-value"><?= number_format((float) $scenario['projected_production']) ?></div></div>
                                        <div class="col-sm-4"><div class="detail-label">Projected Cost</div><div class="detail-value">₱<?= money($scenario['projected_cost']) ?></div></div>
                                        <div class="col-sm-4"><div class="detail-label">Projected Revenue</div><div class="detail-value">₱<?= money($scenario['projected_revenue']) ?></div></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($scenarios !== []): ?>
            <div class="panel-body">
                <button class="btn btn-outline-primary btn-sm" type="submit">Compare Selected (2–3)</button>
            </div>
        <?php endif; ?>
    </form>
    <?php render_pagination_controls($pageNum, $perPage, $totalRecords, $_GET, 'index.php'); ?>
</section>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
