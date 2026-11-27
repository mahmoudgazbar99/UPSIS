-- Smart Timetable Generator tables (additive).
-- Runtime also creates these via emploi_smart_ensure() in application/support/emploi_smart.php.
-- Rollback: DROP TABLE IF EXISTS emploi_unscheduled, emploi_generation_run, emploi_session,
--   emploi_composante, emploi_version, emploi_ressource, emploi_config;
-- Do not drop emploi_temps, groupe, matiere, or student data.

CREATE TABLE IF NOT EXISTS emploi_config (
  id_config INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label VARCHAR(80) NOT NULL DEFAULT 'default',
  working_days TEXT NOT NULL,
  morning_start TIME NOT NULL DEFAULT '08:15:00',
  morning_end TIME NOT NULL DEFAULT '13:00:00',
  afternoon_start TIME NOT NULL DEFAULT '15:00:00',
  afternoon_end TIME NOT NULL DEFAULT '18:00:00',
  break_start TIME NOT NULL DEFAULT '13:00:00',
  break_end TIME NOT NULL DEFAULT '15:00:00',
  slot_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  default_cours_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  default_td_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  default_tp_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 180,
  max_group_hours_day DECIMAL(4,1) NOT NULL DEFAULT 8.0,
  max_teacher_hours_day DECIMAL(4,1) NOT NULL DEFAULT 8.0,
  max_consecutive_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 180,
  min_break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  semester_weeks SMALLINT UNSIGNED NOT NULL DEFAULT 14,
  soft_weights TEXT NOT NULL,
  holidays TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_config)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emploi_ressource (
  id_ressource INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  nom VARCHAR(120) NOT NULL,
  type_ressource VARCHAR(20) NOT NULL,
  lab_category VARCHAR(80) NOT NULL DEFAULT '',
  capacite SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  active SMALLINT NOT NULL DEFAULT 1,
  availability TEXT NOT NULL,
  equipment TEXT NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_ressource),
  UNIQUE KEY uq_emploi_ressource_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emploi_version (
  id_version INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label VARCHAR(160) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  id_year INT UNSIGNED DEFAULT NULL,
  semestre VARCHAR(20) NOT NULL DEFAULT 'S1',
  id_level INT UNSIGNED DEFAULT NULL,
  id_spec INT UNSIGNED DEFAULT NULL,
  scope_json TEXT NOT NULL,
  config_json TEXT NOT NULL,
  quality_score DECIMAL(6,2) NOT NULL DEFAULT 0,
  created_by VARCHAR(120) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emploi_composante (
  id_composante INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_version INT UNSIGNED NOT NULL,
  id_mat INT UNSIGNED NOT NULL,
  type_seance VARCHAR(20) NOT NULL,
  id_enseignant INT UNSIGNED NOT NULL,
  id_groupe INT UNSIGNED NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  sessions_per_week DECIMAL(4,2) NOT NULL DEFAULT 1,
  frequency VARCHAR(20) NOT NULL DEFAULT 'weekly',
  lab_category VARCHAR(80) NOT NULL DEFAULT '',
  students_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  preferred_resource_type VARCHAR(20) NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (id_composante),
  KEY idx_emploi_comp_version (id_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emploi_session (
  id_session INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_version INT UNSIGNED NOT NULL,
  id_composante INT UNSIGNED DEFAULT NULL,
  id_groupe INT UNSIGNED NOT NULL,
  id_mat INT UNSIGNED NOT NULL,
  id_enseignant INT UNSIGNED NOT NULL,
  id_ressource INT UNSIGNED NOT NULL,
  type_seance VARCHAR(20) NOT NULL,
  jour VARCHAR(20) NOT NULL,
  semaine SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  week_pattern VARCHAR(20) NOT NULL DEFAULT 'weekly',
  heure_debut TIME NOT NULL,
  heure_fin TIME NOT NULL,
  locked SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id_session),
  KEY idx_emploi_sess_version (id_version),
  KEY idx_emploi_sess_groupe (id_groupe),
  KEY idx_emploi_sess_ressource (id_ressource)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emploi_generation_run (
  id_run INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_version INT UNSIGNED NOT NULL,
  requested_count INT UNSIGNED NOT NULL DEFAULT 0,
  scheduled_count INT UNSIGNED NOT NULL DEFAULT 0,
  unscheduled_count INT UNSIGNED NOT NULL DEFAULT 0,
  total_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
  quality_score DECIMAL(6,2) NOT NULL DEFAULT 0,
  stats_json TEXT NOT NULL,
  warnings_json TEXT NOT NULL,
  created_by VARCHAR(120) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_run),
  KEY idx_emploi_run_version (id_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emploi_unscheduled (
  id_unscheduled INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_run INT UNSIGNED NOT NULL,
  id_composante INT UNSIGNED DEFAULT NULL,
  id_mat INT UNSIGNED NOT NULL,
  type_seance VARCHAR(20) NOT NULL,
  id_enseignant INT UNSIGNED NOT NULL,
  id_groupe INT UNSIGNED NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL,
  required_resource VARCHAR(80) NOT NULL DEFAULT '',
  reason TEXT NOT NULL,
  suggestions TEXT NOT NULL,
  PRIMARY KEY (id_unscheduled),
  KEY idx_emploi_unscheduled_run (id_run)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
