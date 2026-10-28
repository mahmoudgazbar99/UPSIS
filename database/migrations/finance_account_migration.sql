USE ecole_sante_gabes;

INSERT INTO finance_user (username, password, active)
VALUES ('mondher', '$2y$10$/YF0CrLwWSYF49XPe/pH2.jG3Jgzn2tlMWTPGZtNk8A9jOxBrRHuO', 1)
ON DUPLICATE KEY UPDATE active = 1;

SET @auth_generation_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'finance_user'
    AND column_name = 'auth_generation'
);
SET @auth_generation_ddl = IF(
  @auth_generation_exists = 0,
  'ALTER TABLE finance_user ADD COLUMN auth_generation INT UNSIGNED NOT NULL DEFAULT 1 AFTER active',
  'SELECT 1'
);
PREPARE auth_generation_statement FROM @auth_generation_ddl;
EXECUTE auth_generation_statement;
DEALLOCATE PREPARE auth_generation_statement;
