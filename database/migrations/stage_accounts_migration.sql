USE ecole_sante_gabes;

CREATE TABLE IF NOT EXISTS stage_user (
  id_stage INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cin VARCHAR(8) NOT NULL,
  password VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  auth_generation INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id_stage),
  UNIQUE KEY uq_stage_cin (cin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
