-- Ledgerly core schema, part 55: stock issued to a project (Module 9a, job costing).
--
-- Materials taken from a warehouse for a project leave stock at their cost and become a cost of that project: the move
-- names the project, and the posting tags the cost line with it, so the project's profit counts them. Rules here:
--   1. A move of kind 'project_issue' takes stock out (a negative quantity) and names a project of its own company.
--   2. No other kind of move names a project.

ALTER TABLE stock_moves DROP CONSTRAINT stock_moves_kind_check;
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_kind_check CHECK (kind IN ('opening', 'purchase', 'sale', 'sale_return', 'purchase_return',
    'transfer_in', 'transfer_out', 'adjustment', 'production', 'project_issue'));
ALTER TABLE stock_moves ADD COLUMN project_id uuid;

CREATE FUNCTION stock_moves_project_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.kind = 'project_issue' THEN
        IF NEW.quantity >= 0 OR NEW.project_id IS NULL OR NOT dimension_belongs(NEW.company_id, 'project', NEW.project_id) THEN
            RAISE EXCEPTION 'Stock is issued out of a warehouse to a project of the company' USING ERRCODE = 'check_violation';
        END IF;
    ELSIF NEW.project_id IS NOT NULL THEN
        RAISE EXCEPTION 'Only stock issued to a project names one' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER stock_moves_project_valid BEFORE INSERT ON stock_moves
    FOR EACH ROW EXECUTE FUNCTION stock_moves_project_valid();
