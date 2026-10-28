CREATE TABLE IF NOT EXISTS audit_log (
  id_audit INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_role VARCHAR(20) NOT NULL,
  actor_id INT(10) UNSIGNED DEFAULT NULL,
  actor_label VARCHAR(160) NOT NULL,
  category ENUM('note', 'paiement', 'presence', 'compte') NOT NULL,
  action_name VARCHAR(40) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_audit),
  KEY idx_audit_category_date (category, created_at),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
