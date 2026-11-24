<?php
require_once __DIR__ . '/../config/db.php';

try {
    echo "Running migration for 24-Hour Deadline and Late Correction Requests...\n";

    $columns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'pos_correction_requests'")->fetchAll(PDO::FETCH_COLUMN);

    $new_cols = [
        'is_late_request' => "ALTER TABLE pos_correction_requests ADD COLUMN is_late_request BOOLEAN DEFAULT FALSE",
        'correction_deadline' => "ALTER TABLE pos_correction_requests ADD COLUMN correction_deadline TIMESTAMP",
        'late_reason' => "ALTER TABLE pos_correction_requests ADD COLUMN late_reason TEXT",
        'late_reviewed_by' => "ALTER TABLE pos_correction_requests ADD COLUMN late_reviewed_by VARCHAR(100)",
        'late_reviewed_at' => "ALTER TABLE pos_correction_requests ADD COLUMN late_reviewed_at TIMESTAMP",
        'late_review_remarks' => "ALTER TABLE pos_correction_requests ADD COLUMN late_review_remarks TEXT",
        'owner_remarks' => "ALTER TABLE pos_correction_requests ADD COLUMN owner_remarks TEXT",
        'other_details' => "ALTER TABLE pos_correction_requests ADD COLUMN other_details TEXT"
    ];

    foreach ($new_cols as $col => $sql) {
        if (!in_array($col, $columns)) {
            $pdo->exec($sql);
            echo "Added column '$col' to pos_correction_requests.\n";
        } else {
            echo "Column '$col' already exists.\n";
        }
    }

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pcr_is_late ON pos_correction_requests(is_late_request)");

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
