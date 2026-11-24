<?php
require_once __DIR__ . '/../config/db.php';

try {
    echo "Running migration for Transaction Correction Management...\n";

    // 1. Check or add columns in pos_transaction
    $columns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'pos_transaction'")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('correction_status', $columns)) {
        $pdo->exec("ALTER TABLE pos_transaction ADD COLUMN correction_status VARCHAR(50) DEFAULT 'none'");
        echo "Added correction_status column to pos_transaction.\n";
    } else {
        echo "correction_status column already exists.\n";
    }

    if (!in_array('is_corrected', $columns)) {
        $pdo->exec("ALTER TABLE pos_transaction ADD COLUMN is_corrected BOOLEAN DEFAULT FALSE");
        echo "Added is_corrected column to pos_transaction.\n";
    }

    // 2. Create pos_correction_requests table
    $sql_requests = "
    CREATE TABLE IF NOT EXISTS pos_correction_requests (
        id SERIAL PRIMARY KEY,
        transaction_id INT NOT NULL REFERENCES pos_transaction(id) ON DELETE CASCADE,
        cashier_name VARCHAR(100) NOT NULL,
        cashier_id INT,
        correction_type VARCHAR(100) NOT NULL,
        reason TEXT NOT NULL,
        evidence_path VARCHAR(255),
        original_total NUMERIC(12,2) NOT NULL,
        proposed_total NUMERIC(12,2) NOT NULL,
        payment_difference NUMERIC(12,2) NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Pending Review',
        owner_id INT,
        owner_name VARCHAR(100),
        rejection_reason TEXT,
        payment_settled BOOLEAN DEFAULT FALSE,
        settlement_type VARCHAR(50) DEFAULT 'none',
        payment_method VARCHAR(50),
        payment_reference VARCHAR(100),
        amount_settled NUMERIC(12,2) DEFAULT 0,
        settled_by VARCHAR(100),
        settled_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP,
        completed_at TIMESTAMP
    );";
    $pdo->exec($sql_requests);
    echo "Table pos_correction_requests ensured.\n";

    // 3. Create pos_correction_items table
    $sql_items = "
    CREATE TABLE IF NOT EXISTS pos_correction_items (
        id SERIAL PRIMARY KEY,
        request_id INT NOT NULL REFERENCES pos_correction_requests(id) ON DELETE CASCADE,
        item_type VARCHAR(20) NOT NULL, -- 'original' or 'corrected'
        product_id INT,
        variant_id INT,
        product_name VARCHAR(255) NOT NULL,
        price NUMERIC(10,2) NOT NULL,
        qty INT NOT NULL,
        subtotal NUMERIC(12,2) NOT NULL
    );";
    $pdo->exec($sql_items);
    echo "Table pos_correction_items ensured.\n";

    // Create index on transaction_id and status for fast lookups
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pcr_trans_id ON pos_correction_requests(transaction_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pcr_status ON pos_correction_requests(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pci_req_id ON pos_correction_items(request_id)");

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
