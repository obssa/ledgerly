-- Ledgerly core schema, part 31: the chart of accounts (Module 6).
--
-- Accounts are added, renamed, re-coded, grouped under a parent and archived; never deleted, because the ledger names
-- them. Rules here (026 already keeps an account's type, kind and currency once it has entries):
--   1. A system account (one the posting code looks up by its key) keeps its key and stays active.
--   2. An account is archived only when it holds nothing, and only when no active account sits under it.
--   3. A sub-account sits under an account of the same company and the same type, never under itself or its own
--      sub-accounts, at most four levels deep.
--   4. A code is short and plain (letters, digits, dots, dashes); a name is not empty.

ALTER TABLE accounts
    ADD CONSTRAINT accounts_code_plain CHECK (code ~ '^[0-9A-Za-z.-]{1,20}$') NOT VALID,
    ADD CONSTRAINT accounts_name_given CHECK (length(trim(name)) BETWEEN 1 AND 120) NOT VALID;

CREATE FUNCTION accounts_chart_rules() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    parent record;
    cursor_id uuid;
    depth int := 1;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.system_key IS DISTINCT FROM OLD.system_key THEN
            RAISE EXCEPTION 'Account % is a system account: its key stays', OLD.code USING ERRCODE = 'check_violation';
        END IF;
        IF OLD.is_active AND NOT NEW.is_active THEN
            IF OLD.system_key IS NOT NULL THEN
                RAISE EXCEPTION 'Account % is a system account: it stays active', OLD.code USING ERRCODE = 'check_violation';
            END IF;
            IF EXISTS (SELECT 1 FROM account_balances WHERE company_id = OLD.company_id AND account_id = OLD.id
                        GROUP BY account_id HAVING sum(debit - credit) <> 0) THEN
                RAISE EXCEPTION 'Account % still holds a balance: move it out before archiving', OLD.code USING ERRCODE = 'check_violation';
            END IF;
            IF EXISTS (SELECT 1 FROM accounts WHERE company_id = OLD.company_id AND parent_id = OLD.id AND is_active) THEN
                RAISE EXCEPTION 'Account % has active sub-accounts: archive or move them first', OLD.code USING ERRCODE = 'check_violation';
            END IF;
        END IF;
        IF NEW.type IS DISTINCT FROM OLD.type AND EXISTS (SELECT 1 FROM accounts WHERE company_id = OLD.company_id AND parent_id = OLD.id) THEN
            RAISE EXCEPTION 'Account % has sub-accounts of its type: move them first', OLD.code USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    IF NEW.parent_id IS NOT NULL THEN
        SELECT company_id, type, code INTO parent FROM accounts WHERE id = NEW.parent_id;
        IF parent.company_id IS DISTINCT FROM NEW.company_id OR parent.type IS DISTINCT FROM NEW.type THEN
            RAISE EXCEPTION 'A sub-account sits under an account of the same company and type' USING ERRCODE = 'check_violation';
        END IF;
        cursor_id := NEW.parent_id;
        WHILE cursor_id IS NOT NULL LOOP
            IF cursor_id = NEW.id THEN
                RAISE EXCEPTION 'Account % cannot sit under itself or its own sub-accounts', NEW.code USING ERRCODE = 'check_violation';
            END IF;
            depth := depth + 1;
            IF depth > 4 THEN
                RAISE EXCEPTION 'Accounts go at most four levels deep' USING ERRCODE = 'check_violation';
            END IF;
            SELECT a.parent_id INTO cursor_id FROM accounts a WHERE a.id = cursor_id;
        END LOOP;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER accounts_chart_rules BEFORE INSERT OR UPDATE OF system_key, is_active, type, parent_id ON accounts
    FOR EACH ROW EXECUTE FUNCTION accounts_chart_rules();

REVOKE DELETE ON accounts FROM {{app_role}};
