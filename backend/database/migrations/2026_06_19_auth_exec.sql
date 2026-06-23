SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS app_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL,
  password_sha256 CHAR(64) NOT NULL,
  activo TINYINT DEFAULT 1,
  is_superadmin TINYINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_app_user_email (email)
);

CREATE TABLE IF NOT EXISTS app_user_companies (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  empresa_id INT NOT NULL,
  recruiter_id INT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'viewer',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_company (user_id, empresa_id),
  FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS auth_sessions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  session_token CHAR(64) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  last_seen_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_session_token (session_token),
  INDEX idx_session_expiry (expires_at),
  FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE
);

INSERT INTO app_users (nombre, email, password_sha256, activo, is_superadmin)
SELECT 'Administrador HDreams', 'admin@hdreams.local', '72e224f1512f99ccf28cfbd3c6f01d0ef6ee4d917821e992914f9806839aa014', 1, 1
WHERE NOT EXISTS (
  SELECT 1 FROM app_users WHERE email = 'admin@hdreams.local'
);

INSERT INTO app_users (nombre, email, password_sha256, activo, is_superadmin)
SELECT 'Operaciones Heavenly Dreams', 'operaciones@heavenlydreams.mx', '72e224f1512f99ccf28cfbd3c6f01d0ef6ee4d917821e992914f9806839aa014', 1, 0
WHERE NOT EXISTS (
  SELECT 1 FROM app_users WHERE email = 'operaciones@heavenlydreams.mx'
);

INSERT INTO app_user_companies (user_id, empresa_id, recruiter_id, role)
SELECT u.id, 1, r.id, 'manager'
FROM app_users u
JOIN recruiters r ON r.empresa_id = 1 AND r.nombre = 'Gissell Arenas'
WHERE u.email = 'operaciones@heavenlydreams.mx'
  AND NOT EXISTS (
    SELECT 1 FROM app_user_companies auc
    WHERE auc.user_id = u.id AND auc.empresa_id = 1
  );

SET foreign_key_checks = 1;
