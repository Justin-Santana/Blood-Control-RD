-- Blood Control RD · MariaDB 10.4+
-- Import with: mariadb -u root -p < database/schema.sql
CREATE DATABASE IF NOT EXISTS blood_control_rd
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE blood_control_rd;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  username VARCHAR(40) NOT NULL,
  display_name_mode ENUM('username','real') NOT NULL DEFAULT 'username',
  profile_photo MEDIUMBLOB NULL,
  profile_photo_mime VARCHAR(40) NULL,
  theme ENUM('light','dark') NOT NULL DEFAULT 'light',
  compact_mode TINYINT(1) NOT NULL DEFAULT 0,
  email VARCHAR(254) NOT NULL UNIQUE,
  phone_country_code VARCHAR(8) NOT NULL,
  phone VARCHAR(24) NOT NULL,
  identity_document CHAR(11) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB;

ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(40) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS display_name_mode ENUM('username','real') NOT NULL DEFAULT 'username';
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo MEDIUMBLOB NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo_mime VARCHAR(40) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS theme ENUM('light','dark') NOT NULL DEFAULT 'light';
ALTER TABLE users ADD COLUMN IF NOT EXISTS compact_mode TINYINT(1) NOT NULL DEFAULT 0;
UPDATE users SET username=CONCAT('usuario',id) WHERE username IS NULL OR username='';
ALTER TABLE users MODIFY username VARCHAR(40) NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_username ON users(username);

CREATE TABLE IF NOT EXISTS blood_banks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  province VARCHAR(80) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 8000.00,
  UNIQUE KEY uq_bank_name_province (name, province)
) ENGINE=InnoDB;
ALTER TABLE blood_banks ADD COLUMN IF NOT EXISTS unit_price DECIMAL(10,2) NOT NULL DEFAULT 8000.00;

CREATE TABLE IF NOT EXISTS donors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  identity_document CHAR(11) NOT NULL,
  phone_country_code VARCHAR(8) NOT NULL,
  phone VARCHAR(24) NOT NULL,
  blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
  province VARCHAR(80) NOT NULL,
  bank_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_donor_owner_document (owner_id, identity_document),
  CONSTRAINT fk_donor_owner FOREIGN KEY (owner_id) REFERENCES users(id),
  CONSTRAINT fk_donor_bank FOREIGN KEY (bank_id) REFERENCES blood_banks(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blood_deposits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  donor_id BIGINT UNSIGNED NULL,
  donor_name VARCHAR(160) NOT NULL,
  donor_document CHAR(11) NOT NULL,
  blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
  component ENUM('whole_blood','red_cells','platelets','plasma') NOT NULL DEFAULT 'whole_blood',
  quantity SMALLINT UNSIGNED NOT NULL,
  available SMALLINT UNSIGNED NOT NULL,
  bank_id BIGINT UNSIGNED NULL,
  collected_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  KEY ix_deposit_owner_type_expiry (owner_id, blood_type, expires_at),
  CONSTRAINT fk_deposit_owner FOREIGN KEY (owner_id) REFERENCES users(id),
  CONSTRAINT fk_deposit_donor FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE SET NULL,
  CONSTRAINT fk_deposit_bank FOREIGN KEY (bank_id) REFERENCES blood_banks(id) ON DELETE SET NULL,
  CONSTRAINT chk_deposit_quantity CHECK (quantity > 0 AND available <= quantity)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  deposit_id BIGINT UNSIGNED NULL,
  patient_id BIGINT UNSIGNED NULL,
  movement ENUM('entry','exit','expiry','adjustment') NOT NULL,
  blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL,
  reason VARCHAR(255) NULL,
  patient_authorized_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_movement_owner_date (owner_id, created_at),
  CONSTRAINT fk_movement_owner FOREIGN KEY (owner_id) REFERENCES users(id),
  CONSTRAINT fk_movement_deposit FOREIGN KEY (deposit_id) REFERENCES blood_deposits(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  identity_document CHAR(11) NOT NULL,
  phone_country_code VARCHAR(8) NOT NULL DEFAULT '+1',
  phone VARCHAR(24) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_patient_owner_document (owner_id, identity_document),
  CONSTRAINT fk_patient_owner FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;

ALTER TABLE inventory_movements ADD COLUMN IF NOT EXISTS patient_id BIGINT UNSIGNED NULL AFTER deposit_id;
ALTER TABLE inventory_movements ADD COLUMN IF NOT EXISTS patient_authorized_at DATETIME NULL AFTER reason;
SET @bcrd_movement_patient_fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'inventory_movements'
    AND CONSTRAINT_NAME = 'fk_movement_patient'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @bcrd_movement_patient_fk_sql = IF(
  @bcrd_movement_patient_fk_exists = 0,
  'ALTER TABLE inventory_movements ADD CONSTRAINT fk_movement_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE bcrd_movement_patient_fk_stmt FROM @bcrd_movement_patient_fk_sql;
EXECUTE bcrd_movement_patient_fk_stmt;
DEALLOCATE PREPARE bcrd_movement_patient_fk_stmt;

CREATE TABLE IF NOT EXISTS blood_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  patient_id BIGINT UNSIGNED NULL,
  hospital VARCHAR(160) NOT NULL,
  province VARCHAR(80) NULL,
  blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
  quantity SMALLINT UNSIGNED NOT NULL,
  priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status ENUM('pending','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  reason VARCHAR(500) NULL,
  patient_authorized_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_request_owner_status (owner_id, status, created_at),
  CONSTRAINT fk_request_owner FOREIGN KEY (owner_id) REFERENCES users(id),
  CONSTRAINT fk_request_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
ALTER TABLE blood_requests ADD COLUMN IF NOT EXISTS province VARCHAR(80) NULL;
ALTER TABLE blood_requests ADD COLUMN IF NOT EXISTS patient_id BIGINT UNSIGNED NULL AFTER owner_id;
ALTER TABLE blood_requests ADD COLUMN IF NOT EXISTS patient_authorized_at DATETIME NULL AFTER reason;

SET @bcrd_patient_fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'blood_requests'
    AND CONSTRAINT_NAME = 'fk_request_patient'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @bcrd_patient_fk_sql = IF(
  @bcrd_patient_fk_exists = 0,
  'ALTER TABLE blood_requests ADD CONSTRAINT fk_request_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE bcrd_patient_fk_stmt FROM @bcrd_patient_fk_sql;
EXECUTE bcrd_patient_fk_stmt;
DEALLOCATE PREPARE bcrd_patient_fk_stmt;

CREATE TABLE IF NOT EXISTS sales (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  invoice_number VARCHAR(40) NOT NULL UNIQUE,
  currency CHAR(3) NOT NULL DEFAULT 'DOP',
  subtotal DECIMAL(12,2) NOT NULL,
  status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  payment_method VARCHAR(32) NULL,
  buyer_name VARCHAR(160) NULL,
  buyer_document CHAR(11) NULL,
  buyer_email VARCHAR(254) NULL,
  buyer_phone VARCHAR(32) NULL,
  province VARCHAR(80) NULL,
  bank_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sale_owner_date (owner_id, created_at),
  CONSTRAINT fk_sale_owner FOREIGN KEY (owner_id) REFERENCES users(id),
  CONSTRAINT fk_sale_bank FOREIGN KEY (bank_id) REFERENCES blood_banks(id) ON DELETE SET NULL
) ENGINE=InnoDB;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS buyer_name VARCHAR(160) NULL;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS buyer_document CHAR(11) NULL;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS buyer_email VARCHAR(254) NULL;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS buyer_phone VARCHAR(32) NULL;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS province VARCHAR(80) NULL;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS bank_id BIGINT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS sale_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sale_id BIGINT UNSIGNED NOT NULL,
  blood_type ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
  units SMALLINT UNSIGNED NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_sale_item_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_owner_date (owner_id, created_at),
  CONSTRAINT fk_audit_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO blood_banks (name, province) VALUES
('Banco de Sangre Hospital Plaza de la Salud','Distrito Nacional'),
('Banco de Sangre Cruz Roja Dominicana','Distrito Nacional'),
('Banco de Sangre Hospital Salvador B. Gautier','Distrito Nacional'),
('Banco de Sangre Hospital José María Cabral y Báez','Santiago'),
('Banco de Sangre Hospital Regional Universitario Jaime Mota','Barahona');

-- Never store card numbers, CVV, Cash App credentials, or PayPal passwords.
