<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_once __DIR__ . '/../../shared/vendor/fpdf/fpdf.php';
require_authentication();

if (!is_poultry_admin()) {
    http_response_code(403);
    $pageTitle = 'Reports Access Denied';
    require __DIR__ . '/../../shared/includes/header.php';
    echo '<div class="alert alert-danger" role="alert">Only the Poultry Admin may generate PMS reports.</div>';
    require __DIR__ . '/../../shared/includes/footer.php';
    exit;
}

$reportTypes = [
    'flocks' => 'Flock Report',
    'inventory' => 'Inventory Report',
    'activities' => 'Farm Activity Report',
    'production' => 'Production Report',
    'health' => 'Health Report',
];
$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('PMS reports database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}
$pageTitle = 'PMS Reports';
$activeNavigation = 'reports';
$flocks = [];
if ($pdo instanceof PDO) {
    try {
        $flocks = $pdo->query('SELECT flock_id, batch_code FROM flocks WHERE deleted_at IS NULL ORDER BY batch_code')->fetchAll();
    } catch (PDOException $exception) {
        error_log('PMS report filter query failed: ' . $exception->getMessage());
        $databaseError = 'Report filters are unavailable because of a database error.';
    }
}

if (isset($_GET['type'])) {
    $type = (string) $_GET['type'];
    if (!isset($reportTypes[$type])) {
        http_response_code(400);
        exit('Unsupported report type.');
    }
    if (!$pdo instanceof PDO) {
        http_response_code(503);
        exit('Reports are temporarily unavailable because the database could not be reached.');
    }

    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    $flockId = trim((string) ($_GET['flock_id'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $activityType = trim((string) ($_GET['activity_type'] ?? ''));
    $healthType = trim((string) ($_GET['health_type'] ?? ''));
    $conditions = [];
    $parameters = [];

    foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
        $value = $field === 'date_from' ? $dateFrom : $dateTo;
        if ($value !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($parsed === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $value) {
                http_response_code(400);
                exit('Invalid report date filter.');
            }
            $conditions[] = 'report_date ' . $operator . ' ?';
            $parameters[] = $value;
        }
    }
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        http_response_code(400);
        exit('Date from must not be later than date to.');
    }
    if ($flockId !== '') {
        $validFlockId = filter_var($flockId, FILTER_VALIDATE_INT);
        if ($validFlockId === false || $validFlockId < 1) {
            http_response_code(400);
            exit('Invalid flock filter.');
        }
        $conditions[] = 'flock_id = ?';
        $parameters[] = $validFlockId;
    }

    $columns = [];
    $dateColumn = null;
    $flockColumn = null;
    switch ($type) {
        case 'flocks':
            $columns = ['Batch Code' => 'batch_code', 'Breed' => 'breed', 'Start Date' => 'start_date', 'Age (Days)' => 'age_days', 'Age (Weeks)' => 'age_weeks', 'Initial Birds' => 'initial_birds', 'Current Birds' => 'current_birds', 'Status' => 'status'];
            $conditions = ['deleted_at IS NULL'];
            $parameters = [];
            if ($dateFrom !== '' || $dateTo !== '') {
                foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
                    $value = $field === 'date_from' ? $dateFrom : $dateTo;
                    if ($value !== '') {
                        $conditions[] = 'start_date ' . $operator . ' ?';
                        $parameters[] = $value;
                    }
                }
            }
            if ($status !== '') {
                if (!in_array($status, ['Active', 'Completed', 'Sold', 'Terminated'], true)) { http_response_code(400); exit('Invalid flock status.'); }
                $conditions[] = 'status = ?'; $parameters[] = $status;
            }
            $sql = "SELECT batch_code, breed, start_date,
                        (DATEDIFF(CURDATE(), start_date) + age_at_arrival_days) AS age_days,
                        ROUND((DATEDIFF(CURDATE(), start_date) + age_at_arrival_days) / 7, 1) AS age_weeks,
                        initial_birds, current_birds, status
                    FROM flocks WHERE " . implode(' AND ', $conditions) . ' ORDER BY start_date DESC';
            break;
        case 'inventory':
            $columns = ['Item Code' => 'item_code', 'Item Name' => 'item_name', 'Category' => 'category', 'Quantity' => 'quantity', 'Unit' => 'unit', 'Reorder Level' => 'reorder_level', 'Unit Cost' => 'unit_cost', 'Stock Status' => 'status'];
            $conditions = ['deleted_at IS NULL']; $parameters = [];
            if ($category !== '') {
                if (!in_array($category, ['Feed', 'Vaccine', 'Medicine', 'Farm Supply', 'Other'], true)) { http_response_code(400); exit('Invalid inventory category.'); }
                $conditions[] = 'category = ?'; $parameters[] = $category;
            }
            if ($status !== '') {
                if (!in_array($status, ['Available', 'Low Stock', 'Out of Stock'], true)) { http_response_code(400); exit('Invalid inventory status.'); }
                $conditions[] = 'status = ?'; $parameters[] = $status;
            }
            $sql = 'SELECT item_code, item_name, category, quantity, unit, reorder_level, unit_cost, status FROM poultry_inventory WHERE ' . implode(' AND ', $conditions) . ' ORDER BY item_name';
            break;
        case 'activities':
            $columns = ['Activity ID' => 'activity_id', 'Flock / Batch' => 'batch_code', 'Activity Type' => 'activity_type', 'Description' => 'description', 'Scheduled Date' => 'scheduled_date', 'Completed Date' => 'completed_date', 'Assigned To' => 'assigned_to', 'Status' => 'status'];
            $conditions = ['a.deleted_at IS NULL', '(a.flock_id IS NULL OR f.deleted_at IS NULL)']; $parameters = [];
            if ($dateFrom !== '') { $conditions[] = 'a.scheduled_date >= ?'; $parameters[] = $dateFrom; }
            if ($dateTo !== '') { $conditions[] = 'a.scheduled_date <= ?'; $parameters[] = $dateTo; }
            if ($flockId !== '') { $conditions[] = 'a.flock_id = ?'; $parameters[] = $validFlockId; }
            if ($status !== '') { if (!in_array($status, ['Scheduled','In Progress','Completed','Missed','Cancelled'], true)) { http_response_code(400); exit('Invalid activity status.'); } $conditions[] = 'a.status = ?'; $parameters[] = $status; }
            if ($activityType !== '') { if (!in_array($activityType, ['Feeding','Cleaning','Vaccination','Medication','Egg Collection','Weighing','Other'], true)) { http_response_code(400); exit('Invalid activity type.'); } $conditions[] = 'a.activity_type = ?'; $parameters[] = $activityType; }
            $sql = 'SELECT a.activity_id, f.batch_code, a.activity_type, a.description, a.scheduled_date, a.completed_date, a.assigned_to, a.status FROM farm_activities a LEFT JOIN flocks f ON f.flock_id = a.flock_id WHERE ' . implode(' AND ', $conditions) . ' ORDER BY a.scheduled_date DESC';
            break;
        case 'production':
            $columns = ['Flock / Batch' => 'batch_code', 'Record Date' => 'record_date', 'Eggs Produced' => 'eggs_produced', 'Good Eggs' => 'good_eggs', 'Damaged Eggs' => 'damaged_eggs', 'Sample Size' => 'sample_size', 'Average Weight' => 'average_weight', 'Mortality Count' => 'mortality_count'];
            $conditions = ['p.deleted_at IS NULL', 'f.deleted_at IS NULL']; $parameters = [];
            if ($dateFrom !== '') { $conditions[] = 'p.record_date >= ?'; $parameters[] = $dateFrom; }
            if ($dateTo !== '') { $conditions[] = 'p.record_date <= ?'; $parameters[] = $dateTo; }
            if ($flockId !== '') { $conditions[] = 'p.flock_id = ?'; $parameters[] = $validFlockId; }
            $sql = 'SELECT f.batch_code, p.record_date, p.eggs_produced, p.good_eggs, p.damaged_eggs, p.sample_size, p.average_weight, p.mortality_count FROM production_records p JOIN flocks f ON f.flock_id = p.flock_id WHERE ' . implode(' AND ', $conditions) . ' ORDER BY p.record_date DESC';
            break;
        case 'health':
            $columns = ['Flock / Batch' => 'batch_code', 'Record Date' => 'record_date', 'Record Type' => 'record_type', 'Description' => 'description', 'Affected Birds' => 'affected_birds', 'Medicine / Vaccine' => 'medicine_or_vaccine', 'Quantity Used' => 'quantity_used', 'Action Taken' => 'action_taken', 'Status' => 'status'];
            $conditions = ['h.deleted_at IS NULL', 'f.deleted_at IS NULL']; $parameters = [];
            if ($dateFrom !== '') { $conditions[] = 'h.record_date >= ?'; $parameters[] = $dateFrom; }
            if ($dateTo !== '') { $conditions[] = 'h.record_date <= ?'; $parameters[] = $dateTo; }
            if ($flockId !== '') { $conditions[] = 'h.flock_id = ?'; $parameters[] = $validFlockId; }
            if ($status !== '') { if (!in_array($status, ['Observed','Under Treatment','Resolved','Monitoring'], true)) { http_response_code(400); exit('Invalid health status.'); } $conditions[] = 'h.status = ?'; $parameters[] = $status; }
            if ($healthType !== '') { if (!in_array($healthType, ['Disease','Vaccination','Treatment','Health Check'], true)) { http_response_code(400); exit('Invalid health record type.'); } $conditions[] = 'h.record_type = ?'; $parameters[] = $healthType; }
            $sql = 'SELECT f.batch_code, h.record_date, h.record_type, h.description, h.affected_birds, h.medicine_or_vaccine, h.quantity_used, h.action_taken, h.status FROM health_records h JOIN flocks f ON f.flock_id = h.flock_id WHERE ' . implode(' AND ', $conditions) . ' ORDER BY h.record_date DESC';
            break;
    }

    try {
        $query = $pdo->prepare($sql);
        $query->execute($parameters);
        $data = $query->fetchAll();
    } catch (PDOException $exception) {
        error_log('PMS report query failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('The report could not be generated.');
    }
    if ($data === []) {
        http_response_code(404);
        exit('No records match the selected report filters.');
    }

    class PmsReportPdf extends FPDF
    {
        public string $reportTitle = '';
        public string $filtersText = '';
        public function Header(): void
        {
            $this->SetFont('Arial', 'B', 14);
            $this->Cell(0, 8, 'YIELD - POULTRY MANAGEMENT SYSTEM', 0, 1, 'C');
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(0, 7, $this->reportTitle, 0, 1, 'C');
            $this->SetFont('Arial', '', 8);
            $this->Cell(0, 5, 'Generated: ' . date('Y-m-d H:i:s') . '    Generated by: Poultry Admin', 0, 1, 'C');
            if ($this->filtersText !== '') { $this->MultiCell(0, 5, 'Filters: ' . $this->filtersText, 0, 'L'); }
            $this->Ln(3);
        }
        public function Footer(): void
        {
            $this->SetY(-12);
            $this->SetFont('Arial', '', 8);
            $this->Cell(0, 8, 'Page ' . $this->PageNo() . '    Generated by: Poultry Admin', 0, 0, 'C');
        }
    }

    $pdf = new PmsReportPdf('L', 'mm', 'A4');
    $pdf->reportTitle = $reportTypes[$type];
    $appliedFilters = [];
    if ($dateFrom !== '') { $appliedFilters[] = 'From ' . $dateFrom; }
    if ($dateTo !== '') { $appliedFilters[] = 'To ' . $dateTo; }
    if ($flockId !== '') { $appliedFilters[] = 'Flock ID ' . $flockId; }
    foreach (['status' => $status, 'category' => $category, 'activity type' => $activityType, 'health type' => $healthType] as $label => $value) { if ($value !== '') { $appliedFilters[] = $label . ': ' . $value; } }
    $pdf->filtersText = implode('; ', $appliedFilters);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage();
    $width = 277 / max(1, count($columns));
    $pdf->SetFillColor(37, 83, 61);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    foreach ($columns as $label => $_column) { $pdf->Cell($width, 8, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $label), 1, 0, 'C', true); }
    $pdf->Ln();
    $pdf->SetTextColor(36, 51, 45);
    $pdf->SetFont('Arial', '', 8);
    foreach ($data as $row) {
        foreach ($columns as $column) {
            $value = (string) ($row[$column] ?? '');
            $pdf->Cell($width, 7, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', mb_strimwidth($value, 0, 18, '...')), 1, 0, 'L');
        }
        $pdf->Ln();
    }
    $pdf->Output('D', $type . '-report-' . date('Ymd-His') . '.pdf');
    exit;
}

require __DIR__ . '/../../shared/includes/header.php';
?>
<div class="page-heading"><div class="eyebrow">SYSTEM</div><h1>PMS Reports</h1><p>Generate a PDF report from active Poultry Management System records.</p></div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>
<section class="content-panel">
    <div class="panel-heading"><h2 class="h5 mb-0">Select report and filters</h2></div>
    <div class="panel-body">
        <form method="get" action="index.php" class="row g-3 align-items-end">
            <div class="col-md-4"><label class="form-label" for="type">Report</label><select class="form-select" id="type" name="type" required><option value="">Select report</option><?php foreach ($reportTypes as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label" for="date_from">Date from</label><input class="form-control" type="date" id="date_from" name="date_from"></div>
            <div class="col-md-2"><label class="form-label" for="date_to">Date to</label><input class="form-control" type="date" id="date_to" name="date_to"></div>
            <div class="col-md-2"><label class="form-label" for="flock_id">Flock / batch</label><select class="form-select" id="flock_id" name="flock_id"><option value="">All flocks</option><?php foreach ($flocks as $flock): ?><option value="<?= (int) $flock['flock_id'] ?>"><?= h($flock['batch_code']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label" for="status">Status filter</label><select class="form-select" id="status" name="status"><option value="">Any status</option><option>Active</option><option>Completed</option><option>Sold</option><option>Terminated</option><option>Available</option><option>Low Stock</option><option>Out of Stock</option><option>Scheduled</option><option>In Progress</option><option>Missed</option><option>Cancelled</option><option>Observed</option><option>Under Treatment</option><option>Resolved</option><option>Monitoring</option></select></div>
            <div class="col-md-3"><label class="form-label" for="category">Inventory category</label><select class="form-select" id="category" name="category"><option value="">All categories</option><option>Feed</option><option>Vaccine</option><option>Medicine</option><option>Farm Supply</option><option>Other</option></select></div>
            <div class="col-md-3"><label class="form-label" for="activity_type">Activity type</label><select class="form-select" id="activity_type" name="activity_type"><option value="">All types</option><option>Feeding</option><option>Cleaning</option><option>Vaccination</option><option>Medication</option><option>Egg Collection</option><option>Weighing</option><option>Other</option></select></div>
            <div class="col-md-3"><label class="form-label" for="health_type">Health record type</label><select class="form-select" id="health_type" name="health_type"><option value="">All types</option><option>Disease</option><option>Vaccination</option><option>Treatment</option><option>Health Check</option></select></div>
            <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Generate PDF</button></div>
        </form>
        <p class="small text-secondary mt-3 mb-0">Only supported report types are selectable. Date, flock, and report-specific filters are optional.</p>
    </div>
</section>
<?php require __DIR__ . '/../../shared/includes/footer.php'; ?>
