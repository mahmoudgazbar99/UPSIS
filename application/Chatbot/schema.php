<?php
declare(strict_types=1);

function chatbot_ensure_tables(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $pdo = $pdo ?: db();

    if (!db_table_exists($pdo, 'chatbot_conversation')) {
        $pdo->exec(
            "CREATE TABLE chatbot_conversation (
              id_conversation INT UNSIGNED NOT NULL AUTO_INCREMENT,
              owner_key VARCHAR(120) NOT NULL,
              audience VARCHAR(16) NOT NULL DEFAULT 'public',
              role_name VARCHAR(40) NOT NULL DEFAULT 'guest',
              title VARCHAR(180) NOT NULL DEFAULT 'Nouvelle conversation',
              page_path VARCHAR(255) NULL DEFAULT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id_conversation)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        try {
            $pdo->exec('CREATE INDEX idx_chatbot_owner_updated ON chatbot_conversation (owner_key, updated_at)');
        } catch (Throwable $e) {
            // index may already exist after shim rewrite
        }
    }

    if (!db_table_exists($pdo, 'chatbot_message')) {
        $pdo->exec(
            "CREATE TABLE chatbot_message (
              id_message INT UNSIGNED NOT NULL AUTO_INCREMENT,
              id_conversation INT UNSIGNED NOT NULL,
              role_name VARCHAR(16) NOT NULL,
              content TEXT NOT NULL,
              citations_json TEXT NULL DEFAULT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id_message)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        try {
            $pdo->exec('CREATE INDEX idx_chatbot_msg_conversation ON chatbot_message (id_conversation, id_message)');
        } catch (Throwable $e) {
        }
    }

    if (!db_table_exists($pdo, 'chatbot_feedback')) {
        $pdo->exec(
            "CREATE TABLE chatbot_feedback (
              id_feedback INT UNSIGNED NOT NULL AUTO_INCREMENT,
              id_message INT UNSIGNED NOT NULL,
              owner_key VARCHAR(120) NOT NULL,
              rating VARCHAR(8) NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id_feedback)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        try {
            $pdo->exec('CREATE UNIQUE INDEX uq_chatbot_feedback_owner_msg ON chatbot_feedback (owner_key, id_message)');
        } catch (Throwable $e) {
        }
    }

    if (!db_table_exists($pdo, 'chatbot_rate_limit')) {
        $pdo->exec(
            "CREATE TABLE chatbot_rate_limit (
              id_limit INT UNSIGNED NOT NULL AUTO_INCREMENT,
              bucket_key VARCHAR(160) NOT NULL,
              window_started TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              hit_count INT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (id_limit)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        try {
            $pdo->exec('CREATE UNIQUE INDEX uq_chatbot_rate_bucket ON chatbot_rate_limit (bucket_key)');
        } catch (Throwable $e) {
        }
    }
}
