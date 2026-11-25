-- Evenbooks core schema, part 28: bank reconciliation (Module 5).
--
-- A reconciliation agrees a money account's books with one bank statement: the statement's date and ending balance, and
-- the entries on the account the statement shows (cleared). Rules here:
--   1. Each reconciliation starts from where the last completed one ended (0 for the first) and comes after it in time.
--      One draft per account at a time.
--   2. It completes only when the difference is zero: opening + cleared entries = the statement's ending balance, every
--      cleared entry dated on or before the statement date. Its report is saved with it. A completed reconciliation never
--      changes; only the latest one of an account can be undone, with a reason, and not while a later draft starts from it.
--   3. An entry is cleared at most once, and only on its own account.
--   4. A reconciled period is locked: nothing new posts to the account on or before the statement date of its latest
--      completed reconciliation. Undo the reconciliation to correct it.
--   5. Statement lines explained by cleared entries are 'reconciled' while the reconciliation stands, and go back to
--      matched or categorised when it is undone. The database does this, so the two never disagree.

CREATE TABLE reconciliations (
    id              uuid          NOT NULL DEFAULT gen_random_uuid(),
    company_id      uuid          NOT NULL REFERENCES companies (id),
    account_id      uuid          NOT NULL REFERENCES accounts (id),
    statement_date  date          NOT NULL,
    opening_balance numeric(20,2) NOT NULL,
    ending_balance  numeric(20,2) NOT NULL,
    status          text          NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'completed', 'undone')),
    report          jsonb,
    created_by      uuid          REFERENCES users (id),
    created_at      timestamptz   NOT NULL DEFAULT now(),
    completed_by    uuid          REFERENCES users (id),
    completed_at    timestamptz,
    undone_by       uuid          REFERENCES users (id),
    undone_at       timestamptz,
    undo_reason     text          CHECK (length(undo_reason) BETWEEN 3 AND 300),
    PRIMARY KEY (company_id, id),
    CHECK (status = 'draft' OR (report IS NOT NULL AND completed_at IS NOT NULL)),
    CHECK ((status = 'undone') = (undo_reason IS NOT NULL AND undone_at IS NOT NULL))
);
CREATE UNIQUE INDEX reconciliations_one_draft ON reconciliations (company_id, account_id) WHERE status = 'draft';
CREATE INDEX reconciliations_account_idx ON reconciliations (company_id, account_id, status, statement_date);

CREATE TABLE reconciliation_lines (
    company_id        uuid NOT NULL REFERENCES companies (id),
    reconciliation_id uuid NOT NULL,
    journal_line_id   uuid NOT NULL,
    PRIMARY KEY (company_id, journal_line_id),   -- cleared once
    FOREIGN KEY (company_id, reconciliation_id) REFERENCES reconciliations (company_id, id),
    FOREIGN KEY (company_id, journal_line_id) REFERENCES journal_lines (company_id, id)
);
CREATE INDEX reconciliation_lines_reconciliation_idx ON reconciliation_lines (company_id, reconciliation_id);

-- The amount of a journal line in its account's own currency (+ in, - out): base for base-currency accounts.
CREATE FUNCTION reconciliation_cleared_total(p_company uuid, p_reconciliation uuid) RETURNS numeric
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
    SELECT coalesce(sum(CASE WHEN nullif(a.currency, c.base_currency) IS NULL THEN jl.debit - jl.credit ELSE jl.amount_txn END), 0)
      FROM reconciliation_lines rl
      JOIN journal_lines jl ON jl.company_id = rl.company_id AND jl.id = rl.journal_line_id
      JOIN accounts a ON a.id = jl.account_id
      JOIN companies c ON c.id = rl.company_id
     WHERE rl.company_id = p_company AND rl.reconciliation_id = p_reconciliation;
$$;
REVOKE ALL ON FUNCTION reconciliation_cleared_total(uuid, uuid) FROM PUBLIC;

CREATE FUNCTION reconciliations_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    acc record;
    last_done record;
    cleared numeric;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' THEN
            RAISE EXCEPTION 'A completed reconciliation is never deleted: undo it, with a reason' USING ERRCODE = 'check_violation';
        END IF;
        DELETE FROM reconciliation_lines WHERE company_id = OLD.company_id AND reconciliation_id = OLD.id;
        RETURN OLD;
    END IF;

    IF TG_OP = 'UPDATE' AND OLD.status <> 'draft' THEN
        -- Completed: only undone, once, keeping everything it said.
        IF OLD.status = 'undone' OR NEW.status <> 'undone'
           OR (NEW.company_id, NEW.account_id, NEW.statement_date, NEW.opening_balance, NEW.ending_balance, NEW.report, NEW.completed_at, NEW.completed_by)
              IS DISTINCT FROM (OLD.company_id, OLD.account_id, OLD.statement_date, OLD.opening_balance, OLD.ending_balance, OLD.report, OLD.completed_at, OLD.completed_by) THEN
            RAISE EXCEPTION 'A completed reconciliation does not change: undo it, with a reason' USING ERRCODE = 'check_violation';
        END IF;
        IF EXISTS (SELECT 1 FROM reconciliations r WHERE r.company_id = OLD.company_id AND r.account_id = OLD.account_id
                     AND r.status = 'completed' AND r.statement_date > OLD.statement_date) THEN
            RAISE EXCEPTION 'Undo the later reconciliations of this account first' USING ERRCODE = 'check_violation';
        END IF;
        IF EXISTS (SELECT 1 FROM reconciliations r WHERE r.company_id = OLD.company_id AND r.account_id = OLD.account_id AND r.status = 'draft') THEN
            RAISE EXCEPTION 'Discard the reconciliation in progress first: it starts from this one' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' AND (NEW.company_id, NEW.account_id, NEW.opening_balance) IS DISTINCT FROM (OLD.company_id, OLD.account_id, OLD.opening_balance) THEN
        RAISE EXCEPTION 'A reconciliation keeps its account and opening balance' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status = 'undone' THEN
        RAISE EXCEPTION 'A draft is discarded, not undone' USING ERRCODE = 'check_violation';
    END IF;

    SELECT a.company_id, a.subtype, a.system_key INTO acc FROM accounts a WHERE a.id = NEW.account_id;
    IF acc.company_id IS DISTINCT FROM NEW.company_id OR coalesce(acc.subtype, '') NOT IN ('bank', 'mobile_money', 'cash')
       OR acc.system_key IS NOT DISTINCT FROM 'undeposited_funds' THEN
        RAISE EXCEPTION 'Only the company''s bank, wallet and cash accounts are reconciled' USING ERRCODE = 'check_violation';
    END IF;
    SELECT statement_date, ending_balance INTO last_done FROM reconciliations r
     WHERE r.company_id = NEW.company_id AND r.account_id = NEW.account_id AND r.status = 'completed' AND r.id <> NEW.id
     ORDER BY statement_date DESC LIMIT 1;
    IF TG_OP = 'INSERT' AND NEW.opening_balance <> coalesce(last_done.ending_balance, 0) THEN
        RAISE EXCEPTION 'A reconciliation starts from the ending balance of the last one (%)', coalesce(last_done.ending_balance, 0)
            USING ERRCODE = 'check_violation';
    END IF;
    IF last_done.statement_date IS NOT NULL AND NEW.statement_date <= last_done.statement_date THEN
        RAISE EXCEPTION 'This account is reconciled through %: the next statement ends after that', last_done.statement_date
            USING ERRCODE = 'check_violation';
    END IF;

    IF NEW.status = 'completed' THEN
        IF EXISTS (SELECT 1 FROM reconciliation_lines rl JOIN journal_lines jl ON jl.company_id = rl.company_id AND jl.id = rl.journal_line_id
                    WHERE rl.company_id = NEW.company_id AND rl.reconciliation_id = NEW.id AND jl.entry_date > NEW.statement_date) THEN
            RAISE EXCEPTION 'An entry dated after the statement cannot be on it' USING ERRCODE = 'check_violation';
        END IF;
        cleared := reconciliation_cleared_total(NEW.company_id, NEW.id);
        IF NEW.opening_balance + cleared <> NEW.ending_balance THEN
            RAISE EXCEPTION 'The difference is % : it must be zero to finish', NEW.ending_balance - NEW.opening_balance - cleared
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER reconciliations_valid BEFORE INSERT OR UPDATE OR DELETE ON reconciliations
    FOR EACH ROW EXECUTE FUNCTION reconciliations_valid();

-- Completing marks the statement lines reconciled; undoing puts them back and releases the cleared entries.
CREATE FUNCTION reconciliations_follow() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.status = 'completed' AND OLD.status = 'draft' THEN
        UPDATE bank_transactions bt SET status = 'reconciled'
          FROM reconciliation_lines rl
         WHERE rl.company_id = NEW.company_id AND rl.reconciliation_id = NEW.id
           AND bt.company_id = rl.company_id AND bt.journal_line_id = rl.journal_line_id AND bt.status IN ('matched', 'categorised');
    ELSIF NEW.status = 'undone' AND OLD.status = 'completed' THEN
        UPDATE bank_transactions bt
           SET status = CASE WHEN EXISTS (SELECT 1 FROM journal_lines jl JOIN journal_entries e ON e.company_id = jl.company_id AND e.id = jl.journal_entry_id
                                           WHERE jl.company_id = bt.company_id AND jl.id = bt.journal_line_id
                                             AND e.source_type = 'bank_transaction' AND e.source_id = bt.id)
                             THEN 'categorised' ELSE 'matched' END
          FROM reconciliation_lines rl
         WHERE rl.company_id = NEW.company_id AND rl.reconciliation_id = NEW.id
           AND bt.company_id = rl.company_id AND bt.journal_line_id = rl.journal_line_id AND bt.status = 'reconciled';
        DELETE FROM reconciliation_lines WHERE company_id = NEW.company_id AND reconciliation_id = NEW.id;
    END IF;
    RETURN NULL;
END $$;

CREATE TRIGGER reconciliations_follow AFTER UPDATE OF status ON reconciliations
    FOR EACH ROW EXECUTE FUNCTION reconciliations_follow();

CREATE FUNCTION reconciliation_lines_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    rec record;
    jl record;
BEGIN
    SELECT account_id, statement_date, status INTO rec FROM reconciliations
     WHERE company_id = coalesce(NEW.company_id, OLD.company_id) AND id = coalesce(NEW.reconciliation_id, OLD.reconciliation_id);
    IF TG_OP = 'DELETE' THEN
        -- Unticked in a draft; the triggers above release the entries of a discarded or undone reconciliation.
        IF rec.status = 'completed' THEN
            RAISE EXCEPTION 'A completed reconciliation keeps its entries: undo it, with a reason' USING ERRCODE = 'check_violation';
        END IF;
        RETURN OLD;
    END IF;
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'A cleared entry is unticked and ticked again, not changed' USING ERRCODE = 'check_violation';
    END IF;
    IF rec.status IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'Entries are ticked only on a reconciliation in progress' USING ERRCODE = 'check_violation';
    END IF;
    SELECT account_id, entry_date INTO jl FROM journal_lines WHERE company_id = NEW.company_id AND id = NEW.journal_line_id;
    IF NOT FOUND OR jl.account_id <> rec.account_id THEN
        RAISE EXCEPTION 'Only entries on the account being reconciled are ticked' USING ERRCODE = 'check_violation';
    END IF;
    IF jl.entry_date > rec.statement_date THEN
        RAISE EXCEPTION 'An entry dated after the statement cannot be on it' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER reconciliation_lines_valid BEFORE INSERT OR UPDATE OR DELETE ON reconciliation_lines
    FOR EACH ROW EXECUTE FUNCTION reconciliation_lines_valid();

-- The reconciled period is locked. Named to fire after journal_lines_same_company, which sets entry_date.
CREATE FUNCTION journal_lines_unreconciled_dates() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    through date;
BEGIN
    SELECT max(statement_date) INTO through FROM reconciliations
     WHERE company_id = NEW.company_id AND account_id = NEW.account_id AND status = 'completed';
    IF through IS NOT NULL AND NEW.entry_date <= through THEN
        RAISE EXCEPTION 'This account is reconciled through %: date the entry after that, or undo the reconciliation', through
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_lines_unreconciled_dates BEFORE INSERT ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION journal_lines_unreconciled_dates();

ALTER TABLE reconciliations ENABLE ROW LEVEL SECURITY;
ALTER TABLE reconciliation_lines ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON reconciliations
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
CREATE POLICY tenant_isolation ON reconciliation_lines
    USING (company_id = (SELECT app_company_id())) WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON reconciliations, reconciliation_lines FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON reconciliations TO {{app_role}};
GRANT SELECT, INSERT, DELETE ON reconciliation_lines TO {{app_role}};
