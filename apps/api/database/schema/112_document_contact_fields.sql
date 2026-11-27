-- Evenbooks core schema, part 112: QuickBooks' invoice header and footer fields.
--
-- Who it goes to and where: the billing and shipping addresses as printed (filled from the customer, changed per
-- document), the email it is sent to with Cc and Bcc, how it ships (ship via, shipping date, tracking number), and a
-- message the customer's statement shows for it (the message on the invoice is notes, 003). Each is the document's
-- own and frozen with it once posted (005 compares the whole row).

ALTER TABLE documents
    ADD COLUMN billing_address      text CHECK (length(billing_address) <= 500),
    ADD COLUMN shipping_address     text CHECK (length(shipping_address) <= 500),
    ADD COLUMN email_to             text CHECK (length(email_to) <= 500),
    ADD COLUMN email_cc             text CHECK (length(email_cc) <= 500),
    ADD COLUMN email_bcc            text CHECK (length(email_bcc) <= 500),
    ADD COLUMN ship_via             text CHECK (length(ship_via) <= 60),
    ADD COLUMN ship_date            date,
    ADD COLUMN tracking_number      text CHECK (length(tracking_number) <= 60),
    ADD COLUMN message_on_statement text CHECK (length(message_on_statement) <= 500);
