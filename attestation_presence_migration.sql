USE ecole_sante_gabes;

CREATE TABLE IF NOT EXISTS system_settings (
  id_setting INT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(80) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  PRIMARY KEY (id_setting),
  UNIQUE KEY uq_system_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('attestation_feature_active', '1')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

CREATE TABLE IF NOT EXISTS demande_attestation (
  id_demande INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_etudiant INT UNSIGNED NOT NULL,
  cin VARCHAR(40) NOT NULL,
  motif VARCHAR(500) NOT NULL,
  statut ENUM('en_attente', 'acceptee', 'refusee') NOT NULL DEFAULT 'en_attente',
  date_demande TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_acceptation TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id_demande),
  KEY idx_demande_statut (statut, date_demande),
  KEY idx_demande_etudiant (id_etudiant),
  CONSTRAINT fk_demande_etudiant FOREIGN KEY (id_etudiant) REFERENCES etudiant (id_etudiant) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS etudiant_notifications (
  id_notif INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_etudiant INT UNSIGNED NOT NULL,
  sujet VARCHAR(180) NOT NULL,
  message_body TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_notif),
  KEY idx_etudiant_notif_unread (id_etudiant, is_read, date_creation),
  CONSTRAINT fk_etudiant_notifications FOREIGN KEY (id_etudiant)
    REFERENCES etudiant (id_etudiant) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
