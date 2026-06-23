SET NAMES utf8mb4;
SET foreign_key_checks = 0;

ALTER TABLE leads
  ADD COLUMN IF NOT EXISTS playbook_key VARCHAR(80) NULL AFTER next_action_type,
  ADD COLUMN IF NOT EXISTS playbook_step VARCHAR(80) NULL AFTER playbook_key,
  ADD COLUMN IF NOT EXISTS playbook_last_run_at TIMESTAMP NULL AFTER playbook_step,
  ADD COLUMN IF NOT EXISTS last_automation_contact_at TIMESTAMP NULL AFTER playbook_last_run_at;

ALTER TABLE interviews
  ADD COLUMN IF NOT EXISTS no_show_followup_sent_at TIMESTAMP NULL AFTER reminder_2h_sent_at;

CREATE TABLE IF NOT EXISTS automation_runs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  lead_id INT NULL,
  interview_id BIGINT NULL,
  automation_key VARCHAR(80) NOT NULL,
  status ENUM('sent','skipped','failed') NOT NULL DEFAULT 'sent',
  payload JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_automation_lookup (empresa_id, automation_key, created_at),
  INDEX idx_automation_lead (lead_id, created_at),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (interview_id) REFERENCES interviews(id) ON DELETE CASCADE
);

SET foreign_key_checks = 1;
