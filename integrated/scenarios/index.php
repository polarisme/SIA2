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

    if ($action === 'create') {
        $formData = scenario_form_data($_POST);
        $errors = validate_scenario($formData);
        if ($errors === []) {
            $statement = $pdo->prepare(
                'INSERT INTO scenarios
                    (scenario_name, description, current_flock_size, proposed_flock_size, projected_production, projected_cost, projected_revenue)
                 VALUES (:scenario_name, :description, :current_flock_size, :proposed_flock_size, :projected_production, :projected_cost, :projected_revenue)'
            );
            $statement->execute([
                'scenario_name' => $formData['scenario_name'],
                'description' => $formData['description'] !== '' ? $formData['description'] : null,
                'current_flock_size' => $formData['current_flock_size'] !== '' ? (int) $formData['current_flock_size'] : 0,
                'proposed_flock_size' => $formData['proposed_flock_size'] !== '' ? (int) $formData['proposed_flock_size'] : 0,
                'projected_production' => $formData['projected_production'] !== '' ? (float) $formData['projected_production'] : 0,
                'projected_cost' => $formData['projected_cost'] !== '' ? (float) $formData['projected_cost'] : 0,
                'projected_revenue' => $formData['projected_revenue'] !== '' ? (float) $formData['projected_revenue'] : 0,
            ]);
            audit_pms_action($pdo, 'CREATE', 'scenarios', (int) $pdo->lastInsertId());
            set_flash('success', 'Scenario saved. This is a simulation only — no PMS or AIS records were changed.');
            redirect_to('index.php');
        }
        $action = 'add';
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

if ($action === 'add' && $pdo instanceof PDO) {
    $formData = $formData ?? scenario_form_data([]);
    $errors = $errors ?? [];

    $pageTitle = 'New Scenario';
    require __DIR__ . '/../../shared/includes/header.php';
    ?>
    <div class="page-heading">
        <div class="eyebrow">FARM DECISION SUPPORT — SCENARIO ANALYSIS</div>
        <h1>New Scenario</h1>
        <a class="btn btn-outline-secondary btn-sm mt-2" href="index.php">Back to scenarios</a>
    </div>
    <div class="alert alert-info" role="status"><strong>PROJECTED / SCENARIO:</strong> figures entered here are a simulation only. Saving a scenario never modifies any real PMS or AIS record.</div>
    <section class="content-panel" style="max-width: 760px">
        <div class="panel-body">
            <form method="post" action="index.php?action=create" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
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
                        <label class="form-label" for="current_flock_size">Current flock size (birds)</label>
                        <input class="form-control<?= isset($errors['current_flock_size']) ? ' is-invalid' : '' ?>" type="number" id="current_flock_size" name="current_flock_size" min="0" step="1" value="<?= h($formData['current_flock_size']) ?>">
                        <?php if (isset($errors['current_flock_size'])): ?><div class="invalid-feedback"><?= h($errors['current_flock_size']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="proposed_flock_size">Proposed flock size (birds)</label>
                        <input class="form-control<?= isset($errors['proposed_flock_size']) ? ' is-invalid' : '' ?>" type="number" id="proposed_flock_size" name="proposed_flock_size" min="0" step="1" value="<?= h($formData['proposed_flock_size']) ?>">
                        <?php if (isset($errors['proposed_flock_size'])): ?><div class="invalid-feedback"><?= h($errors['proposed_flock_size']) ?></div><?php endif; ?>
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
                        <button class="btn btn-primary" type="submit">Save scenario</button>
                        <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </section>
    <?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
    <?php exit; ?>
<?php }

$scenarios = [];
if ($pdo instanceof PDO && $databaseError === null) {
    try {
        $scenarios = $pdo->query('SELECT * FROM scenarios WHERE deleted_at IS NULL ORDER BY created_at DESC')->fetchAll();
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
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Scenario</th><th class="text-end">Current Size</th><th class="text-end">Proposed Size</th><th class="text-end">Proj. Production</th><th class="text-end">Proj. Cost</th><th class="text-end">Proj. Revenue</th><th class="text-end">Proj. Profit</th><th>Saved</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php if ($scenarios === []): ?>
                    <tr><td colspan="9" class="text-center text-secondary py-4">No scenarios saved yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($scenarios as $scenario): ?>
                        <tr>
                            <td>
                                <strong><?= h($scenario['scenario_name']) ?></strong>
                                <?php if ($scenario['description']): ?><div class="small text-secondary"><?= h($scenario['description']) ?></div><?php endif; ?>
                            </td>
                            <td class="text-end"><?= number_format((int) $scenario['current_flock_size']) ?></td>
                            <td class="text-end"><?= number_format((int) $scenario['proposed_flock_size']) ?></td>
                            <td class="text-end"><?= number_format((float) $scenario['projected_production']) ?></td>
                            <td class="text-end">₱<?= money($scenario['projected_cost']) ?></td>
                            <td class="text-end">₱<?= money($scenario['projected_revenue']) ?></td>
                            <td class="text-end <?= (float) $scenario['projected_profit'] >= 0 ? 'text-success' : 'text-danger' ?> fw-bold">₱<?= money($scenario['projected_profit']) ?></td>
                            <td><?= h((string) $scenario['created_at']) ?></td>
                            <td class="text-end">
                                <form method="post" action="index.php?action=delete" onsubmit="return confirm('Move this scenario to the Recycle Bin?\nIt can be restored later.');">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="scenario_id" value="<?= (int) $scenario['scenario_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
