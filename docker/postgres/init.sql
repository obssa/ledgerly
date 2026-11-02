-- Runs once when the Postgres container is first created.
-- ledgerly_owner (the POSTGRES_USER) owns the schema and runs migrations.
-- ledgerly_web is what the API connects as: a member of ledgerly_app, NOT the owner, so RLS applies.

CREATE ROLE ledgerly_app NOLOGIN;
CREATE ROLE ledgerly_web LOGIN PASSWORD 'ledgerly_web' IN ROLE ledgerly_app;
GRANT CONNECT ON DATABASE ledgerly TO ledgerly_app;
GRANT USAGE ON SCHEMA public TO ledgerly_app;

-- Tables created later by the owner (migrations) are granted automatically.
ALTER DEFAULT PRIVILEGES FOR ROLE ledgerly_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ledgerly_app;
ALTER DEFAULT PRIVILEGES FOR ROLE ledgerly_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO ledgerly_app;

CREATE DATABASE ledgerly_test OWNER ledgerly_owner;
