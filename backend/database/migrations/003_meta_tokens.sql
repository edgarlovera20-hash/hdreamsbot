CREATE TABLE IF NOT EXISTS meta_oauth_tokens (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      VARCHAR(50)  NOT NULL UNIQUE,
  user_name    VARCHAR(100),
  access_token TEXT         NOT NULL,
  expires_at   DATETIME,
  pages_json   JSON,
  created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
