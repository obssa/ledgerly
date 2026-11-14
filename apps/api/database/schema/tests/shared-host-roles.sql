-- Recreates, on a plain PostgreSQL server, the role layout cPanel shared hosting gives an account
-- (checked on the production host, 14 Nov 2026, PostgreSQL 13):
--
--   cp_ledgerly_test   NOLOGIN role that owns the database (cPanel names it after the database)
--   cp_lowner          login, member of the database-owner role: runs migrations, owns the tables
--   cp_lweb            login, NOT a member of anything: the API. (cPanel's "grant all privileges" would make
--                      it a member; the deploy revokes that, and `ledgerly:check-database-roles` refuses to
--                      go live while it is.)
--   public schema      owned by postgres and writable by everyone (PostgreSQL 13 default), so the tables live
--                      in their own schema, DB_SCHEMA=ledgerly
--
-- Neither login is a superuser or can create roles. Run as a superuser on an empty server:
--   psql -v ON_ERROR_STOP=1 -f shared-host-roles.sql
CREATE ROLE cp_ledgerly_test NOLOGIN;
CREATE ROLE cp_lowner LOGIN PASSWORD 'cp_lowner' NOSUPERUSER NOCREATEROLE NOCREATEDB IN ROLE cp_ledgerly_test;
CREATE ROLE cp_lweb   LOGIN PASSWORD 'cp_lweb'   NOSUPERUSER NOCREATEROLE NOCREATEDB;
CREATE DATABASE cp_ledgerly_test OWNER cp_ledgerly_test;
