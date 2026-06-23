SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS voice_notes (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  lead_id INT NOT NULL,
  recruiter_id INT NULL,
  file_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NULL,
  transcript LONGTEXT NULL,
  status ENUM('uploaded','transcribed','failed') NOT NULL DEFAULT 'uploaded',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  transcribed_at TIMESTAMP NULL,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE SET NULL,
  INDEX idx_voice_lead (lead_id, created_at DESC)
);

CREATE TABLE IF NOT EXISTS knowledge_documents (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  site_id INT NULL,
  title VARCHAR(180) NOT NULL,
  source_filename VARCHAR(180) NOT NULL,
  mime_type VARCHAR(120) NULL,
  text_content LONGTEXT NULL,
  status ENUM('uploaded','indexed','failed') NOT NULL DEFAULT 'uploaded',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (site_id) REFERENCES company_sites(id) ON DELETE SET NULL,
  INDEX idx_knowledge_company (empresa_id, created_at DESC)
);

CREATE TABLE IF NOT EXISTS knowledge_chunks (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT NOT NULL,
  empresa_id INT NOT NULL,
  chunk_index INT NOT NULL,
  content TEXT NOT NULL,
  token_count INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (document_id) REFERENCES knowledge_documents(id) ON DELETE CASCADE,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  INDEX idx_knowledge_chunks (empresa_id, document_id, chunk_index)
);

CREATE TABLE IF NOT EXISTS recruiter_playbooks (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  recruiter_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  trigger_stage VARCHAR(60) NOT NULL,
  message_template TEXT NOT NULL,
  active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE CASCADE,
  INDEX idx_recruiter_playbooks (empresa_id, recruiter_id, active)
);

INSERT INTO recruiter_playbooks (empresa_id, recruiter_id, name, trigger_stage, message_template, active)
SELECT r.empresa_id, r.id, 'Reactivación caliente', 'contactado',
       'Hola {{nombre}}, retomo tu proceso para {{vacante}}. Si sigues disponible hoy, puedo ayudarte a avanzar al siguiente paso.',
       1
FROM recruiters r
WHERE NOT EXISTS (
  SELECT 1 FROM recruiter_playbooks rp WHERE rp.recruiter_id = r.id AND rp.name = 'Reactivación caliente'
);

SET foreign_key_checks = 1;
