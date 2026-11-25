-- Evenbooks core schema, part 76: bank rules (Module 5).
--
-- A bank rule says how a kind of statement line is categorised: the bank charge every month, the rent, the mobile-money
-- fee. A line to review that the rule fits (its words are in the line's description, in the direction and amount range
-- given, on the account given) is suggested the rule's account, contact and memo, and categorised by it when the person
-- applies the rules: each line through the same entry as categorising it by hand (027). A rule posts nothing itself.
-- Rules:
--   1. A rule's accounts and contact are the company's own: the bank account it is for (if any) a bank, cash or
--      mobile-money account, the account it categorises to another active account; its words are not empty.
--   2. An amount range has its smallest no larger than its largest, both above zero (the size of the line, in or out).

CREATE TABLE bank_rules (
    id                uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id        uuid          NOT NULL REFERENCES companies (id),
    name              text          NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 120),
    bank_account_id   uuid          REFERENCES accounts (id),   -- none: any of the company's money accounts
    direction         text          NOT NULL DEFAULT 'any' CHECK (direction IN ('in', 'out', 'any')),
    contains          text          NOT NULL CHECK (length(btrim(contains)) BETWEEN 1 AND 120),
    amount_min        numeric(20,2) CHECK (amount_min > 0),
    amount_max        numeric(20,2) CHECK (amount_max > 0),
    target_account_id uuid          NOT NULL REFERENCES accounts (id),
    contact_id        uuid          REFERENCES contacts (id),
    memo              text          CHECK (length(memo) <= 255),
    priority          integer       NOT NULL DEFAULT 100 CHECK (priority BETWEEN 0 AND 1000),
    is_active         boolean       NOT NULL DEFAULT true,
    created_by        uuid          REFERENCES users (id),
    created_at        timestamptz   NOT NULL DEFAULT now(),
    updated_at        timestamptz   NOT NULL DEFAULT now(),
    CHECK (amount_min IS NULL OR amount_max IS NULL OR amount_min <= amount_max),
    CHECK (bank_account_id IS NULL OR bank_account_id <> target_account_id)
);
CREATE INDEX bank_rules_company ON bank_rules (company_id, priority);

-- Rule 1.
CREATE FUNCTION bank_rules_valid() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.bank_account_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM accounts WHERE id = NEW.bank_account_id AND company_id = NEW.company_id
                                                          AND subtype IN ('bank', 'cash', 'mobile_money')) THEN
        RAISE EXCEPTION 'A bank rule is for one of the company''s bank, cash or mobile-money accounts' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM accounts WHERE id = NEW.target_account_id AND company_id = NEW.company_id AND is_active) THEN
        RAISE EXCEPTION 'A bank rule categorises to an active account of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.contact_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM contacts WHERE id = NEW.contact_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A bank rule''s contact is one of the company''s customers or suppliers' USING ERRCODE = 'check_violation';
    END IF;
    NEW.updated_at := now();
    RETURN NEW;
END $$;
CREATE TRIGGER bank_rules_valid BEFORE INSERT OR UPDATE ON bank_rules FOR EACH ROW EXECUTE FUNCTION bank_rules_valid();

ALTER TABLE bank_rules ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON bank_rules
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON bank_rules FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON bank_rules TO {{app_role}};
