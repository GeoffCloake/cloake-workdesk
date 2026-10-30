-- Cloake Manager M2 staging foundation (MariaDB 10.6+). No production migration.
CREATE TABLE IF NOT EXISTS clients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_system VARCHAR(32) NOT NULL, source_id VARCHAR(64) NOT NULL,
 name VARCHAR(255) NOT NULL, status VARCHAR(60), xero_contact_id VARCHAR(64), legacy_record JSON,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_client_source(source_system,source_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS contacts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id BIGINT UNSIGNED, source_system VARCHAR(32) NOT NULL, source_id VARCHAR(64) NOT NULL,
 display_name VARCHAR(255), email VARCHAR(255), phone VARCHAR(64), legacy_record JSON,
 UNIQUE KEY uq_contact_source(source_system,source_id), FOREIGN KEY(client_id) REFERENCES clients(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS enquiries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id BIGINT UNSIGNED, title VARCHAR(255) NOT NULL, details TEXT,
 required_by DATE, priority VARCHAR(20) DEFAULT 'normal', next_action TEXT, next_action_due DATE,
 waiting_for VARCHAR(80), status VARCHAR(40) DEFAULT 'enquiry', legacy_source_id VARCHAR(64),
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(client_id) REFERENCES clients(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS tasks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id BIGINT UNSIGNED NOT NULL, enquiry_id BIGINT UNSIGNED,
 source_system VARCHAR(32), source_id VARCHAR(64), title VARCHAR(255) NOT NULL, instructions TEXT, notes TEXT,
 required_by DATETIME, received_at DATETIME, completed_at DATETIME, priority VARCHAR(25), status VARCHAR(40),
 pricing ENUM('hourly','set','web_service') NULL, chargeable VARCHAR(24), estimate_minutes INT UNSIGNED,
 agreed_fee_ex_gst DECIMAL(12,2), legacy_billing_amount_ex_gst DECIMAL(12,2),
 billing_advised TINYINT(1), invoice_number VARCHAR(64), invoice_reference VARCHAR(255),
 purchase_order_no VARCHAR(80), job_number VARCHAR(100), legacy_record JSON,
 UNIQUE KEY uq_task_source(source_system,source_id), FOREIGN KEY(client_id) REFERENCES clients(id),
 FOREIGN KEY(enquiry_id) REFERENCES enquiries(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS work_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id BIGINT UNSIGNED, task_id BIGINT UNSIGNED,
 source_system VARCHAR(32), source_id VARCHAR(64), staff_key VARCHAR(100), details TEXT NOT NULL,
 started_at DATETIME NULL, ended_at DATETIME NULL, duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
 billable_seconds INT UNSIGNED NOT NULL DEFAULT 0, billability ENUM('billable','non_billable','included_in_fee','review') DEFAULT 'review',
 rate_ex_gst DECIMAL(12,2) NULL, billed_amount_ex_gst DECIMAL(12,2) NULL, billable_approved_at DATETIME NULL,
 legacy_record JSON, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_work_source(source_system,source_id), FOREIGN KEY(client_id) REFERENCES clients(id), FOREIGN KEY(task_id) REFERENCES tasks(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS account_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(90) UNIQUE NOT NULL,
 description TEXT, pricing_mode ENUM('hourly','set','web_service') DEFAULT 'hourly',
 unit_price_ex_gst DECIMAL(12,2), xero_item_code VARCHAR(90), tax_code VARCHAR(40), active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS breakdown_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, enquiry_id BIGINT UNSIGNED, task_id BIGINT UNSIGNED,
 account_item_id BIGINT UNSIGNED, description TEXT, quantity DECIMAL(12,3) DEFAULT 1,
 unit_price_ex_gst DECIMAL(12,2), pricing_mode ENUM('hourly','set','web_service') NOT NULL,
 status VARCHAR(24) DEFAULT 'proposed', FOREIGN KEY(enquiry_id) REFERENCES enquiries(id),
 FOREIGN KEY(task_id) REFERENCES tasks(id), FOREIGN KEY(account_item_id) REFERENCES account_items(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS financial_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id BIGINT UNSIGNED NOT NULL, enquiry_id BIGINT UNSIGNED,
 kind ENUM('quote','invoice') NOT NULL, local_status VARCHAR(32) NOT NULL DEFAULT 'draft',
 xero_id VARCHAR(80), xero_status VARCHAR(40), xero_number VARCHAR(80), revision INT UNSIGNED DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_xero_id(xero_id),
 FOREIGN KEY(client_id) REFERENCES clients(id), FOREIGN KEY(enquiry_id) REFERENCES enquiries(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS activity_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id BIGINT UNSIGNED, enquiry_id BIGINT UNSIGNED,
 task_id BIGINT UNSIGNED, work_log_id BIGINT UNSIGNED, event_kind VARCHAR(50) NOT NULL,
 event_at DATETIME NOT NULL, summary TEXT NOT NULL, actor VARCHAR(100), payload JSON,
 FOREIGN KEY(client_id) REFERENCES clients(id), FOREIGN KEY(enquiry_id) REFERENCES enquiries(id),
 FOREIGN KEY(task_id) REFERENCES tasks(id), FOREIGN KEY(work_log_id) REFERENCES work_log(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS import_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_name VARCHAR(120), sha256 CHAR(64) NOT NULL,
 row_count INT UNSIGNED, imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_import_hash(source_name,sha256)
) ENGINE=InnoDB;
