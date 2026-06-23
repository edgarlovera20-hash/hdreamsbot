SET NAMES utf8mb4;
SET foreign_key_checks = 0;

ALTER TABLE leads
  ADD COLUMN IF NOT EXISTS conversation_score DECIMAL(5,2) NULL AFTER score_ia_contratacion,
  ADD COLUMN IF NOT EXISTS ai_recommended_action VARCHAR(120) NULL AFTER conversation_score,
  ADD COLUMN IF NOT EXISTS ai_summary TEXT NULL AFTER ai_recommended_action,
  ADD COLUMN IF NOT EXISTS ai_last_analysis_at TIMESTAMP NULL AFTER ai_summary;

CREATE TABLE IF NOT EXISTS ai_copilot_runs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  lead_id INT NOT NULL,
  recruiter_id INT NULL,
  run_type VARCHAR(60) NOT NULL,
  payload JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_copilot_lookup (empresa_id, lead_id, run_type, created_at),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE SET NULL
);

SET foreign_key_checks = 1;
