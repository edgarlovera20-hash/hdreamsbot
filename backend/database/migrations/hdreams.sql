-- HDreams Bot - Schema completo
-- 2026-06-18

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -------------------------------------------------------
-- EMPRESAS Y SECCIONES
-- -------------------------------------------------------
CREATE TABLE empresas (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(100) NOT NULL,
  activo     TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE secciones (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id              INT NOT NULL,
  nombre                  VARCHAR(100) NOT NULL,
  slug                    VARCHAR(50) NOT NULL,
  system_prompt           TEXT,
  voz_activa              TINYINT DEFAULT 1,
  emocion_activa          TINYINT DEFAULT 1,
  pausas_base_ms          INT DEFAULT 300,
  pausas_nervioso_ms      INT DEFAULT 800,
  respiracion_audible     TINYINT DEFAULT 0,
  respiracion_frecuencia  ENUM('baja','media','alta') DEFAULT 'media',
  muletillas_activas      TINYINT DEFAULT 1,
  muletillas_frecuencia   ENUM('baja','media','alta') DEFAULT 'media',
  risas_activas           TINYINT DEFAULT 1,
  risas_intensidad        ENUM('sutil','normal','expresiva') DEFAULT 'sutil',
  fb_page_id              VARCHAR(100) NULL,
  fb_page_token           TEXT NULL,
  fb_auto_post            TINYINT DEFAULT 0,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- CANALES
-- -------------------------------------------------------
CREATE TABLE canales (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id    INT NOT NULL,
  seccion_id    INT NOT NULL,
  canal         ENUM('whatsapp','messenger','instagram','gmail','outlook','teams','telegram','facebook') NOT NULL,
  page_id       VARCHAR(100) NULL,
  access_token  TEXT NULL,
  webhook_token VARCHAR(100) NULL,
  activo        TINYINT DEFAULT 1,
  config        JSON NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_canal (empresa_id, seccion_id, canal, page_id),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (seccion_id) REFERENCES secciones(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- LEADS
-- -------------------------------------------------------
CREATE TABLE leads (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id           INT NOT NULL,
  seccion_id           INT NOT NULL,
  canal                ENUM('whatsapp','messenger','instagram','gmail','outlook','teams','facebook','telegram') NOT NULL,
  canal_user_id        VARCHAR(100) NOT NULL,
  nombre               VARCHAR(100),
  telefono             VARCHAR(20),
  email                VARCHAR(100),
  edad                 INT,
  estado               ENUM('nuevo','contactado','calificado','entrevista_agendada','entrevista_realizada','contratado','rechazado','no_interesado') DEFAULT 'nuevo',
  score                INT DEFAULT 0,
  score_ia_candidato   DECIMAL(5,2) DEFAULT 0,
  score_ia_contratacion DECIMAL(5,2) DEFAULT 0,
  prioridad            ENUM('baja','media','alta','urgente') DEFAULT 'media',
  fuente               VARCHAR(50),
  utm_source           VARCHAR(100),
  utm_campaign         VARCHAR(100),
  metadata             JSON,
  primera_interaccion  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ultima_interaccion   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fecha_contratado     TIMESTAMP NULL,
  responsable_id       INT NULL,
  tiempo_respuesta_seg INT DEFAULT 0,
  mensajes_enviados    INT DEFAULT 0,
  mensajes_recibidos   INT DEFAULT 1,
  ultimo_scoring       TIMESTAMP NULL,
  ab_test_id           INT NULL,
  ab_variante_id       INT NULL,
  fb_leadgen_id        VARCHAR(100) NULL,
  INDEX idx_estado    (empresa_id, seccion_id, estado),
  UNIQUE KEY idx_canal (empresa_id, canal, canal_user_id),
  INDEX idx_prioridad (prioridad),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (seccion_id) REFERENCES secciones(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- LEAD SCORING IA
-- -------------------------------------------------------
CREATE TABLE lead_scoring_ia (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  lead_id             INT NOT NULL,
  score_candidato     DECIMAL(5,2) DEFAULT 0,
  score_contratacion  DECIMAL(5,2) DEFAULT 0,
  score_prioridad     DECIMAL(5,2) DEFAULT 0,
  factores_positivos  JSON,
  factores_negativos  JSON,
  razonamiento        TEXT,
  modelo_version      VARCHAR(20) DEFAULT 'gpt-4o-1.0',
  calculado_en        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  INDEX idx_prioridad (score_prioridad DESC)
);

-- -------------------------------------------------------
-- COLA PRIORIDAD
-- -------------------------------------------------------
CREATE TABLE lead_cola_prioridad (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  lead_id        INT NOT NULL,
  prioridad      ENUM('baja','media','alta','urgente') DEFAULT 'media',
  score_prioridad DECIMAL(5,2),
  asignado_a     INT NULL,
  estado         ENUM('pendiente','en_proceso','contactado','cerrado') DEFAULT 'pendiente',
  sla_horas      INT DEFAULT 24,
  vence_en       TIMESTAMP,
  creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
  INDEX idx_prioridad (prioridad, score_prioridad DESC, vence_en)
);

-- -------------------------------------------------------
-- KPIs POR HORA
-- -------------------------------------------------------
CREATE TABLE kpi_horario (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id               INT NOT NULL,
  seccion_id               INT NOT NULL,
  canal                    ENUM('whatsapp','messenger','instagram','gmail','outlook','teams','facebook') NOT NULL,
  fecha                    DATE NOT NULL,
  hora                     TINYINT NOT NULL,
  mensajes_recibidos       INT DEFAULT 0,
  mensajes_enviados        INT DEFAULT 0,
  leads_nuevos             INT DEFAULT 0,
  leads_calificados        INT DEFAULT 0,
  tiempo_respuesta_promedio INT DEFAULT 0,
  tasa_conversion          DECIMAL(5,2) DEFAULT 0,
  UNIQUE KEY unique_hora (empresa_id, seccion_id, canal, fecha, hora),
  INDEX idx_fecha_hora (fecha, hora),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (seccion_id) REFERENCES secciones(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- A/B TESTING
-- -------------------------------------------------------
CREATE TABLE ab_tests (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id  INT NOT NULL,
  seccion_id  INT NOT NULL,
  nombre      VARCHAR(100) NOT NULL,
  tipo        ENUM('bienvenida','seguimiento','cierre','oferta') NOT NULL,
  activo      TINYINT DEFAULT 1,
  fecha_inicio DATE,
  fecha_fin    DATE NULL,
  ganador_id   INT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (seccion_id) REFERENCES secciones(id) ON DELETE CASCADE
);

CREATE TABLE ab_variantes (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  test_id              INT NOT NULL,
  nombre               VARCHAR(50) NOT NULL,
  mensaje              TEXT NOT NULL,
  porcentaje_trafico   INT DEFAULT 50,
  impresiones          INT DEFAULT 0,
  respuestas           INT DEFAULT 0,
  leads_generados      INT DEFAULT 0,
  entrevistas_agendadas INT DEFAULT 0,
  contratados          INT DEFAULT 0,
  tasa_respuesta       DECIMAL(5,2) DEFAULT 0,
  tasa_conversion      DECIMAL(5,2) DEFAULT 0,
  FOREIGN KEY (test_id) REFERENCES ab_tests(id) ON DELETE CASCADE,
  INDEX idx_test (test_id)
);

CREATE TABLE ab_asignaciones (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  test_id         INT NOT NULL,
  variante_id     INT NOT NULL,
  canal           ENUM('whatsapp','messenger','instagram','facebook') NOT NULL,
  canal_user_id   VARCHAR(100) NOT NULL,
  convertido      TINYINT DEFAULT 0,
  fecha_conversion TIMESTAMP NULL,
  UNIQUE KEY unique_user_test (test_id, canal, canal_user_id),
  FOREIGN KEY (test_id) REFERENCES ab_tests(id) ON DELETE CASCADE,
  FOREIGN KEY (variante_id) REFERENCES ab_variantes(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- FACEBOOK POSTS
-- -------------------------------------------------------
CREATE TABLE facebook_posts (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id       INT NOT NULL,
  seccion_id       INT NOT NULL,
  page_id          VARCHAR(100) NOT NULL,
  fb_post_id       VARCHAR(100) NULL,
  mensaje          TEXT NOT NULL,
  imagen_url       VARCHAR(255) NULL,
  link_url         VARCHAR(255) NULL,
  programado_para  TIMESTAMP NULL,
  estado           ENUM('borrador','programado','publicado','error') DEFAULT 'borrador',
  alcance          INT DEFAULT 0,
  reacciones       INT DEFAULT 0,
  comentarios      INT DEFAULT 0,
  clicks           INT DEFAULT 0,
  leads_generados  INT DEFAULT 0,
  publicado_en     TIMESTAMP NULL,
  error_msg        TEXT NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_estado (estado, programado_para),
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (seccion_id) REFERENCES secciones(id) ON DELETE CASCADE
);

-- -------------------------------------------------------
-- FACEBOOK LEAD ADS
-- -------------------------------------------------------
CREATE TABLE fb_lead_ads (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id        INT NOT NULL,
  seccion_id        INT NOT NULL,
  leadgen_id        VARCHAR(100) UNIQUE NOT NULL,
  ad_id             VARCHAR(100),
  ad_name           VARCHAR(200),
  campaign_id       VARCHAR(100),
  campaign_name     VARCHAR(200),
  form_id           VARCHAR(100),
  created_time      TIMESTAMP,
  nombre            VARCHAR(100),
  email             VARCHAR(100),
  telefono          VARCHAR(20),
  datos_adicionales JSON,
  lead_id           INT NULL,
  procesado         TINYINT DEFAULT 0,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  FOREIGN KEY (seccion_id) REFERENCES secciones(id) ON DELETE CASCADE,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
  INDEX idx_procesado (procesado, created_at)
);

-- -------------------------------------------------------
-- LOGS IA
-- -------------------------------------------------------
CREATE TABLE ia_logs (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  seccion_id INT NOT NULL,
  wa_id      VARCHAR(100) NOT NULL,
  canal      ENUM('whatsapp','messenger','instagram','gmail','outlook','teams','facebook') DEFAULT 'whatsapp',
  pregunta   TEXT,
  respuesta  TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- -------------------------------------------------------
-- DATOS INICIALES
-- -------------------------------------------------------
INSERT INTO empresas (id, nombre) VALUES (1, 'Heavenly Dreams');

INSERT INTO secciones (id, empresa_id, nombre, slug, system_prompt) VALUES
(1, 1, 'Recursos Humanos', 'rh',
 'Eres Lic. Gissell de RH. Captas asesores de ventas 17-35 años. Sueldo $2000 semanal + comisiones. Tono profesional pero cálido.');

INSERT INTO canales (empresa_id, seccion_id, canal, webhook_token) VALUES
(1, 1, 'whatsapp',  'HDREAMS_WA_2026'),
(1, 1, 'messenger', 'HDREAMS_MSG_2026'),
(1, 1, 'instagram', 'HDREAMS_IG_2026'),
(1, 1, 'facebook',  'HDREAMS_LEADS_2026');

SET foreign_key_checks = 1;
