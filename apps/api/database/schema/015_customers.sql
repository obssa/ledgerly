-- Ledgerly core schema, part 15: customers (Module 2).
--
-- A contact is a customer, a supplier or both. This adds what the customer side needs: a credit limit (a warning,
-- not a block: product rule), tax exemption with its reason, a delivery address, and archiving. Contacts are
-- archived, never deleted: documents, payments and the ledger point to them.

ALTER TABLE contacts
    ADD COLUMN credit_limit       numeric(20,2) CHECK (credit_limit >= 0),
    ADD COLUMN tax_exempt         boolean       NOT NULL DEFAULT false,
    ADD COLUMN tax_exempt_reason  text          CHECK (length(tax_exempt_reason) <= 300),
    ADD COLUMN delivery_address   jsonb         NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN notes              text          CHECK (length(notes) <= 2000),
    ADD COLUMN is_active          boolean       NOT NULL DEFAULT true,
    ADD CONSTRAINT contacts_name_length CHECK (length(name) BETWEEN 1 AND 200),
    ADD CONSTRAINT contacts_exemption_has_reason CHECK (NOT tax_exempt OR tax_exempt_reason IS NOT NULL),
    ADD CONSTRAINT contacts_address_is_object CHECK (jsonb_typeof(address) = 'object' AND jsonb_typeof(delivery_address) = 'object');

REVOKE DELETE ON contacts FROM {{app_role}};
