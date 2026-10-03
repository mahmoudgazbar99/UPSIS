-- School management application schema
-- MySQL 8.0+ / MariaDB 10.4+
-- Import this file after creating/selecting the database in phpMyAdmin.
-- If an old database exists, remove it using the XAMPP cleanup steps
-- documented in README.md before importing this file.

CREATE DATABASE IF NOT EXISTS ecole_sante_gabes
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE ecole_sante_gabes;

CREATE TABLE admin_user (
  id_admin INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  PRIMARY KEY (id_admin),
  UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB;

CREATE TABLE specialite (
  id_spec INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom_spec VARCHAR(120) NOT NULL,
  nom_spec_ar VARCHAR(160) NOT NULL,
  PRIMARY KEY (id_spec),
  UNIQUE KEY uq_specialite_nom (nom_spec)
) ENGINE=InnoDB;

CREATE TABLE etudiant (
  id_etudiant INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom VARCHAR(80) NOT NULL,
  prenom VARCHAR(80) NOT NULL,
  nom_ar VARCHAR(100) NOT NULL,
  prenom_ar VARCHAR(100) NOT NULL,
  date_naissance DATE NOT NULL,
  lieu_naissance VARCHAR(120) NOT NULL,
  lieu_naissance_ar VARCHAR(160) NOT NULL,
  sexe ENUM('Femme', 'Homme') NOT NULL,
  nationalite VARCHAR(80) NOT NULL,
  etat_civil VARCHAR(40) NOT NULL,
  cin_passeport VARCHAR(40) NOT NULL,
  num_tel VARCHAR(30) NOT NULL,
  adresse VARCHAR(255) NOT NULL,
  email VARCHAR(150) NOT NULL,
  num_inscription VARCHAR(50) NOT NULL,
  date_inscrit DATE NOT NULL,
  id_spec INT UNSIGNED NOT NULL,
  niveau ENUM('1', '2', '3') NOT NULL,
  statut ENUM('actif', 'diplome') NOT NULL DEFAULT 'actif',
  annee_bac SMALLINT UNSIGNED NOT NULL,
  specialite_bac ENUM('Science', 'Math', 'Lettre', 'Sport') NOT NULL,
  PRIMARY KEY (id_etudiant),
  UNIQUE KEY uq_etudiant_cin (cin_passeport),
  UNIQUE KEY uq_etudiant_email (email),
  UNIQUE KEY uq_etudiant_inscription (num_inscription),
  KEY idx_etudiant_specialite (id_spec),
  CONSTRAINT fk_etudiant_specialite
    FOREIGN KEY (id_spec) REFERENCES specialite (id_spec)
) ENGINE=InnoDB;

CREATE TABLE matiere (
  id_mat INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code_ue VARCHAR(30) NOT NULL,
  description_ue VARCHAR(255) NOT NULL,
  code_ecue VARCHAR(30) NOT NULL,
  description_ecue VARCHAR(255) NOT NULL,
  semestre ENUM('S1', 'S2', 'S3', 'S4', 'S5', 'S6') NOT NULL DEFAULT 'S1',
  coeff DECIMAL(4,2) NOT NULL DEFAULT 1,
  credit DECIMAL(4,1) NOT NULL DEFAULT 0,
  regime VARCHAR(80) NOT NULL,
  volume_cours DECIMAL(5,2) NOT NULL DEFAULT 0,
  volume_td DECIMAL(5,2) NOT NULL DEFAULT 0,
  volume_tp DECIMAL(5,2) NOT NULL DEFAULT 0,
  volume_autres DECIMAL(5,2) NOT NULL DEFAULT 0,
  coefficient_ecue DECIMAL(4,2) NOT NULL DEFAULT 1,
  coefficient_ue DECIMAL(4,2) NOT NULL DEFAULT 1,
  PRIMARY KEY (id_mat),
  UNIQUE KEY uq_matiere_ecue (code_ecue),
  KEY idx_matiere_semestre (semestre)
) ENGINE=InnoDB;

CREATE TABLE groupe (
  id_groupe INT UNSIGNED NOT NULL AUTO_INCREMENT,
  semestre ENUM('S1', 'S2', 'S3', 'S4', 'S5', 'S6') NOT NULL,
  type_groupe ENUM('Cours', 'TD') NOT NULL,
  numero SMALLINT UNSIGNED NOT NULL,
  capacite SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (id_groupe),
  UNIQUE KEY uq_groupe (semestre, type_groupe, numero)
) ENGINE=InnoDB;

CREATE TABLE groupe_etudiant (
  id_groupe INT UNSIGNED NOT NULL,
  id_etudiant INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_groupe, id_etudiant),
  KEY idx_groupe_etudiant_etudiant (id_etudiant),
  CONSTRAINT fk_groupe_etudiant_groupe
    FOREIGN KEY (id_groupe) REFERENCES groupe (id_groupe) ON DELETE CASCADE,
  CONSTRAINT fk_groupe_etudiant_etudiant
    FOREIGN KEY (id_etudiant) REFERENCES etudiant (id_etudiant) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE note (
  id_note INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_etudiant INT UNSIGNED NOT NULL,
  id_mat INT UNSIGNED NOT NULL,
  id_groupe INT UNSIGNED NULL,
  semestre ENUM('S1', 'S2', 'S3', 'S4', 'S5', 'S6') NOT NULL,
  note DECIMAL(5,2) NULL,
  note_cours DECIMAL(5,2) NULL,
  note_td DECIMAL(5,2) NULL,
  note_tp DECIMAL(5,2) NULL,
  note_rattrapage DECIMAL(5,2) NULL,
  PRIMARY KEY (id_note),
  UNIQUE KEY uq_note_etudiant_matiere_semestre (id_etudiant, id_mat, semestre),
  KEY idx_note_matiere (id_mat),
  KEY idx_note_groupe (id_groupe),
  CONSTRAINT fk_note_etudiant
    FOREIGN KEY (id_etudiant) REFERENCES etudiant (id_etudiant) ON DELETE CASCADE,
  CONSTRAINT fk_note_matiere
    FOREIGN KEY (id_mat) REFERENCES matiere (id_mat) ON DELETE CASCADE,
  CONSTRAINT fk_note_groupe
    FOREIGN KEY (id_groupe) REFERENCES groupe (id_groupe) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE enseignant (
  id_enseignant INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cin VARCHAR(8) NOT NULL,
  nom VARCHAR(80) NOT NULL,
  prenom VARCHAR(80) NOT NULL,
  type_enseignant ENUM('Cours', 'TD', 'TP') NOT NULL DEFAULT 'Cours',
  num_tel VARCHAR(30) NOT NULL,
  email VARCHAR(150) NOT NULL,
  adresse VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  PRIMARY KEY (id_enseignant),
  UNIQUE KEY uq_enseignant_cin (cin),
  UNIQUE KEY uq_enseignant_email (email)
) ENGINE=InnoDB;

CREATE TABLE enseignant_affectation (
  id_affectation INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_enseignant INT UNSIGNED NOT NULL,
  id_mat INT UNSIGNED NOT NULL,
  id_groupe INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_affectation),
  UNIQUE KEY uq_affectation (id_enseignant, id_mat, id_groupe),
  KEY idx_affectation_matiere (id_mat),
  KEY idx_affectation_groupe (id_groupe),
  CONSTRAINT fk_affectation_enseignant
    FOREIGN KEY (id_enseignant) REFERENCES enseignant (id_enseignant) ON DELETE CASCADE,
  CONSTRAINT fk_affectation_matiere
    FOREIGN KEY (id_mat) REFERENCES matiere (id_mat) ON DELETE CASCADE,
  CONSTRAINT fk_affectation_groupe
    FOREIGN KEY (id_groupe) REFERENCES groupe (id_groupe) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE emploi_temps (
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
  CONSTRAINT fk_emploi_groupe FOREIGN KEY (id_groupe) REFERENCES groupe (id_groupe) ON DELETE CASCADE,
  CONSTRAINT fk_emploi_mat FOREIGN KEY (id_mat) REFERENCES matiere (id_mat) ON DELETE CASCADE,
  CONSTRAINT fk_emploi_teacher FOREIGN KEY (id_enseignant) REFERENCES enseignant (id_enseignant) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE finance_user (
  id_finance INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  password VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  auth_generation INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id_finance),
  UNIQUE KEY uq_finance_username (username)
) ENGINE=InnoDB;

INSERT INTO finance_user (username, password, active) VALUES
  ('mondher', '$2y$10$/YF0CrLwWSYF49XPe/pH2.jG3Jgzn2tlMWTPGZtNk8A9jOxBrRHuO', 1);

CREATE TABLE stage_user (
  id_stage INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cin VARCHAR(8) NOT NULL,
  password VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  auth_generation INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id_stage),
  UNIQUE KEY uq_stage_cin (cin)
) ENGINE=InnoDB;

CREATE TABLE finance_solde (
  id_etudiant INT UNSIGNED NOT NULL,
  total_paye DECIMAL(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id_etudiant),
  CONSTRAINT fk_finance_solde_student
    FOREIGN KEY (id_etudiant) REFERENCES etudiant (id_etudiant) ON DELETE CASCADE,
  CONSTRAINT chk_finance_solde_total
    CHECK (total_paye >= 0 AND total_paye <= 9800)
) ENGINE=InnoDB;

CREATE TABLE finance (
  id_finance_paiement INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_etudiant INT UNSIGNED NOT NULL,
  mode_paiement ENUM('virement', 'espece') NOT NULL,
  montant_paye DECIMAL(10,2) NOT NULL,
  id_finance_user INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id_finance_paiement),
  KEY idx_finance_student (id_etudiant),
  KEY idx_finance_user (id_finance_user),
  CONSTRAINT fk_finance_student
    FOREIGN KEY (id_etudiant) REFERENCES etudiant (id_etudiant) ON DELETE CASCADE,
  CONSTRAINT fk_finance_user
    FOREIGN KEY (id_finance_user) REFERENCES finance_user (id_finance) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE etudiant_archive (
  id_archive INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_etudiant INT UNSIGNED NOT NULL,
  annee_scolaire VARCHAR(9) NOT NULL,
  niveau VARCHAR(10) NOT NULL,
  id_spec INT UNSIGNED NULL,
  nom_spec VARCHAR(120) NULL,
  num_inscription VARCHAR(50) NOT NULL,
  cin_passeport VARCHAR(40) NOT NULL,
  decision ENUM('admis','redouble','archive') NOT NULL,
  moyenne_generale DECIMAL(5,2) NULL,
  student_payload JSON NOT NULL,
  transcript_payload JSON NOT NULL,
  finance_total_paye DECIMAL(10,2) NOT NULL DEFAULT 0,
  archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_by INT UNSIGNED NULL,
  PRIMARY KEY (id_archive),
  UNIQUE KEY uq_archive_student_year (id_etudiant, annee_scolaire),
  KEY idx_archive_cin_year (cin_passeport, annee_scolaire),
  CONSTRAINT fk_archive_student FOREIGN KEY (id_etudiant) REFERENCES etudiant (id_etudiant) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE student_grades_archive (
  id_archive_grade INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_archive INT UNSIGNED NOT NULL,
  id_etudiant INT UNSIGNED NOT NULL,
  annee_scolaire VARCHAR(9) NOT NULL,
  id_mat INT UNSIGNED NULL,
  semestre VARCHAR(5) NOT NULL,
  note DECIMAL(5,2) NULL,
  note_rattrapage DECIMAL(5,2) NULL,
  coeff DECIMAL(4,2) NOT NULL DEFAULT 1,
  credit DECIMAL(4,1) NOT NULL DEFAULT 0,
  subject_payload JSON NULL,
  PRIMARY KEY (id_archive_grade),
  KEY idx_archive_grade_student_year (id_etudiant, annee_scolaire),
  CONSTRAINT fk_archive_grade_header FOREIGN KEY (id_archive) REFERENCES etudiant_archive (id_archive) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE student_transcripts_archive (
  id_transcript_archive INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_archive INT UNSIGNED NOT NULL,
  id_etudiant INT UNSIGNED NOT NULL,
  annee_scolaire VARCHAR(9) NOT NULL,
  transcript_payload JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_transcript_archive),
  UNIQUE KEY uq_transcript_archive_year (id_etudiant, annee_scolaire),
  CONSTRAINT fk_transcript_archive_header FOREIGN KEY (id_archive) REFERENCES etudiant_archive (id_archive) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE student_fees_archive (
  id_fee_archive INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_archive INT UNSIGNED NOT NULL,
  id_etudiant INT UNSIGNED NOT NULL,
  annee_scolaire VARCHAR(9) NOT NULL,
  source_payment_id INT UNSIGNED NULL,
  mode_paiement VARCHAR(30) NOT NULL,
  montant_paye DECIMAL(10,2) NOT NULL,
  payment_created_at TIMESTAMP NULL,
  payment_updated_at TIMESTAMP NULL,
  id_finance_user INT UNSIGNED NULL,
  PRIMARY KEY (id_fee_archive),
  KEY idx_archive_fee_student_year (id_etudiant, annee_scolaire),
  CONSTRAINT fk_fee_archive_header FOREIGN KEY (id_archive) REFERENCES etudiant_archive (id_archive) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE finance_rollover (
  id_rollover INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_etudiant INT UNSIGNED NOT NULL,
  annee_scolaire VARCHAR(9) NOT NULL,
  total_paye DECIMAL(10,2) NOT NULL DEFAULT 0,
  remaining_balance DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  PRIMARY KEY (id_rollover),
  UNIQUE KEY uq_rollover_student_year (id_etudiant, annee_scolaire),
  CONSTRAINT fk_rollover_student FOREIGN KEY (id_etudiant) REFERENCES etudiant (id_etudiant) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO admin_user (username, password_hash) VALUES
  ('admin', '$2y$10$9K2qVnYWB/dfdoKTGMn1FO4Qb0bh07OMLHi3l6XJM8VESe28whYNW');

INSERT INTO specialite (nom_spec, nom_spec_ar) VALUES
  ('Soins à domicile', 'العناية المنزلية'),
  ('Soins et accompagnement des personnes âgées', 'رعاية ومرافقة المسنين'),
  ('Anesthésie-Réanimation', 'التخدير والإنعاش'),
  ('Commun', 'الجذع المشترك');

-- One lecture and one tutorial group per semester. Capacities match
-- assign_tronc_commun_groups() in config.php.
INSERT INTO groupe (semestre, type_groupe, numero, capacite) VALUES
  ('S1', 'Cours', 1, 50), ('S1', 'Cours', 2, 50), ('S1', 'Cours', 3, 50),
  ('S2', 'Cours', 1, 50), ('S2', 'Cours', 2, 50), ('S2', 'Cours', 3, 50),
  ('S3', 'Cours', 1, 50), ('S3', 'Cours', 2, 50), ('S3', 'Cours', 3, 50),
  ('S4', 'Cours', 1, 50),
  ('S5', 'Cours', 1, 50),
  ('S6', 'Cours', 1, 50);


-- 120 deterministic tronc-commun students.
-- The Commun specialty is inserted fourth above, so id_spec = 4.
INSERT INTO etudiant
  (nom, prenom, nom_ar, prenom_ar, date_naissance, lieu_naissance,
   lieu_naissance_ar, sexe, nationalite, etat_civil, cin_passeport,
   num_tel, adresse, email, num_inscription, date_inscrit, id_spec,
   niveau, annee_bac, specialite_bac)
VALUES
  ('Ben Salem', 'Ahmed', 'بن سالم', 'أحمد', '2000-01-01', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000001', '20000001', 'Gabès', 'etudiant001@universite-sante.tn', 'TC000001', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Trabelsi', 'Ahmed', 'الطرابلسي', 'أحمد', '2000-02-02', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000002', '20000002', 'Gabès', 'etudiant002@universite-sante.tn', 'TC000002', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jaziri', 'Ahmed', 'الجزيري', 'أحمد', '2000-03-03', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000003', '20000003', 'Gabès', 'etudiant003@universite-sante.tn', 'TC000003', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Masmoudi', 'Ahmed', 'المسعودي', 'أحمد', '2000-04-04', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000004', '20000004', 'Gabès', 'etudiant004@universite-sante.tn', 'TC000004', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Gharbi', 'Ahmed', 'الغربي', 'أحمد', '2000-05-05', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000005', '20000005', 'Gabès', 'etudiant005@universite-sante.tn', 'TC000005', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Brahmi', 'Ahmed', 'البراهمي', 'أحمد', '2000-06-06', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000006', '20000006', 'Gabès', 'etudiant006@universite-sante.tn', 'TC000006', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Khlifi', 'Ahmed', 'الخليفي', 'أحمد', '2000-07-07', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000007', '20000007', 'Gabès', 'etudiant007@universite-sante.tn', 'TC000007', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Ayari', 'Ahmed', 'العياري', 'أحمد', '2000-08-08', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000008', '20000008', 'Gabès', 'etudiant008@universite-sante.tn', 'TC000008', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chebbi', 'Ahmed', 'الشابي', 'أحمد', '2000-09-09', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000009', '20000009', 'Gabès', 'etudiant009@universite-sante.tn', 'TC000009', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Dridi', 'Ahmed', 'الدريدي', 'أحمد', '2000-10-10', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000010', '20000010', 'Gabès', 'etudiant010@universite-sante.tn', 'TC000010', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Haddad', 'Ahmed', 'الحداد', 'أحمد', '2000-11-11', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000011', '20000011', 'Gabès', 'etudiant011@universite-sante.tn', 'TC000011', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mabrouk', 'Ahmed', 'المبروك', 'أحمد', '2000-12-12', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000012', '20000012', 'Gabès', 'etudiant012@universite-sante.tn', 'TC000012', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Hamdi', 'Ahmed', 'الحمدي', 'أحمد', '2000-01-13', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000013', '20000013', 'Gabès', 'etudiant013@universite-sante.tn', 'TC000013', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Kammoun', 'Ahmed', 'الكمون', 'أحمد', '2000-02-14', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000014', '20000014', 'Gabès', 'etudiant014@universite-sante.tn', 'TC000014', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mejri', 'Ahmed', 'المجري', 'أحمد', '2000-03-15', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000015', '20000015', 'Gabès', 'etudiant015@universite-sante.tn', 'TC000015', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mansouri', 'Ahmed', 'المنصوري', 'أحمد', '2000-04-16', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000016', '20000016', 'Gabès', 'etudiant016@universite-sante.tn', 'TC000016', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Sassi', 'Ahmed', 'الساسي', 'أحمد', '2000-05-17', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000017', '20000017', 'Gabès', 'etudiant017@universite-sante.tn', 'TC000017', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Saidi', 'Ahmed', 'السعيدي', 'أحمد', '2000-06-18', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000018', '20000018', 'Gabès', 'etudiant018@universite-sante.tn', 'TC000018', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jebali', 'Ahmed', 'الجبالي', 'أحمد', '2000-07-19', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000019', '20000019', 'Gabès', 'etudiant019@universite-sante.tn', 'TC000019', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chaabane', 'Ahmed', 'الشعباني', 'أحمد', '2000-08-20', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000020', '20000020', 'Gabès', 'etudiant020@universite-sante.tn', 'TC000020', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Ben Salem', 'Mariem', 'بن سالم', 'مريم', '2000-09-21', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000021', '20000021', 'Gabès', 'etudiant021@universite-sante.tn', 'TC000021', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Trabelsi', 'Mariem', 'الطرابلسي', 'مريم', '2000-10-22', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000022', '20000022', 'Gabès', 'etudiant022@universite-sante.tn', 'TC000022', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jaziri', 'Mariem', 'الجزيري', 'مريم', '2000-11-23', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000023', '20000023', 'Gabès', 'etudiant023@universite-sante.tn', 'TC000023', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Masmoudi', 'Mariem', 'المسعودي', 'مريم', '2000-12-24', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000024', '20000024', 'Gabès', 'etudiant024@universite-sante.tn', 'TC000024', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Gharbi', 'Mariem', 'الغربي', 'مريم', '2000-01-25', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000025', '20000025', 'Gabès', 'etudiant025@universite-sante.tn', 'TC000025', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Brahmi', 'Mariem', 'البراهمي', 'مريم', '2000-02-26', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000026', '20000026', 'Gabès', 'etudiant026@universite-sante.tn', 'TC000026', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Khlifi', 'Mariem', 'الخليفي', 'مريم', '2000-03-27', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000027', '20000027', 'Gabès', 'etudiant027@universite-sante.tn', 'TC000027', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Ayari', 'Mariem', 'العياري', 'مريم', '2000-04-01', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000028', '20000028', 'Gabès', 'etudiant028@universite-sante.tn', 'TC000028', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chebbi', 'Mariem', 'الشابي', 'مريم', '2000-05-02', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000029', '20000029', 'Gabès', 'etudiant029@universite-sante.tn', 'TC000029', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Dridi', 'Mariem', 'الدريدي', 'مريم', '2000-06-03', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000030', '20000030', 'Gabès', 'etudiant030@universite-sante.tn', 'TC000030', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Haddad', 'Mariem', 'الحداد', 'مريم', '2000-07-04', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000031', '20000031', 'Gabès', 'etudiant031@universite-sante.tn', 'TC000031', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mabrouk', 'Mariem', 'المبروك', 'مريم', '2000-08-05', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000032', '20000032', 'Gabès', 'etudiant032@universite-sante.tn', 'TC000032', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Hamdi', 'Mariem', 'الحمدي', 'مريم', '2000-09-06', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000033', '20000033', 'Gabès', 'etudiant033@universite-sante.tn', 'TC000033', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Kammoun', 'Mariem', 'الكمون', 'مريم', '2000-10-07', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000034', '20000034', 'Gabès', 'etudiant034@universite-sante.tn', 'TC000034', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mejri', 'Mariem', 'المجري', 'مريم', '2000-11-08', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000035', '20000035', 'Gabès', 'etudiant035@universite-sante.tn', 'TC000035', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mansouri', 'Mariem', 'المنصوري', 'مريم', '2000-12-09', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000036', '20000036', 'Gabès', 'etudiant036@universite-sante.tn', 'TC000036', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Sassi', 'Mariem', 'الساسي', 'مريم', '2000-01-10', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000037', '20000037', 'Gabès', 'etudiant037@universite-sante.tn', 'TC000037', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Saidi', 'Mariem', 'السعيدي', 'مريم', '2000-02-11', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000038', '20000038', 'Gabès', 'etudiant038@universite-sante.tn', 'TC000038', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jebali', 'Mariem', 'الجبالي', 'مريم', '2000-03-12', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000039', '20000039', 'Gabès', 'etudiant039@universite-sante.tn', 'TC000039', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chaabane', 'Mariem', 'الشعباني', 'مريم', '2000-04-13', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000040', '20000040', 'Gabès', 'etudiant040@universite-sante.tn', 'TC000040', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Ben Salem', 'Mohamed', 'بن سالم', 'محمد', '2000-05-14', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000041', '20000041', 'Gabès', 'etudiant041@universite-sante.tn', 'TC000041', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Trabelsi', 'Mohamed', 'الطرابلسي', 'محمد', '2000-06-15', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000042', '20000042', 'Gabès', 'etudiant042@universite-sante.tn', 'TC000042', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jaziri', 'Mohamed', 'الجزيري', 'محمد', '2000-07-16', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000043', '20000043', 'Gabès', 'etudiant043@universite-sante.tn', 'TC000043', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Masmoudi', 'Mohamed', 'المسعودي', 'محمد', '2000-08-17', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000044', '20000044', 'Gabès', 'etudiant044@universite-sante.tn', 'TC000044', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Gharbi', 'Mohamed', 'الغربي', 'محمد', '2000-09-18', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000045', '20000045', 'Gabès', 'etudiant045@universite-sante.tn', 'TC000045', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Brahmi', 'Mohamed', 'البراهمي', 'محمد', '2000-10-19', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000046', '20000046', 'Gabès', 'etudiant046@universite-sante.tn', 'TC000046', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Khlifi', 'Mohamed', 'الخليفي', 'محمد', '2000-11-20', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000047', '20000047', 'Gabès', 'etudiant047@universite-sante.tn', 'TC000047', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Ayari', 'Mohamed', 'العياري', 'محمد', '2000-12-21', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000048', '20000048', 'Gabès', 'etudiant048@universite-sante.tn', 'TC000048', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chebbi', 'Mohamed', 'الشابي', 'محمد', '2000-01-22', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000049', '20000049', 'Gabès', 'etudiant049@universite-sante.tn', 'TC000049', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Dridi', 'Mohamed', 'الدريدي', 'محمد', '2000-02-23', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000050', '20000050', 'Gabès', 'etudiant050@universite-sante.tn', 'TC000050', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Haddad', 'Mohamed', 'الحداد', 'محمد', '2000-03-24', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000051', '20000051', 'Gabès', 'etudiant051@universite-sante.tn', 'TC000051', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mabrouk', 'Mohamed', 'المبروك', 'محمد', '2000-04-25', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000052', '20000052', 'Gabès', 'etudiant052@universite-sante.tn', 'TC000052', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Hamdi', 'Mohamed', 'الحمدي', 'محمد', '2000-05-26', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000053', '20000053', 'Gabès', 'etudiant053@universite-sante.tn', 'TC000053', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Kammoun', 'Mohamed', 'الكمون', 'محمد', '2000-06-27', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000054', '20000054', 'Gabès', 'etudiant054@universite-sante.tn', 'TC000054', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mejri', 'Mohamed', 'المجري', 'محمد', '2000-07-01', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000055', '20000055', 'Gabès', 'etudiant055@universite-sante.tn', 'TC000055', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mansouri', 'Mohamed', 'المنصوري', 'محمد', '2000-08-02', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000056', '20000056', 'Gabès', 'etudiant056@universite-sante.tn', 'TC000056', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Sassi', 'Mohamed', 'الساسي', 'محمد', '2000-09-03', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000057', '20000057', 'Gabès', 'etudiant057@universite-sante.tn', 'TC000057', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Saidi', 'Mohamed', 'السعيدي', 'محمد', '2000-10-04', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000058', '20000058', 'Gabès', 'etudiant058@universite-sante.tn', 'TC000058', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jebali', 'Mohamed', 'الجبالي', 'محمد', '2000-11-05', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000059', '20000059', 'Gabès', 'etudiant059@universite-sante.tn', 'TC000059', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chaabane', 'Mohamed', 'الشعباني', 'محمد', '2000-12-06', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000060', '20000060', 'Gabès', 'etudiant060@universite-sante.tn', 'TC000060', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Ben Salem', 'Amira', 'بن سالم', 'أميرة', '2000-01-07', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000061', '20000061', 'Gabès', 'etudiant061@universite-sante.tn', 'TC000061', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Trabelsi', 'Amira', 'الطرابلسي', 'أميرة', '2000-02-08', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000062', '20000062', 'Gabès', 'etudiant062@universite-sante.tn', 'TC000062', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jaziri', 'Amira', 'الجزيري', 'أميرة', '2000-03-09', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000063', '20000063', 'Gabès', 'etudiant063@universite-sante.tn', 'TC000063', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Masmoudi', 'Amira', 'المسعودي', 'أميرة', '2000-04-10', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000064', '20000064', 'Gabès', 'etudiant064@universite-sante.tn', 'TC000064', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Gharbi', 'Amira', 'الغربي', 'أميرة', '2000-05-11', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000065', '20000065', 'Gabès', 'etudiant065@universite-sante.tn', 'TC000065', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Brahmi', 'Amira', 'البراهمي', 'أميرة', '2000-06-12', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000066', '20000066', 'Gabès', 'etudiant066@universite-sante.tn', 'TC000066', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Khlifi', 'Amira', 'الخليفي', 'أميرة', '2000-07-13', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000067', '20000067', 'Gabès', 'etudiant067@universite-sante.tn', 'TC000067', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Ayari', 'Amira', 'العياري', 'أميرة', '2000-08-14', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000068', '20000068', 'Gabès', 'etudiant068@universite-sante.tn', 'TC000068', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chebbi', 'Amira', 'الشابي', 'أميرة', '2000-09-15', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000069', '20000069', 'Gabès', 'etudiant069@universite-sante.tn', 'TC000069', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Dridi', 'Amira', 'الدريدي', 'أميرة', '2000-10-16', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000070', '20000070', 'Gabès', 'etudiant070@universite-sante.tn', 'TC000070', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Haddad', 'Amira', 'الحداد', 'أميرة', '2000-11-17', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000071', '20000071', 'Gabès', 'etudiant071@universite-sante.tn', 'TC000071', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mabrouk', 'Amira', 'المبروك', 'أميرة', '2000-12-18', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000072', '20000072', 'Gabès', 'etudiant072@universite-sante.tn', 'TC000072', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Hamdi', 'Amira', 'الحمدي', 'أميرة', '2000-01-19', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000073', '20000073', 'Gabès', 'etudiant073@universite-sante.tn', 'TC000073', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Kammoun', 'Amira', 'الكمون', 'أميرة', '2000-02-20', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000074', '20000074', 'Gabès', 'etudiant074@universite-sante.tn', 'TC000074', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mejri', 'Amira', 'المجري', 'أميرة', '2000-03-21', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000075', '20000075', 'Gabès', 'etudiant075@universite-sante.tn', 'TC000075', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mansouri', 'Amira', 'المنصوري', 'أميرة', '2000-04-22', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000076', '20000076', 'Gabès', 'etudiant076@universite-sante.tn', 'TC000076', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Sassi', 'Amira', 'الساسي', 'أميرة', '2000-05-23', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000077', '20000077', 'Gabès', 'etudiant077@universite-sante.tn', 'TC000077', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Saidi', 'Amira', 'السعيدي', 'أميرة', '2000-06-24', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000078', '20000078', 'Gabès', 'etudiant078@universite-sante.tn', 'TC000078', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jebali', 'Amira', 'الجبالي', 'أميرة', '2000-07-25', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000079', '20000079', 'Gabès', 'etudiant079@universite-sante.tn', 'TC000079', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chaabane', 'Amira', 'الشعباني', 'أميرة', '2000-08-26', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000080', '20000080', 'Gabès', 'etudiant080@universite-sante.tn', 'TC000080', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Ben Salem', 'Youssef', 'بن سالم', 'يوسف', '2000-09-27', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000081', '20000081', 'Gabès', 'etudiant081@universite-sante.tn', 'TC000081', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Trabelsi', 'Youssef', 'الطرابلسي', 'يوسف', '2000-10-01', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000082', '20000082', 'Gabès', 'etudiant082@universite-sante.tn', 'TC000082', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jaziri', 'Youssef', 'الجزيري', 'يوسف', '2000-11-02', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000083', '20000083', 'Gabès', 'etudiant083@universite-sante.tn', 'TC000083', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Masmoudi', 'Youssef', 'المسعودي', 'يوسف', '2000-12-03', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000084', '20000084', 'Gabès', 'etudiant084@universite-sante.tn', 'TC000084', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Gharbi', 'Youssef', 'الغربي', 'يوسف', '2000-01-04', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000085', '20000085', 'Gabès', 'etudiant085@universite-sante.tn', 'TC000085', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Brahmi', 'Youssef', 'البراهمي', 'يوسف', '2000-02-05', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000086', '20000086', 'Gabès', 'etudiant086@universite-sante.tn', 'TC000086', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Khlifi', 'Youssef', 'الخليفي', 'يوسف', '2000-03-06', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000087', '20000087', 'Gabès', 'etudiant087@universite-sante.tn', 'TC000087', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Ayari', 'Youssef', 'العياري', 'يوسف', '2000-04-07', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000088', '20000088', 'Gabès', 'etudiant088@universite-sante.tn', 'TC000088', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chebbi', 'Youssef', 'الشابي', 'يوسف', '2000-05-08', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000089', '20000089', 'Gabès', 'etudiant089@universite-sante.tn', 'TC000089', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Dridi', 'Youssef', 'الدريدي', 'يوسف', '2000-06-09', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000090', '20000090', 'Gabès', 'etudiant090@universite-sante.tn', 'TC000090', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Haddad', 'Youssef', 'الحداد', 'يوسف', '2000-07-10', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000091', '20000091', 'Gabès', 'etudiant091@universite-sante.tn', 'TC000091', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mabrouk', 'Youssef', 'المبروك', 'يوسف', '2000-08-11', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000092', '20000092', 'Gabès', 'etudiant092@universite-sante.tn', 'TC000092', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Hamdi', 'Youssef', 'الحمدي', 'يوسف', '2000-09-12', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000093', '20000093', 'Gabès', 'etudiant093@universite-sante.tn', 'TC000093', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Kammoun', 'Youssef', 'الكمون', 'يوسف', '2000-10-13', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000094', '20000094', 'Gabès', 'etudiant094@universite-sante.tn', 'TC000094', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mejri', 'Youssef', 'المجري', 'يوسف', '2000-11-14', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000095', '20000095', 'Gabès', 'etudiant095@universite-sante.tn', 'TC000095', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mansouri', 'Youssef', 'المنصوري', 'يوسف', '2000-12-15', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000096', '20000096', 'Gabès', 'etudiant096@universite-sante.tn', 'TC000096', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Sassi', 'Youssef', 'الساسي', 'يوسف', '2000-01-16', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000097', '20000097', 'Gabès', 'etudiant097@universite-sante.tn', 'TC000097', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Saidi', 'Youssef', 'السعيدي', 'يوسف', '2000-02-17', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000098', '20000098', 'Gabès', 'etudiant098@universite-sante.tn', 'TC000098', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jebali', 'Youssef', 'الجبالي', 'يوسف', '2000-03-18', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000099', '20000099', 'Gabès', 'etudiant099@universite-sante.tn', 'TC000099', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chaabane', 'Youssef', 'الشعباني', 'يوسف', '2000-04-19', 'Gabès', 'قابس', 'Homme', 'Tunisienne', 'Célibataire', 'TC000100', '20000100', 'Gabès', 'etudiant100@universite-sante.tn', 'TC000100', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Ben Salem', 'Sarra', 'بن سالم', 'سارة', '2000-05-20', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000101', '20000101', 'Gabès', 'etudiant101@universite-sante.tn', 'TC000101', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Trabelsi', 'Sarra', 'الطرابلسي', 'سارة', '2000-06-21', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000102', '20000102', 'Gabès', 'etudiant102@universite-sante.tn', 'TC000102', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jaziri', 'Sarra', 'الجزيري', 'سارة', '2000-07-22', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000103', '20000103', 'Gabès', 'etudiant103@universite-sante.tn', 'TC000103', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Masmoudi', 'Sarra', 'المسعودي', 'سارة', '2000-08-23', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000104', '20000104', 'Gabès', 'etudiant104@universite-sante.tn', 'TC000104', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Gharbi', 'Sarra', 'الغربي', 'سارة', '2000-09-24', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000105', '20000105', 'Gabès', 'etudiant105@universite-sante.tn', 'TC000105', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Brahmi', 'Sarra', 'البراهمي', 'سارة', '2000-10-25', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000106', '20000106', 'Gabès', 'etudiant106@universite-sante.tn', 'TC000106', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Khlifi', 'Sarra', 'الخليفي', 'سارة', '2000-11-26', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000107', '20000107', 'Gabès', 'etudiant107@universite-sante.tn', 'TC000107', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Ayari', 'Sarra', 'العياري', 'سارة', '2000-12-27', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000108', '20000108', 'Gabès', 'etudiant108@universite-sante.tn', 'TC000108', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chebbi', 'Sarra', 'الشابي', 'سارة', '2000-01-01', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000109', '20000109', 'Gabès', 'etudiant109@universite-sante.tn', 'TC000109', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Dridi', 'Sarra', 'الدريدي', 'سارة', '2000-02-02', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000110', '20000110', 'Gabès', 'etudiant110@universite-sante.tn', 'TC000110', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Haddad', 'Sarra', 'الحداد', 'سارة', '2000-03-03', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000111', '20000111', 'Gabès', 'etudiant111@universite-sante.tn', 'TC000111', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mabrouk', 'Sarra', 'المبروك', 'سارة', '2000-04-04', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000112', '20000112', 'Gabès', 'etudiant112@universite-sante.tn', 'TC000112', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Hamdi', 'Sarra', 'الحمدي', 'سارة', '2000-05-05', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000113', '20000113', 'Gabès', 'etudiant113@universite-sante.tn', 'TC000113', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Kammoun', 'Sarra', 'الكمون', 'سارة', '2000-06-06', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000114', '20000114', 'Gabès', 'etudiant114@universite-sante.tn', 'TC000114', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mejri', 'Sarra', 'المجري', 'سارة', '2000-07-07', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000115', '20000115', 'Gabès', 'etudiant115@universite-sante.tn', 'TC000115', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Mansouri', 'Sarra', 'المنصوري', 'سارة', '2000-08-08', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000116', '20000116', 'Gabès', 'etudiant116@universite-sante.tn', 'TC000116', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Sassi', 'Sarra', 'الساسي', 'سارة', '2000-09-09', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000117', '20000117', 'Gabès', 'etudiant117@universite-sante.tn', 'TC000117', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Saidi', 'Sarra', 'السعيدي', 'سارة', '2000-10-10', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000118', '20000118', 'Gabès', 'etudiant118@universite-sante.tn', 'TC000118', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Jebali', 'Sarra', 'الجبالي', 'سارة', '2000-11-11', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000119', '20000119', 'Gabès', 'etudiant119@universite-sante.tn', 'TC000119', '2026-09-01', 4, '1', 2020, 'Science'),
  ('Chaabane', 'Sarra', 'الشعباني', 'سارة', '2000-12-12', 'Gabès', 'قابس', 'Femme', 'Tunisienne', 'Célibataire', 'TC000120', '20000120', 'Gabès', 'etudiant120@universite-sante.tn', 'TC000120', '2026-09-01', 4, '1', 2020, 'Science');

-- Assign every tronc-commun student evenly to the three S1-S3 Cours groups.
INSERT INTO groupe_etudiant (id_groupe, id_etudiant)
SELECT g.id_groupe, e.id_etudiant
FROM groupe g
CROSS JOIN etudiant e
WHERE e.id_spec = 4
  AND g.semestre IN ('S1', 'S2', 'S3')
  AND g.type_groupe = 'Cours'
  AND g.numero = MOD(CAST(SUBSTRING(e.cin_passeport, 3) AS UNSIGNED) - 1, 3) + 1;

INSERT INTO matiere
  (code_ue, description_ue, code_ecue, description_ecue, semestre, coeff,
   credit, regime, volume_cours, volume_td, volume_tp, volume_autres,
   coefficient_ecue, coefficient_ue)
VALUES
  ('UEF110','Sciences biomédicales 1','UEF111','Anatomie physiologie 1','S1',5,5,'Mixte',42,28,0,0,2.5,2.5),
  ('UEF120','Discipline paramédicale - Biologie - Ethique','UEF121','Diabétologie - Ethique','S1',2,0,'Mixte',24,0,0,0,1,2.5),
  ('UEF120','Discipline paramédicale - Biologie - Ethique','UEF122','Education à la discipline','S1',3,0,'Mixte',30,0,0,0,1.5,2.5),
  ('UEF130','Méthodes d’interventions infirmières 1','UEF131','Techniques de soins','S1',3,0,'Mixte',6,36,45,0,1.5,3),
  ('UEF130','Méthodes d’interventions infirmières 1','UEF132','Démarche de soins','S1',3,0,'Mixte',20,22,0,14,1.5,3),
  ('UEF140','Soins infirmiers en santé communautaire','UEF141','Soins dans la communauté 1','S1',2,0,'Mixte',21,9,0,12,1,2),
  ('UET120','Techniques de communication et Psychologie du développement social','UET121','Psychologie du développement et développement social','S1',2,0,'C.C',21,0,0,0,1,2),
  ('UET120','Techniques de communication et Psychologie du développement social','UET122','Techniques de communication 1','S1',2,0,'C.C',21,0,0,0,1,2),
  ('UET110','Formation transversale 1','UET111','Français Professionnel 1','S1',2,0,'C.C',21,0,0,0,1,3),
  ('UET110','Formation transversale 1','UET112','Digital (Certif 2CN)','S1',2,0,'C.C',21,0,0,0,1,3),
  ('UET110','Formation transversale 1','UET113','Study Skills (Quiz)','S1',2,0,'C.C',21,0,0,0,1,3),
  ('UEF210','Sciences biomédicales 2','UEF211','Biochimie clinique','S2',1,1,'Mixte',21,0,0,0,1,3),
  ('UEF210','Sciences biomédicales 2','UEF212','Anatomie Physiologie 2','S2',3,0,'Mixte',28,14,0,0,1,3),
  ('UEF210','Sciences biomédicales 2','UEF213','Pharmacologie 1','S2',2,0,'Mixte',21,0,0,0,1,3),
  ('UEF220','Soins infirmiers en gynécologie et périnatalité','UEF221','Soins infirmiers en gynécologie et périnatalité','S2',3,0,'Mixte',30,6,24,0,1.5,2),
  ('UEF220','Soins infirmiers en gynécologie et périnatalité','UEF222','Hygiène et environnement','S2',1,0,'Mixte',15,9,0,0,0.5,2),
  ('UEF230','Méthodes d’interventions infirmières 2','UEF231','Techniques infirmières 2','S2',2,0,'Mixte',0,30,0,0,1,2),
  ('UEF230','Méthodes d’interventions infirmières 2','UEF232','Relation d’aide','S2',2,0,'Mixte',20,10,0,0,1,2),
  ('UEF240','Pratiques de soins 1','UEF241','Initiation aux premiers secours','S2',2,0,'Mixte',6,0,20,0,1,3),
  ('UEF240','Pratiques de soins 1','UEF242','Pratique de soins en médecine-chirurgie 1','S2',4,0,'Mixte',0,0,70,0,2,3),
  ('UET220','Techniques de Communication, Sociologie et Philosophie des sciences','UET221','Sociologie de la santé','S2',2,0,'C.C',21,0,0,0,0.5,1),
  ('UET220','Techniques de Communication, Sociologie et Philosophie des sciences','UET222','Philosophie des Sciences','S2',1,0,'C.C',28,0,0,0,1,1),
  ('UET220','Techniques de Communication, Sociologie et Philosophie des sciences','UET223','Techniques de communication 2','S2',2,0,'C.C',14,0,0,0,0.5,1),
  ('UET210','Formation transversale 2','UET211','Français Pro2','S2',2,0,'C.C',21,0,0,0,1,1.5),
  ('UET210','Formation transversale 2','UET212','Anglais médical 1','S2',2,0,'C.C',21,0,0,0,1,1.5),
  ('UET210','Formation transversale 2','UET213','Apprendre à apprendre','S2',2,0,'C.C',21,0,0,0,1,1.5),
  ('UEF310','Soins infirmiers adultes 1','UEF311','Soins infirmiers en cardiologie','S3',2,0,'Mixte',16,0,8,0,1,2),
  ('UEF310','Soins infirmiers adultes 1','UEF312','Soins infirmiers en pneumologie','S3',2,0,'Mixte',14,0,8,0,1,2),
  ('UEF310','Soins infirmiers adultes 1','UEF313','Pharmacologie 2','S3',2,0,'Mixte',30,0,0,0,1,2),
  ('UEF320','Soins infirmiers adultes 2','UEF321','Soins infirmiers en infectiologie','S3',2,0,'Mixte',15,9,0,0,1,2.5),
  ('UEF320','Soins infirmiers adultes 2','UEF322','Soins infirmiers en neurologie','S3',3,0,'Mixte',24,12,0,0,1.5,2.5),
  ('UEF330','Soins infirmiers en pédiatrie','UEF331','Soins infirmiers en pédiatrie','S3',3,0,'Mixte',30,15,0,0,1.5,1.5),
  ('UEF340','Pratiques de soins 2','UEF341','Pratique de soins en médecine-chirurgie 2','S3',6,0,'Mixte',0,0,110,0,3,3),
  ('UET320','Soins infirmiers en santé au travail et en situation critique','UET321','Santé et sécurité au travail','S3',2,0,'C.C',20,0,15,0,1,1),
  ('UET320','Soins infirmiers en santé au travail et en situation critique','UET322','Soins infirmiers et santé de l’adolescent','S3',1,0,'C.C',20,0,15,0,0.5,1),
  ('UET320','Soins infirmiers en santé au travail et en situation critique','UET323','Soins infirmiers en rhumatologie et Handicap','S3',1,0,'C.C',20,0,15,0,0.5,1),
  ('UET310','Formation transversale 3','UET311','Français Pro3','S3',2,0,'C.C',21,0,0,0,1,1),
  ('UET310','Formation transversale 3','UET312','Anglais médical 2','S3',2,0,'C.C',21,0,0,0,1,1),
  ('UET310','Formation transversale 3','UET313','Soft Skills (Projet Pro)','S3',2,0,'C.C',21,0,0,0,1,1);
