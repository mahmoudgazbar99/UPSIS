BEGIN;
DO $$
DECLARE row record;
BEGIN
  FOR row IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
    EXECUTE 'TRUNCATE TABLE public.' || quote_ident(row.tablename) || ' RESTART IDENTITY CASCADE';
  END LOOP;
END $$;
\i /dump.sql
COMMIT;
