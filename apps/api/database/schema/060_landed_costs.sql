-- Ledgerly core schema, part 60: landed costs (Module 4).
--
-- A bill for freight, customs duty or clearing (the cost bill) is added to the cost of the goods another bill brought in
-- (the goods bill): its amount is spread over the goods bill's stock lines, by their value or their quantity. The share
-- of the goods still on hand raises their value (a landed_cost stock move: value only, no quantity); the share of goods
-- already sold goes to cost of goods sold. The entry takes the cost off the account the cost bill used. Rules:
--   1. A landed-cost move adds value only (no quantity, an amount above zero) to stock still on hand.
--   2. A landed cost joins two posted bills of the company, never one bill to itself, in an entry of its own.
--   3. A cost bill is never spread beyond its amount before tax (in the base currency).
--   4. Landed costs are kept: never changed or deleted.

ALTER TABLE stock_moves DROP CONSTRAINT stock_moves_kind_check;
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_kind_check CHECK (kind IN ('opening', 'purchase', 'sale', 'sale_return', 'purchase_return',
    'transfer_in', 'transfer_out', 'adjustment', 'production', 'project_issue', 'landed_cost'));
ALTER TABLE stock_moves DROP CONSTRAINT stock_moves_quantity_check;
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_quantity_check CHECK (quantity <> 0 OR kind = 'landed_cost');
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_landed_cost_value_only CHECK (kind <> 'landed_cost' OR quantity = 0);

-- 024's valuation, with the landed-cost move: value onto what is on hand, the average rising with it.
CREATE OR REPLACE FUNCTION stock_moves_cost() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
DECLARE
    it record;
    bal record;
    allow_negative boolean;
BEGIN
    SELECT type, company_id INTO it FROM items WHERE id = NEW.item_id;
    IF it.company_id IS DISTINCT FROM NEW.company_id OR NOT EXISTS (SELECT 1 FROM warehouses WHERE id = NEW.warehouse_id AND company_id = NEW.company_id) THEN
        RAISE EXCEPTION 'A stock move names the company''s own item and warehouse' USING ERRCODE = 'foreign_key_violation';
    END IF;
    IF it.type <> 'inventory' THEN
        RAISE EXCEPTION 'Only stock items have stock moves' USING ERRCODE = 'check_violation';
    END IF;

    INSERT INTO stock_balances (company_id, item_id, warehouse_id) VALUES (NEW.company_id, NEW.item_id, NEW.warehouse_id)
        ON CONFLICT DO NOTHING;
    SELECT quantity, value INTO bal FROM stock_balances
     WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id FOR UPDATE;

    IF NEW.kind = 'landed_cost' THEN
        IF NEW.quantity <> 0 OR NEW.amount IS NULL OR NEW.amount <= 0 THEN
            RAISE EXCEPTION 'A landed cost adds a value above zero, and no quantity' USING ERRCODE = 'check_violation';
        END IF;
        IF bal.quantity <= 0 THEN
            RAISE EXCEPTION 'A landed cost goes onto stock still on hand' USING ERRCODE = 'check_violation';
        END IF;
        NEW.unit_cost := 0;
    ELSIF NEW.quantity > 0 THEN
        NEW.amount := coalesce(NEW.amount, round(NEW.quantity * NEW.unit_cost, 2));
        IF NEW.amount < 0 THEN
            RAISE EXCEPTION 'Stock coming in has a value of zero or more' USING ERRCODE = 'check_violation';
        END IF;
    ELSE
        SELECT coalesce((settings -> 'inventory' ->> 'allow_negative')::boolean, false) INTO allow_negative FROM companies WHERE id = NEW.company_id;
        IF bal.quantity + NEW.quantity < 0 AND NOT allow_negative THEN
            RAISE EXCEPTION 'Not enough stock: % on hand, % going out', bal.quantity, -NEW.quantity USING ERRCODE = 'check_violation';
        END IF;
        -- Weighted average. Emptying the warehouse takes all its value; going below zero takes the last known average.
        NEW.amount := CASE
            WHEN bal.quantity + NEW.quantity = 0 THEN -bal.value
            WHEN bal.quantity > 0 THEN round(NEW.quantity * bal.value / bal.quantity, 2)
            ELSE round(NEW.quantity * NEW.unit_cost, 2) END;
        NEW.unit_cost := CASE WHEN NEW.quantity = 0 THEN 0 ELSE round(NEW.amount / NEW.quantity, 6) END;
    END IF;

    UPDATE stock_balances SET quantity = quantity + NEW.quantity, value = value + NEW.amount, updated_at = now()
     WHERE company_id = NEW.company_id AND item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id;
    RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION stock_moves_cost() FROM PUBLIC;

CREATE TABLE landed_costs (
    id               uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id       uuid          NOT NULL REFERENCES companies (id),
    cost_bill_id     uuid          NOT NULL REFERENCES documents (id),
    goods_bill_id    uuid          NOT NULL REFERENCES documents (id),
    amount           numeric(20,2) NOT NULL CHECK (amount > 0),   -- in the base currency
    method           text          NOT NULL CHECK (method IN ('value', 'quantity')),
    applied_on       date          NOT NULL,
    journal_entry_id uuid          NOT NULL,
    created_by       uuid          NOT NULL REFERENCES users (id),
    created_at       timestamptz   NOT NULL DEFAULT now(),
    CHECK (cost_bill_id <> goods_bill_id),
    FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id)
);
CREATE INDEX landed_costs_cost_bill ON landed_costs (company_id, cost_bill_id);
CREATE INDEX landed_costs_goods_bill ON landed_costs (company_id, goods_bill_id);

CREATE FUNCTION landed_costs_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    cost   record;
    spread numeric;
BEGIN
    SELECT type, status, subtotal, fx_rate INTO cost FROM documents WHERE id = NEW.cost_bill_id AND company_id = NEW.company_id FOR UPDATE;
    IF NOT FOUND OR cost.type <> 'bill' OR cost.status NOT IN ('posted', 'part_paid', 'paid')
       OR NOT EXISTS (SELECT 1 FROM documents WHERE id = NEW.goods_bill_id AND company_id = NEW.company_id AND type = 'bill'
                                                AND status IN ('posted', 'part_paid', 'paid')) THEN
        RAISE EXCEPTION 'A landed cost joins two posted bills of the company' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.journal_entry_id AND source_type = 'landed_cost') THEN
        RAISE EXCEPTION 'A landed cost is posted in an entry of its own' USING ERRCODE = 'check_violation';
    END IF;
    SELECT coalesce(sum(amount), 0) INTO spread FROM landed_costs WHERE company_id = NEW.company_id AND cost_bill_id = NEW.cost_bill_id;
    IF spread + NEW.amount > round(cost.subtotal * cost.fx_rate, 2) THEN
        RAISE EXCEPTION 'A cost bill is spread no further than its amount before tax (% already, % of %)', spread, NEW.amount, round(cost.subtotal * cost.fx_rate, 2)
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER landed_costs_valid BEFORE INSERT ON landed_costs FOR EACH ROW EXECUTE FUNCTION landed_costs_valid();

ALTER TABLE landed_costs ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON landed_costs
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON landed_costs FROM {{app_role}};
GRANT SELECT, INSERT ON landed_costs TO {{app_role}};
