<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/includes/bootstrap.php';
require_authentication();

$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($documentId === false || $documentId < 1) {
    http_response_code(404);
    exit('Document not found.');
}

try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('AIS document download database connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('The document could not be reached because of a database error.');
}

// Neither the document nor its parent transaction may be soft-deleted — a
// deleted record's document must not remain downloadable while it sits in
// the Recycle Bin. Restoring the record makes it downloadable again.
$statement = $pdo->prepare(
    'SELECT d.file_name, d.file_path FROM transaction_documents d
     JOIN financial_transactions t ON t.transaction_id = d.transaction_id
     WHERE d.document_id = ? AND d.deleted_at IS NULL AND t.deleted_at IS NULL'
);
$statement->execute([$documentId]);
$document = $statement->fetch();
if (!$document || !is_string($document['file_path']) || $document['file_path'] === '') {
    http_response_code(404);
    exit('Document not found.');
}

$absolutePath = ais_absolute_path($document['file_path']);
if (!is_file($absolutePath)) {
    http_response_code(404);
    exit('Document not found.');
}

$mimeTypes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
$contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
$downloadName = preg_replace('/[\r\n"]/', '_', (string) $document['file_name']);

header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . (string) filesize($absolutePath));
header('X-Content-Type-Options: nosniff');
readfile($absolutePath);
