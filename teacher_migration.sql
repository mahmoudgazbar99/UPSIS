USE ecole_sante_gabes;

CREATE TABLE IF NOT EXISTS enseignant (
  id_enseignant INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cin VARCHAR(8) NOT NULL UNIQUE,
  nom VARCHAR(80) NOT NULL,
  prenom VARCHAR(80) NOT NULL,
  num_tel VARCHAR(30) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  adresse VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

ALTER TABLE enseignant MODIFY password VARCHAR(255) NOT NULL;

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
