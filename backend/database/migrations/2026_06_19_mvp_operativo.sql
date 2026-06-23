SET NAMES utf8mb4;
SET foreign_key_checks = 0;

ALTER TABLE leads
  ADD COLUMN IF NOT EXISTS assigned_recruiter_id INT NULL AFTER responsable_id,
  ADD COLUMN IF NOT EXISTS current_stage VARCHAR(50) NOT NULL DEFAULT 'nuevo_lead' AFTER assigned_recruiter_id,
  ADD COLUMN IF NOT EXISTS source_detail VARCHAR(100) NULL AFTER fuente,
  ADD COLUMN IF NOT EXISTS last_inbound_at TIMESTAMP NULL AFTER ultima_interaccion,
  ADD COLUMN IF NOT EXISTS last_outbound_at TIMESTAMP NULL AFTER last_inbound_at,
  ADD COLUMN IF NOT EXISTS next_action_at TIMESTAMP NULL AFTER last_outbound_at,
  ADD COLUMN IF NOT EXISTS next_action_type VARCHAR(50) NULL AFTER next_action_at,
  ADD COLUMN IF NOT EXISTS screening_status ENUM('pendiente','aprobado','rechazado') DEFAULT 'pendiente' AFTER next_action_type,
  ADD COLUMN IF NOT EXISTS interview_status ENUM('sin_agendar','agendada','confirmada','reagendada','realizada','no_show') DEFAULT 'sin_agendar' AFTER screening_status,
  ADD INDEX idx_stage_priority (empresa_id, current_stage, prioridad),
  ADD INDEX idx_recruiter_stage (assigned_recruiter_id, current_stage),
  ADD INDEX idx_next_action (next_action_at);

CREATE TABLE IF NOT EXISTS recruiters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  email VARCHAR(120) NULL,
  telefono VARCHAR(20) NULL,
  activo TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS lead_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  empresa_id INT NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  event_label VARCHAR(100) NOT NULL,
  payload JSON NULL,
  actor_type ENUM('system','bot','recruiter','candidate') NOT NULL DEFAULT 'system',
  actor_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  INDEX idx_lead_created (lead_id, created_at DESC),
  INDEX idx_empresa_type (empresa_id, event_type)
);

CREATE TABLE IF NOT EXISTS lead_notes (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  recruiter_id INT NOT NULL,
  note TEXT NOT NULL,
  is_pinned TINYINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE CASCADE,
  INDEX idx_lead_notes (lead_id, created_at DESC)
);

CREATE TABLE IF NOT EXISTS interviews (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  empresa_id INT NOT NULL,
  recruiter_id INT NULL,
  office_location VARCHAR(150) NOT NULL,
  interview_date DATE NOT NULL,
  interview_time TIME NOT NULL,
  status ENUM('agendada','confirmada','reagendada','realizada','cancelada','no_show') DEFAULT 'agendada',
  confirmation_channel ENUM('whatsapp','messenger','instagram','facebook','telegram','manual') DEFAULT 'manual',
  reminder_24h_sent_at TIMESTAMP NULL,
  reminder_2h_sent_at TIMESTAMP NULL,
  attended_at TIMESTAMP NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE SET NULL,
  INDEX idx_status_date (status, interview_date),
  INDEX idx_lead_interviews (lead_id, created_at DESC)
);

CREATE TABLE IF NOT EXISTS interview_slots (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  recruiter_id INT NULL,
  slot_date DATE NOT NULL,
  slot_time TIME NOT NULL,
  capacity INT NOT NULL DEFAULT 1,
  reserved INT NOT NULL DEFAULT 0,
  active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_slot_capacity (empresa_id, recruiter_id, slot_date, slot_time),
  INDEX idx_slot_lookup (slot_date, slot_time, active)
);

CREATE TABLE IF NOT EXISTS lead_assignments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  recruiter_id INT NOT NULL,
  assigned_by INT NULL,
  reason VARCHAR(100) NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  released_at TIMESTAMP NULL,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE CASCADE,
  INDEX idx_lead_assignment (lead_id, assigned_at DESC),
  INDEX idx_recruiter_assignment (recruiter_id, assigned_at DESC)
);

INSERT INTO recruiters (empresa_id, nombre, email, telefono)
SELECT 1, 'Gissell Arenas', 'gissell@heavenlydreams.mx', '5210000000000'
WHERE NOT EXISTS (
  SELECT 1 FROM recruiters WHERE empresa_id = 1 AND nombre = 'Gissell Arenas'
);

UPDATE leads
SET current_stage = CASE
  WHEN estado = 'nuevo' THEN 'nuevo_lead'
  WHEN estado = 'contactado' THEN 'contactado'
  WHEN estado = 'calificado' THEN 'calificado'
  WHEN estado = 'entrevista_agendada' THEN 'entrevista_agendada'
  WHEN estado = 'entrevista_realizada' THEN 'confirmado'
  WHEN estado = 'contratado' THEN 'contratado'
  WHEN estado = 'rechazado' THEN 'rechazado'
  WHEN estado = 'no_interesado' THEN 'rechazado'
  ELSE 'nuevo_lead'
END
WHERE current_stage IS NULL OR current_stage = '' OR current_stage = 'nuevo_lead';

SET foreign_key_checks = 1;
