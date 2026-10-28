USE ecole_sante_gabes;

CREATE TABLE IF NOT EXISTS enseignant (
  id_enseignant INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cin VARCHAR(8) NOT NULL UNIQUE,
  nom VARCHAR(80) NOT NULL,
  prenom VARCHAR(80) NOT NULL,
  num_tel VARCHAR(30) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  adresse VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  auth_generation INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;

ALTER TABLE enseignant MODIFY password VARCHAR(255) NOT NULL;
SET @teacher_active_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'enseignant'
    AND column_name = 'active'
);
SET @teacher_active_ddl = IF(
  @teacher_active_exists = 0,
  'ALTER TABLE enseignant ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE teacher_active_statement FROM @teacher_active_ddl;
EXECUTE teacher_active_statement;
DEALLOCATE PREPARE teacher_active_statement;

SET @teacher_generation_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'enseignant'
    AND column_name = 'auth_generation'
);
SET @teacher_generation_ddl = IF(
  @teacher_generation_exists = 0,
  'ALTER TABLE enseignant ADD COLUMN auth_generation INT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE teacher_generation_statement FROM @teacher_generation_ddl;
EXECUTE teacher_generation_statement;
DEALLOCATE PREPARE teacher_generation_statement;

CREATE TABLE IF NOT EXISTS enseignant_affectation (
  id_affectation INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_enseignant INT UNSIGNED NOT NULL,
  id_mat INT UNSIGNED NOT NULL,
  id_groupe INT UNSIGNED NOT NULL,
  UNIQUE KEY unique_teacher_subject_group (id_enseignant, id_mat, id_groupe),
  CONSTRAINT fk_affectation_enseignant FOREIGN KEY (id_enseignant) REFERENCES enseignant(id_enseignant) ON DELETE CASCADE,
  CONSTRAINT fk_affectation_matiere FOREIGN KEY (id_mat) REFERENCES matiere(id_mat) ON DELETE CASCADE,
  CONSTRAINT fk_affectation_groupe FOREIGN KEY (id_groupe) REFERENCES groupe(id_groupe) ON DELETE CASCADE
) ENGINE=InnoDB;
