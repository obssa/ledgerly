-- Ledgerly core schema, part 71: delivery notes (Module 2).
--
-- A delivery note says what of a confirmed sales order left for the customer, and when. Its stock lines leave the
-- warehouse that day at their cost (weighted average or FIFO, decided by the database), and the cost of goods sold is
-- recorded then, when the customer takes the goods (Dr Cost of goods sold, Cr Inventory). The invoice for those goods,
-- when it is made from the order, bills them without moving the stock again: each of its stock lines is matched to what
-- was delivered and not yet invoiced. It is the sales side of 069's goods received note. Rules:
--   1. A delivery note is of a confirmed sales order of the company, from the company's warehouse; it is kept, and only
--      ever voided once, with the reason.
--   2. A delivery note line delivers a stock line of that order, the same item, a positive quantity; the live notes
--      never deliver more of an order line than was ordered.
--   3. An item of an order leaves one way: on delivery notes, or on the order's invoices. Once a live invoice of the
--      order took it out, it is not delivered on a note; once it is delivered, the order's invoices do not take it out.
--   4. A match names an invoice made from the order and its line for that order line; the live invoices never match
--      more of an order line than the live notes delivered. A note whose goods are invoiced is not voided.
--   5. An order with live delivery notes is not cancelled.
--   6. Delivery note lines and matches are kept: never changed or deleted.

CREATE TABLE delivery_notes (
    id               uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id       uuid        NOT NULL REFERENCES companies (id),
    sales_order_id   uuid        NOT NULL REFERENCES documents (id),
    number           text        NOT NULL,
    delivered_on     date        NOT NULL,
    warehouse_id     uuid        NOT NULL REFERENCES warehouses (id),
    reference        text        CHECK (length(reference) <= 100),   -- the customer's order or the waybill
    note             text        CHECK (length(note) <= 500),
    status           text        NOT NULL DEFAULT 'posted' CHECK (status IN ('posted', 'void')),
    journal_entry_id uuid,
    void_reason      text        CHECK (length(void_reason) <= 500),
    voided_by        uuid        REFERENCES users (id),
    voided_at        timestamptz,
    created_by       uuid        NOT NULL REFERENCES users (id),
    created_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (company_id, number),
    UNIQUE (company_id, id),
    FOREIGN KEY (company_id, journal_entry_id) REFERENCES journal_entries (company_id, id),
    CHECK ((status = 'void') = (voided_at IS NOT NULL))
);
CREATE INDEX delivery_notes_order ON delivery_notes (company_id, sales_order_id);

-- A delivery note's own moves (out, and back in on its void) name the note, not a document.
ALTER TABLE stock_moves ADD COLUMN delivery_note_id uuid;
ALTER TABLE stock_moves DROP CONSTRAINT stock_moves_one_source;
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_one_source
    CHECK (num_nonnulls(document_id, goods_receipt_id, delivery_note_id) <= 1);
ALTER TABLE stock_moves ADD CONSTRAINT stock_moves_delivery_note FOREIGN KEY (company_id, delivery_note_id) REFERENCES delivery_notes (company_id, id);
CREATE INDEX stock_moves_delivery_note_idx ON stock_moves (company_id, delivery_note_id) WHERE delivery_note_id IS NOT NULL;

CREATE TABLE delivery_note_lines (
    id               uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id       uuid          NOT NULL REFERENCES companies (id),
    delivery_note_id uuid          NOT NULL REFERENCES delivery_notes (id),
    order_line_id    uuid          NOT NULL REFERENCES document_lines (id),
    item_id          uuid          NOT NULL REFERENCES items (id),
    quantity         numeric(18,2) NOT NULL CHECK (quantity > 0)
);
CREATE INDEX delivery_note_lines_note ON delivery_note_lines (company_id, delivery_note_id);
CREATE INDEX delivery_note_lines_order_line ON delivery_note_lines (company_id, order_line_id);

CREATE TABLE invoice_delivery_matches (
    id              uuid          PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id      uuid          NOT NULL REFERENCES companies (id),
    invoice_id      uuid          NOT NULL REFERENCES documents (id),
    invoice_line_id uuid          NOT NULL REFERENCES document_lines (id),
    order_line_id   uuid          NOT NULL REFERENCES document_lines (id),
    quantity        numeric(18,2) NOT NULL CHECK (quantity > 0),
    created_at      timestamptz   NOT NULL DEFAULT now()
);
CREATE INDEX invoice_delivery_matches_invoice ON invoice_delivery_matches (company_id, invoice_id);
CREATE INDEX invoice_delivery_matches_order_line ON invoice_delivery_matches (company_id, order_line_id);

-- Rule 1: a confirmed order of the company, the company's warehouse; then only the void, once.
CREATE FUNCTION delivery_notes_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    line record;
    delivered numeric;
    matched numeric;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'A delivery note is kept: void it instead' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'posted' THEN
            RAISE EXCEPTION 'A delivery note is recorded as posted' USING ERRCODE = 'check_violation';
        END IF;
        PERFORM 1 FROM documents WHERE id = NEW.sales_order_id AND company_id = NEW.company_id
                                   AND type = 'sales_order' AND status = 'accepted' FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'Goods are delivered against a confirmed sales order of the company' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM warehouses WHERE id = NEW.warehouse_id AND company_id = NEW.company_id) THEN
            RAISE EXCEPTION 'Goods leave from the company''s own warehouse' USING ERRCODE = 'foreign_key_violation';
        END IF;
        RETURN NEW;
    END IF;

    IF (NEW.id, NEW.company_id, NEW.sales_order_id, NEW.number, NEW.delivered_on, NEW.warehouse_id, NEW.reference, NEW.note, NEW.created_by, NEW.created_at)
       IS DISTINCT FROM (OLD.id, OLD.company_id, OLD.sales_order_id, OLD.number, OLD.delivered_on, OLD.warehouse_id, OLD.reference, OLD.note, OLD.created_by, OLD.created_at)
       OR (OLD.journal_entry_id IS NOT NULL AND NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id)
       OR OLD.status = 'void' THEN
        RAISE EXCEPTION 'A delivery note does not change: void it and record another' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status = 'void' THEN
        -- Rule 4: what its goods' invoices matched must still be delivered without it.
        FOR line IN SELECT DISTINCT order_line_id FROM delivery_note_lines WHERE company_id = NEW.company_id AND delivery_note_id = NEW.id LOOP
            PERFORM 1 FROM document_lines WHERE id = line.order_line_id FOR UPDATE;
            SELECT coalesce(sum(dl.quantity), 0) INTO delivered
              FROM delivery_note_lines dl JOIN delivery_notes d ON d.id = dl.delivery_note_id
             WHERE dl.company_id = NEW.company_id AND dl.order_line_id = line.order_line_id AND d.status = 'posted' AND d.id <> NEW.id;
            SELECT coalesce(sum(m.quantity), 0) INTO matched
              FROM invoice_delivery_matches m JOIN documents i ON i.id = m.invoice_id
             WHERE m.company_id = NEW.company_id AND m.order_line_id = line.order_line_id AND i.status <> 'void';
            IF matched > delivered THEN
                RAISE EXCEPTION 'The goods on this note are invoiced (% invoiced, % delivered without it): void the invoice first', matched, delivered
                    USING ERRCODE = 'check_violation';
            END IF;
        END LOOP;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER delivery_notes_valid BEFORE INSERT OR UPDATE OR DELETE ON delivery_notes FOR EACH ROW EXECUTE FUNCTION delivery_notes_valid();

-- Rules 2 and 3: a stock line of the note's order, never more than ordered, of an item the order's invoices did not take out.
CREATE FUNCTION delivery_note_lines_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    note record;
    ordered record;
    delivered numeric;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'A delivery note''s lines are kept: void the note instead' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT sales_order_id, status INTO note FROM delivery_notes WHERE id = NEW.delivery_note_id AND company_id = NEW.company_id;
    IF NOT FOUND OR note.status <> 'posted' THEN
        RAISE EXCEPTION 'A line is added to a posted delivery note of the company' USING ERRCODE = 'check_violation';
    END IF;
    SELECT l.document_id, l.item_id, l.quantity INTO ordered FROM document_lines l
     WHERE l.id = NEW.order_line_id AND l.company_id = NEW.company_id FOR UPDATE;
    IF NOT FOUND OR ordered.document_id <> note.sales_order_id THEN
        RAISE EXCEPTION 'A delivery note delivers lines of its own sales order' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.item_id IS DISTINCT FROM ordered.item_id
       OR NOT EXISTS (SELECT 1 FROM items WHERE id = NEW.item_id AND company_id = NEW.company_id AND type = 'inventory') THEN
        RAISE EXCEPTION 'A delivery note delivers the stock item of its order line' USING ERRCODE = 'check_violation';
    END IF;
    SELECT coalesce(sum(dl.quantity), 0) INTO delivered
      FROM delivery_note_lines dl JOIN delivery_notes d ON d.id = dl.delivery_note_id
     WHERE dl.company_id = NEW.company_id AND dl.order_line_id = NEW.order_line_id AND d.status = 'posted';
    IF delivered + NEW.quantity > ordered.quantity THEN
        RAISE EXCEPTION 'More would be delivered than was ordered (% ordered, % already delivered)', ordered.quantity, delivered
            USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (SELECT 1 FROM stock_moves m JOIN documents i ON i.id = m.document_id
                WHERE m.company_id = NEW.company_id AND m.kind = 'sale' AND m.item_id = NEW.item_id
                  AND i.type = 'invoice' AND i.status <> 'void' AND i.source_document_id = note.sales_order_id) THEN
        RAISE EXCEPTION 'An invoice of this order already took this item out: it is not delivered again' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER delivery_note_lines_valid BEFORE INSERT OR UPDATE OR DELETE ON delivery_note_lines FOR EACH ROW EXECUTE FUNCTION delivery_note_lines_valid();

-- Rule 4: a line of an invoice made from the order, for that order line, never beyond what the live notes delivered.
CREATE FUNCTION invoice_delivery_matches_valid() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    inv record;
    delivered numeric;
    matched numeric;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'An invoice''s match to goods delivered is kept: void the invoice instead' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT type, status, source_document_id INTO inv FROM documents WHERE id = NEW.invoice_id AND company_id = NEW.company_id;
    IF NOT FOUND OR inv.type <> 'invoice' OR inv.status = 'void'
       OR NOT EXISTS (SELECT 1 FROM document_lines WHERE id = NEW.invoice_line_id AND company_id = NEW.company_id
                                                     AND document_id = NEW.invoice_id AND source_line_id = NEW.order_line_id)
       OR NOT EXISTS (SELECT 1 FROM document_lines WHERE id = NEW.order_line_id AND company_id = NEW.company_id
                                                     AND document_id = inv.source_document_id) THEN
        RAISE EXCEPTION 'A match names a line of an invoice made from the order, for that order line' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM 1 FROM document_lines WHERE id = NEW.order_line_id FOR UPDATE;
    SELECT coalesce(sum(dl.quantity), 0) INTO delivered
      FROM delivery_note_lines dl JOIN delivery_notes d ON d.id = dl.delivery_note_id
     WHERE dl.company_id = NEW.company_id AND dl.order_line_id = NEW.order_line_id AND d.status = 'posted';
    SELECT coalesce(sum(m.quantity), 0) INTO matched
      FROM invoice_delivery_matches m JOIN documents i ON i.id = m.invoice_id
     WHERE m.company_id = NEW.company_id AND m.order_line_id = NEW.order_line_id AND i.status <> 'void';
    IF matched + NEW.quantity > delivered THEN
        RAISE EXCEPTION 'More would be invoiced than was delivered (% delivered, % already invoiced)', delivered, matched
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER invoice_delivery_matches_valid BEFORE INSERT OR UPDATE OR DELETE ON invoice_delivery_matches FOR EACH ROW EXECUTE FUNCTION invoice_delivery_matches_valid();

-- Rule 3, the other way: an invoice made from an order does not take out an item the order's live notes delivered.
CREATE FUNCTION stock_moves_delivered_once() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.kind <> 'sale' OR NEW.document_id IS NULL OR NEW.quantity >= 0 THEN
        RETURN NEW;
    END IF;
    IF EXISTS (SELECT 1 FROM documents i
                 JOIN delivery_notes d ON d.company_id = i.company_id AND d.sales_order_id = i.source_document_id AND d.status = 'posted'
                 JOIN delivery_note_lines dl ON dl.delivery_note_id = d.id AND dl.item_id = NEW.item_id
                WHERE i.id = NEW.document_id AND i.company_id = NEW.company_id AND i.type = 'invoice') THEN
        RAISE EXCEPTION 'These goods left on a delivery note: the invoice bills them without moving them again' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER stock_moves_delivered_once BEFORE INSERT ON stock_moves FOR EACH ROW EXECUTE FUNCTION stock_moves_delivered_once();

-- Rule 5: an order with goods delivered is closed, not cancelled.
CREATE FUNCTION documents_delivered_not_cancelled() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.type = 'sales_order' AND NEW.status = 'void' AND OLD.status <> 'void'
       AND EXISTS (SELECT 1 FROM delivery_notes WHERE company_id = NEW.company_id AND sales_order_id = NEW.id AND status = 'posted') THEN
        RAISE EXCEPTION 'A sales order with goods delivered is closed, not cancelled' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER documents_delivered_not_cancelled BEFORE UPDATE OF status ON documents FOR EACH ROW EXECUTE FUNCTION documents_delivered_not_cancelled();

ALTER TABLE delivery_notes ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON delivery_notes
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON delivery_notes FROM {{app_role}};
GRANT SELECT, INSERT, UPDATE ON delivery_notes TO {{app_role}};

ALTER TABLE delivery_note_lines ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON delivery_note_lines
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON delivery_note_lines FROM {{app_role}};
GRANT SELECT, INSERT ON delivery_note_lines TO {{app_role}};

ALTER TABLE invoice_delivery_matches ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON invoice_delivery_matches
    USING (company_id = (SELECT app_company_id()))
    WITH CHECK (company_id = (SELECT app_company_id()));
REVOKE ALL ON invoice_delivery_matches FROM {{app_role}};
GRANT SELECT, INSERT ON invoice_delivery_matches TO {{app_role}};
