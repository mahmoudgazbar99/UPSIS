USE ecole_sante_gabes;

ALTER TABLE etudiant
  ADD COLUMN IF NOT EXISTS statut ENUM('actif', 'diplome') NOT NULL DEFAULT 'actif' AFTER niveau;

CREATE TABLE IF NOT EXISTS etudiant_archive (
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

CREATE TABLE IF NOT EXISTS student_grades_archive (
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

CREATE TABLE IF NOT EXISTS student_transcripts_archive (
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

CREATE TABLE IF NOT EXISTS student_fees_archive (
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

CREATE TABLE IF NOT EXISTS finance_rollover (
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

ALTER TABLE finance_rollover
  ADD COLUMN IF NOT EXISTS remaining_balance DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total_paye;
