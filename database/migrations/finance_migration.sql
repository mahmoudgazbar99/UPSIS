USE ecole_sante_gabes;

CREATE TABLE IF NOT EXISTS finance_user (
  id_finance INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  auth_generation INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS finance (
  id_finance_paiement INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_etudiant INT UNSIGNED NOT NULL,
  mode_paiement ENUM('virement','espece') NOT NULL,
  montant_paye DECIMAL(10,2) NOT NULL,
  id_finance_user INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT fk_finance_student FOREIGN KEY (id_etudiant) REFERENCES etudiant(id_etudiant) ON DELETE CASCADE,
  CONSTRAINT fk_finance_user FOREIGN KEY (id_finance_user) REFERENCES finance_user(id_finance) ON DELETE RESTRICT
) ENGINE=InnoDB;

ALTER TABLE finance ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL AFTER created_at;
ALTER TABLE finance_user ADD COLUMN IF NOT EXISTS auth_generation INT UNSIGNED NOT NULL DEFAULT 1 AFTER active;

INSERT INTO finance_user (username, password, active)
VALUES ('mondher', '$2y$10$/YF0CrLwWSYF49XPe/pH2.jG3Jgzn2tlMWTPGZtNk8A9jOxBrRHuO', 1)
ON DUPLICATE KEY UPDATE active = 1;

ALTER TABLE finance ADD INDEX idx_finance_student (id_etudiant);
ALTER TABLE finance DROP INDEX unique_student_payment;
ALTER TABLE finance CHANGE updated_at created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS finance_solde (
  id_etudiant INT UNSIGNED PRIMARY KEY,
  total_paye DECIMAL(10,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_finance_solde_student FOREIGN KEY (id_etudiant) REFERENCES etudiant(id_etudiant) ON DELETE CASCADE,
  CONSTRAINT chk_finance_solde_total CHECK (total_paye >= 0 AND total_paye <= 9800)
) ENGINE=InnoDB;

INSERT INTO finance_solde (id_etudiant, total_paye)
SELECT id_etudiant, SUM(montant_paye)
FROM finance
GROUP BY id_etudiant
ON DUPLICATE KEY UPDATE total_paye = VALUES(total_paye);
