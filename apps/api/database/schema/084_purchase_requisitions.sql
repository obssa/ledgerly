-- Evenbooks core schema, part 84: purchase requisitions (Module 3).
--
-- A member asks for something to be bought: what, how many, roughly at what price, by when and why. Someone who
-- approves bills approves or rejects it (never the person who asked), and someone who enters bills turns an approved
-- one into a draft purchase order to the supplier they choose. Nothing posts. Rules:
--   1. A requisition is asked for by a member of the company, with a reason and one line or more; what was asked for,
--      and by whom, never changes.
--   2. It moves requested -> approved, rejected or withdrawn; approved -> ordered. Nothing else.
--   3. It is approved or rejected by someone other than the person who asked, who is the one recorded; it is withdrawn
--      only by the person who asked.
--   4. Ordered, it names the purchase order of the company it became.

CREATE TABLE purchase_requisitions (
    id                uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id        uuid          NOT NULL REFERENCES companies (id),
    requested_by      uuid          NOT NULL REFERENCES users (id),
    reason            text          NOT NULL CHECK (length(btrim(reason)) BETWEEN 3 AND 500),
    needed_by         date,
    lines             jsonb         NOT NULL CHECK (jsonb_typeof(lines) = 'array' AND jsonb_array_length(lines) BETWEEN 1 AND 100),
    status            text          NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'approved', 'rejected', 'withdrawn', 'ordered')),
    decided_by        uuid          REFERENCES users (id),
    decided_at        timestamptz,
    decision_note     text          CHECK (length(decision_note) <= 500),
    purchase_order_id uuid          REFERENCES documents (id),
    created_at        timestamptz   NOT NULL DEFAULT now(),
    updated_at        timestamptz   NOT NULL DEFAULT now(),
    CHECK ((status = 'ordered') = (purchase_order_id IS NOT NULL)),
    CHECK (status NOT IN ('approved', 'rejected', 'ordered') OR decided_by IS NOT NULL)
);
CREATE INDEX purchase_requisitions_status ON purchase_requisitions (company_id, status, created_at);

CREATE FUNCTION purchase_requisitions_valid() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        -- Rule 1.
        IF NEW.status <> 'requested' OR NOT EXISTS (SELECT 1 FROM memberships WHERE company_id = NEW.company_id AND user_id = NEW.requested_by AND status = 'active')
           OR (app_user_id() IS NOT NULL AND NEW.requested_by <> app_user_id()) THEN
            RAISE EXCEPTION 'A requisition is asked for by the member asking' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.requested_by IS DISTINCT FROM OLD.requested_by OR NEW.lines IS DISTINCT FROM OLD.lines OR NEW.reason IS DISTINCT FROM OLD.reason
       OR NEW.needed_by IS DISTINCT FROM OLD.needed_by OR NEW.company_id IS DISTINCT FROM OLD.company_id THEN
        RAISE EXCEPTION 'What a requisition asked for never changes' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status IS DISTINCT FROM OLD.status THEN
        -- Rule 2.
        IF NOT ((OLD.status = 'requested' AND NEW.status IN ('approved', 'rejected', 'withdrawn')) OR (OLD.status = 'approved' AND NEW.status = 'ordered')) THEN
            RAISE EXCEPTION 'A requisition does not go from % to %', OLD.status, NEW.status USING ERRCODE = 'check_violation';
        END IF;
        -- Rule 3.
        IF NEW.status IN ('approved', 'rejected') AND (NEW.decided_by IS NULL OR NEW.decided_by = NEW.requested_by
           OR (app_user_id() IS NOT NULL AND NEW.decided_by <> app_user_id())) THEN
            RAISE EXCEPTION 'A requisition is approved or rejected by someone other than the person who asked' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.status = 'withdrawn' AND app_user_id() IS NOT NULL AND app_user_id() <> NEW.requested_by THEN
            RAISE EXCEPTION 'A requisition is withdrawn by the person who asked' USING ERRCODE = 'check_violation';
        END IF;
        -- Rule 4.
        IF NEW.status = 'ordered' AND NOT EXISTS (SELECT 1 FROM documents WHERE id = NEW.purchase_order_id AND company_id = NEW.company_id AND type = 'purchase_order') THEN
            RAISE EXCEPTION 'An ordered requisition names the purchase order of the company it became' USING ERRCODE = 'check_violation';
        END IF;
    ELSIF NEW.decided_by IS DISTINCT FROM OLD.decided_by OR NEW.purchase_order_id IS DISTINCT FROM OLD.purchase_order_id THEN
        RAISE EXCEPTION 'A requisition''s decision and order change only with its status' USING ERRCODE = 'check_violation';
    END IF;
    NEW.updated_at := now();
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION purchase_requisitions_valid() FROM PUBLIC;
CREATE TRIGGER purchase_requisitions_valid BEFORE INSERT OR UPDATE ON purchase_requisitions
    FOR EACH ROW EXECUTE FUNCTION purchase_requisitions_valid();

ALTER TABLE purchase_requisitions ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON purchase_requisitions
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON purchase_requisitions FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON purchase_requisitions TO {{app_role}};
