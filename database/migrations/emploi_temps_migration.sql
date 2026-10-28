USE ecole_sante_gabes;

SET @type_enseignant_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'enseignant'
    AND column_name = 'type_enseignant'
);
SET @type_enseignant_ddl = IF(
  @type_enseignant_exists = 0,
  'ALTER TABLE enseignant ADD COLUMN type_enseignant ENUM(''Cours'', ''TD'', ''TP'') NOT NULL DEFAULT ''Cours'' AFTER prenom',
  'SELECT 1'
);
PREPARE type_enseignant_statement FROM @type_enseignant_ddl;
EXECUTE type_enseignant_statement;
DEALLOCATE PREPARE type_enseignant_statement;

CREATE TABLE IF NOT EXISTS emploi_temps (
  id_emploi INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_groupe INT UNSIGNED NOT NULL,
  id_mat INT UNSIGNED NOT NULL,
  id_enseignant INT UNSIGNED NOT NULL,
  jour ENUM('Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi') NOT NULL,
  heure_debut TIME NOT NULL,
  heure_fin TIME NOT NULL,
  type_seance ENUM('Cours', 'TD', 'TP') NOT NULL,
  salle VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_emploi),
  KEY idx_emploi_groupe (id_groupe),
  CONSTRAINT fk_emploi_groupe FOREIGN KEY (id_groupe) REFERENCES groupe(id_groupe) ON DELETE CASCADE,
  CONSTRAINT fk_emploi_mat FOREIGN KEY (id_mat) REFERENCES matiere(id_mat) ON DELETE CASCADE,
  CONSTRAINT fk_emploi_teacher FOREIGN KEY (id_enseignant) REFERENCES enseignant(id_enseignant) ON DELETE CASCADE
) ENGINE=InnoDB;
