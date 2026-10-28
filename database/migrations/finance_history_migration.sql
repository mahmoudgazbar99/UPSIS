USE ecole_sante_gabes;

ALTER TABLE finance
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL AFTER created_at;
