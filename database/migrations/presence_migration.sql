USE ecole_sante_gabes;

CREATE TABLE IF NOT EXISTS presence_etudiant (
  id_presence INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  id_groupe INT(10) UNSIGNED NOT NULL,
  id_mat INT(10) UNSIGNED DEFAULT NULL,
  id_etudiant INT(10) UNSIGNED NOT NULL,
  date_seance DATE NOT NULL,
  statut ENUM('present', 'absent') NOT NULL,
  duree_heures DECIMAL(5,2) NOT NULL DEFAULT 1.50,
  id_enseignant INT(10) UNSIGNED DEFAULT NULL,
  recorded_by_role ENUM('admin', 'enseignant') NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_presence),
  UNIQUE KEY uq_presence_seance (id_groupe, id_mat, id_etudiant, date_seance),
  KEY idx_presence_groupe_date (id_groupe, date_seance),
  KEY idx_presence_etudiant (id_etudiant),
  KEY idx_presence_matiere (id_mat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
