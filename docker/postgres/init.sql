-- Runs once when the Postgres container is first created.
-- ledgerly_owner (the POSTGRES_USER) owns the schema and runs migrations.
-- ledgerly_web is what the API connects as: a member of ledgerly_app, NOT the owner, so RLS applies.

CREATE ROLE ledgerly_app NOLOGIN;
CREATE ROLE ledgerly_web LOGIN PASSWORD 'ledgerly_web' IN ROLE ledgerly_app;
GRANT CONNECT ON DATABASE ledgerly TO ledgerly_app;

-- The application login is an ordinary role: no superuser, no BYPASSRLS, no CREATEDB, no CREATEROLE.
-- A runaway or hostile query cannot hold the database: it is stopped after 30 seconds.
ALTER ROLE ledgerly_web SET statement_timeout = '30s';
ALTER ROLE ledgerly_web SET lock_timeout = '10s';
ALTER ROLE ledgerly_web SET idle_in_transaction_session_timeout = '60s';
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO ledgerly_app;

-- No default table privileges on purpose: the schema grants each table explicitly (007_rls.sql), because
-- partitions must NOT be readable directly (row-level security is applied on the parent table only).
-- A later migration that adds a table must grant it to ledgerly_app itself.
ALTER DEFAULT PRIVILEGES FOR ROLE ledgerly_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO ledgerly_app;

CREATE DATABASE ledgerly_test OWNER ledgerly_owner;
