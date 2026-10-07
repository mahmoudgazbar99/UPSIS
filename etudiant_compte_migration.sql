USE ecole_sante_gabes;

CREATE TABLE IF NOT EXISTS etudiant_compte (
  id_compte INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_etudiant INT UNSIGNED NOT NULL,
  password VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_compte),
  UNIQUE KEY uq_etudiant_compte (id_etudiant),
  CONSTRAINT fk_etudiant_compte FOREIGN KEY (id_etudiant) REFERENCES etudiant (id_etudiant) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
