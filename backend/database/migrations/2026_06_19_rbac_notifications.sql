SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  empresa_id INT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id VARCHAR(80) NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_company (empresa_id, created_at),
  INDEX idx_audit_user (user_id, created_at)
);

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  user_id INT NULL,
  recruiter_id INT NULL,
  type VARCHAR(60) NOT NULL,
  title VARCHAR(160) NOT NULL,
  message TEXT NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  entity_type VARCHAR(60) NULL,
  entity_id BIGINT NULL,
  action_url VARCHAR(255) NULL,
  read_at TIMESTAMP NULL,
  delivered_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_lookup (empresa_id, user_id, recruiter_id, read_at, created_at),
  INDEX idx_notifications_delivery (delivered_at, created_at),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE SET NULL
);

UPDATE app_user_companies
SET role = 'manager'
WHERE empresa_id = 1 AND role = 'viewer';

SET foreign_key_checks = 1;
