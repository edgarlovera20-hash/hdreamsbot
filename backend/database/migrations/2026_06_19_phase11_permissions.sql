SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS app_user_company_permissions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_company_id BIGINT NOT NULL,
  permission_key VARCHAR(80) NOT NULL,
  effect ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_company_permission (user_company_id, permission_key),
  INDEX idx_permission_lookup (permission_key, effect),
  FOREIGN KEY (user_company_id) REFERENCES app_user_companies(id) ON DELETE CASCADE
);

SET foreign_key_checks = 1;
