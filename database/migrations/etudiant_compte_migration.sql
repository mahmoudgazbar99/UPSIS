USE ecole_sante_gabes;

CREATE TABLE IF NOT EXISTS etudiant_compte (
  id_compte INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_etudiant INT UNSIGNED NOT NULL,
  password VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  auth_generation INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_compte),
  UNIQUE KEY uq_etudiant_compte (id_etudiant),
  CONSTRAINT fk_etudiant_compte FOREIGN KEY (id_etudiant) REFERENCES etudiant (id_etudiant) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @student_generation_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'etudiant_compte'
    AND column_name = 'auth_generation'
);
SET @student_generation_ddl = IF(
  @student_generation_exists = 0,
  'ALTER TABLE etudiant_compte ADD COLUMN auth_generation INT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE student_generation_statement FROM @student_generation_ddl;
EXECUTE student_generation_statement;
DEALLOCATE PREPARE student_generation_statement;
