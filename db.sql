CREATE DATABASE IF NOT EXISTS ecole_sante_gabes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ecole_sante_gabes;

CREATE TABLE specialite (
  id_spec INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom_spec VARCHAR(120) NOT NULL UNIQUE,
  nom_spec_ar VARCHAR(160) NOT NULL
) ENGINE=InnoDB;

INSERT INTO specialite (nom_spec, nom_spec_ar) VALUES
('Soins à domicile', 'العناية المنزلية'),
('Soins et accompagnement des personnes âgées', 'رعاية ومرافقة المسنين'),
('Anesthésie-Réanimation', 'التخدير والإنعاش'),
('Commun', 'الجذع المشترك');

CREATE TABLE etudiant (
  id_etudiant INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(80) NOT NULL,
  prenom VARCHAR(80) NOT NULL,
  nom_ar VARCHAR(100) NOT NULL,
  prenom_ar VARCHAR(100) NOT NULL,
  date_naissance DATE NOT NULL,
  lieu_naissance VARCHAR(120) NOT NULL,
  lieu_naissance_ar VARCHAR(160) NOT NULL,
  sexe ENUM('Femme','Homme') NOT NULL,
  nationalite VARCHAR(80) NOT NULL,
  etat_civil VARCHAR(40) NOT NULL,
  cin_passeport VARCHAR(40) NOT NULL UNIQUE,
  num_tel VARCHAR(30) NOT NULL,
  adresse VARCHAR(255) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  num_inscription VARCHAR(50) NOT NULL UNIQUE,
  date_inscrit DATE NOT NULL,
  id_spec INT UNSIGNED NOT NULL,
  niveau ENUM('1','2','3') NOT NULL,
  annee_bac SMALLINT UNSIGNED NOT NULL,
  specialite_bac ENUM('Science','Math','Lettre','Sport') NOT NULL,
  CONSTRAINT fk_etudiant_specialite FOREIGN KEY (id_spec) REFERENCES specialite(id_spec)
) ENGINE=InnoDB;

CREATE TABLE matiere (
  id_mat INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code_ue VARCHAR(30) NOT NULL,
  description_ue VARCHAR(255) NOT NULL,
  code_ecue VARCHAR(30) NOT NULL UNIQUE,
  description_ecue VARCHAR(255) NOT NULL,
  coeff DECIMAL(4,2) NOT NULL DEFAULT 1,
  credit DECIMAL(4,1) NOT NULL DEFAULT 0,
  regime VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE note (
  id_note INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_etudiant INT UNSIGNED NOT NULL,
  id_mat INT UNSIGNED NOT NULL,
  semestre ENUM('S1','S2','S3','S4','S5','S6') NOT NULL,
  note DECIMAL(5,2) NOT NULL,
  note_rattrapage DECIMAL(5,2) NULL,
  CONSTRAINT fk_note_etudiant FOREIGN KEY (id_etudiant) REFERENCES etudiant(id_etudiant) ON DELETE CASCADE,
  CONSTRAINT fk_note_matiere FOREIGN KEY (id_mat) REFERENCES matiere(id_mat) ON DELETE CASCADE,
  UNIQUE KEY unique_student_module_semester (id_etudiant,id_mat,semestre)
) ENGINE=InnoDB;

-- Données de démonstration : 40 étudiants pour tester la recherche et la pagination.
DELIMITER $$
CREATE PROCEDURE seed_demo_students()
BEGIN
  DECLARE student_number INT DEFAULT 1;
  WHILE student_number <= 40 DO
    INSERT IGNORE INTO etudiant (
      nom, prenom, nom_ar, prenom_ar, date_naissance, lieu_naissance,
      lieu_naissance_ar, sexe, nationalite, etat_civil, cin_passeport,
      num_tel, adresse, email, num_inscription, date_inscrit, id_spec,
      niveau, annee_bac, specialite_bac
    ) VALUES (
      CONCAT('Étudiant', student_number), CONCAT('Prénom', student_number),
      'طالب', CONCAT('تلميذ ', student_number),
      DATE_ADD('1998-01-01', INTERVAL student_number * 113 DAY), 'Gabès', 'قابس',
      IF(student_number % 2 = 0, 'Femme', 'Homme'), 'Tunisienne', 'Célibataire',
      CONCAT('CIN', LPAD(student_number, 5, '0')),
      CONCAT('20', LPAD(student_number, 6, '0')), CONCAT('Adresse ', student_number, ', Gabès'),
      CONCAT('etudiant', student_number, '@universite-sante.tn'),
      CONCAT('INS', LPAD(student_number, 6, '0')), '2026-09-01',
      ((student_number - 1) % 4) + 1, ((student_number - 1) % 3) + 1,
      2018 + (student_number % 8),
      ELT(((student_number - 1) % 4) + 1, 'Science', 'Math', 'Lettre', 'Sport')
    );
    SET student_number = student_number + 1;
  END WHILE;
END$$
DELIMITER ;

CALL seed_demo_students();
DROP PROCEDURE seed_demo_students;
