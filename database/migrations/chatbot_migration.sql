-- UPSIS AI chatbot conversations (PostgreSQL via AppPdo MySQL-compat shim)
CREATE TABLE IF NOT EXISTS chatbot_conversation (
  id_conversation INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_key VARCHAR(120) NOT NULL,
  audience ENUM('public', 'staff') NOT NULL DEFAULT 'public',
  role_name VARCHAR(40) NOT NULL DEFAULT 'guest',
  title VARCHAR(180) NOT NULL DEFAULT 'Nouvelle conversation',
  page_path VARCHAR(255) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_conversation),
  KEY idx_chatbot_owner_updated (owner_key, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chatbot_message (
  id_message INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_conversation INT UNSIGNED NOT NULL,
  role_name ENUM('user', 'assistant', 'system') NOT NULL,
  content TEXT NOT NULL,
  citations_json TEXT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_message),
  KEY idx_chatbot_msg_conversation (id_conversation, id_message)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chatbot_feedback (
  id_feedback INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_message INT UNSIGNED NOT NULL,
  owner_key VARCHAR(120) NOT NULL,
  rating ENUM('up', 'down') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_feedback),
  UNIQUE KEY uq_chatbot_feedback_owner_msg (owner_key, id_message)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chatbot_rate_limit (
  id_limit INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket_key VARCHAR(160) NOT NULL,
  window_started TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  hit_count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id_limit),
  UNIQUE KEY uq_chatbot_rate_bucket (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
