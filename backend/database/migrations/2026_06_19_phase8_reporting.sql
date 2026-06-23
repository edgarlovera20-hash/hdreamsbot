SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS company_sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  ciudad VARCHAR(120) NULL,
  direccion VARCHAR(180) NULL,
  activo TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
);

INSERT INTO company_sites (empresa_id, nombre, ciudad, direccion, activo)
SELECT 1, 'HQ Culhuacan', 'Ciudad de Mexico', 'Av. Tlahuac 3632 Int. A301, Col. Culhuacan, Iztapalapa', 1
WHERE NOT EXISTS (
  SELECT 1 FROM company_sites WHERE empresa_id = 1 AND nombre = 'HQ Culhuacan'
);

ALTER TABLE recruiters
  ADD COLUMN IF NOT EXISTS site_id INT NULL AFTER empresa_id;

ALTER TABLE leads
  ADD COLUMN IF NOT EXISTS site_id INT NULL AFTER empresa_id;

ALTER TABLE interviews
  ADD COLUMN IF NOT EXISTS site_id INT NULL AFTER empresa_id;

ALTER TABLE canales
  ADD COLUMN IF NOT EXISTS site_id INT NULL AFTER empresa_id;

UPDATE recruiters r
JOIN company_sites s ON s.empresa_id = r.empresa_id
SET r.site_id = s.id
WHERE r.site_id IS NULL;

UPDATE leads l
JOIN company_sites s ON s.empresa_id = l.empresa_id
SET l.site_id = s.id
WHERE l.site_id IS NULL;

UPDATE interviews i
JOIN company_sites s ON s.empresa_id = i.empresa_id
SET i.site_id = s.id
WHERE i.site_id IS NULL;

UPDATE canales c
JOIN company_sites s ON s.empresa_id = c.empresa_id
SET c.site_id = s.id
WHERE c.site_id IS NULL;

SET foreign_key_checks = 1;
