-- Evenbooks core schema, part 6: state machines.
--
-- Every record with a status has a closed list of states (a CHECK on its table) and a closed list of
-- allowed moves (the rows below). A trigger refuses any other move, whatever code attempts it.
-- from_state '' means "may be created in this state". The same list is mirrored in the API
-- (config/state_machines.php); a test keeps the two identical.

CREATE TABLE state_transitions (
    machine    text NOT NULL,
    from_state text NOT NULL,
    to_state   text NOT NULL,
    action     text NOT NULL,   -- the one named action that performs this move
    PRIMARY KEY (machine, from_state, to_state)
);

INSERT INTO state_transitions (machine, from_state, to_state, action) VALUES
    -- Subscription (organisations.status). suspended and cancelled are read-only.
    ('subscription', '',          'trial',     'SignUp'),
    ('subscription', 'trial',     'active',    'StartSubscription'),
    ('subscription', 'trial',     'suspended', 'EndTrial'),
    ('subscription', 'trial',     'cancelled', 'CancelSubscription'),
    ('subscription', 'active',    'past_due',  'MarkPaymentFailed'),
    ('subscription', 'active',    'cancelled', 'CancelSubscription'),
    ('subscription', 'past_due',  'active',    'RecordSubscriptionPayment'),
    ('subscription', 'past_due',  'suspended', 'SuspendForNonPayment'),
    ('subscription', 'past_due',  'cancelled', 'CancelSubscription'),
    ('subscription', 'suspended', 'active',    'RecordSubscriptionPayment'),
    ('subscription', 'suspended', 'cancelled', 'CancelSubscription'),
    -- Company
    ('company', '',           'setting_up', 'SetUpCompany'),
    ('company', 'setting_up', 'live',       'GoLive'),
    ('company', 'live',       'archived',   'ArchiveCompany'),
    ('company', 'archived',   'live',       'RestoreCompany'),
    -- User membership
    ('membership', '',            'invited',     'InviteUser'),
    ('membership', '',            'active',      'SetUpCompany'),
    ('membership', 'invited',     'active',      'AcceptInvitation'),
    ('membership', 'invited',     'deactivated', 'RevokeInvitation'),
    ('membership', 'active',      'deactivated', 'DeactivateUser'),
    ('membership', 'deactivated', 'active',      'ReactivateUser'),
    -- Fiscal year
    ('fiscal_year', '',         'open',     'OpenFiscalYear'),
    ('fiscal_year', 'open',     'closing',  'StartYearEndClose'),
    ('fiscal_year', 'closing',  'open',     'CancelYearEndClose'),
    ('fiscal_year', 'closing',  'closed',   'CloseFiscalYear'),
    ('fiscal_year', 'closed',   'reopened', 'ReopenFiscalYear'),
    ('fiscal_year', 'reopened', 'closed',   'CloseFiscalYear'),
    -- Journal entry
    ('journal_entry', '',       'posted',   'PostJournal'),
    ('journal_entry', 'posted', 'reversed', 'ReverseJournal'),
    -- Invoice, bill, credit note, supplier credit, cash sale (documents.status)
    ('invoice', '',                  'draft',             'SaveDraft'),
    ('invoice', 'draft',             'awaiting_approval', 'SubmitForApproval'),
    ('invoice', 'draft',             'posted',            'Approve'),
    ('invoice', 'draft',             'void',              'CancelDraft'),
    ('invoice', 'awaiting_approval', 'posted',            'Approve'),
    ('invoice', 'awaiting_approval', 'draft',             'RejectApproval'),
    ('invoice', 'posted',            'part_paid',         'AllocatePayment'),
    ('invoice', 'posted',            'paid',              'AllocatePayment'),
    ('invoice', 'part_paid',         'paid',              'AllocatePayment'),
    ('invoice', 'part_paid',         'posted',            'ReversePayment'),
    ('invoice', 'paid',              'part_paid',         'ReversePayment'),
    ('invoice', 'paid',              'posted',            'ReversePayment'),
    ('invoice', 'posted',            'void',              'Void'),
    -- Quote, sales order, purchase order
    ('quote', '',         'draft',     'SaveDraft'),
    ('quote', 'draft',    'sent',      'Send'),
    ('quote', 'draft',    'accepted',  'Accept'),
    ('quote', 'draft',    'void',      'CancelDraft'),
    ('quote', 'sent',     'accepted',  'Accept'),
    ('quote', 'sent',     'declined',  'Decline'),
    ('quote', 'sent',     'expired',   'Expire'),
    ('quote', 'sent',     'void',      'Cancel'),
    ('quote', 'expired',  'sent',      'Extend'),
    ('quote', 'accepted', 'converted', 'ConvertToInvoice'),
    -- Payment
    ('payment', '',       'posted',   'RecordPayment'),
    ('payment', 'posted', 'reversed', 'ReversePayment'),
    -- E-invoice submission. cleared is final: a cleared invoice is corrected by credit note.
    ('einvoice', '',          'queued',    'QueueEInvoice'),
    ('einvoice', 'queued',    'submitted', 'SubmitEInvoice'),
    ('einvoice', 'queued',    'cleared',   'SubmitEInvoice'),
    ('einvoice', 'queued',    'rejected',  'SubmitEInvoice'),
    ('einvoice', 'queued',    'failed',    'SubmitEInvoice'),
    ('einvoice', 'submitted', 'cleared',   'SubmitEInvoice'),
    ('einvoice', 'submitted', 'rejected',  'SubmitEInvoice'),
    ('einvoice', 'submitted', 'failed',    'SubmitEInvoice'),
    ('einvoice', 'failed',    'queued',    'RetryEInvoice'),
    ('einvoice', 'failed',    'submitted', 'SubmitEInvoice'),
    ('einvoice', 'failed',    'cleared',   'SubmitEInvoice'),
    ('einvoice', 'failed',    'rejected',  'SubmitEInvoice'),
    ('einvoice', 'rejected',  'queued',    'RequeueAfterCorrection'),
    -- Bank statement line
    ('bank_transaction', '',            'imported',    'ImportStatement'),
    ('bank_transaction', 'imported',    'matched',     'MatchTransaction'),
    ('bank_transaction', 'imported',    'categorised', 'CategoriseTransaction'),
    ('bank_transaction', 'imported',    'excluded',    'ExcludeTransaction'),
    ('bank_transaction', 'matched',     'imported',    'UndoMatch'),
    ('bank_transaction', 'categorised', 'imported',    'UndoMatch'),
    ('bank_transaction', 'excluded',    'imported',    'UndoMatch'),
    ('bank_transaction', 'matched',     'reconciled',  'CompleteReconciliation'),
    ('bank_transaction', 'categorised', 'reconciled',  'CompleteReconciliation'),
    ('bank_transaction', 'reconciled',  'matched',     'ReopenReconciliation'),
    ('bank_transaction', 'reconciled',  'categorised', 'ReopenReconciliation');

CREATE FUNCTION state_transition_allowed(p_machine text, p_from text, p_to text) RETURNS boolean
LANGUAGE sql STABLE AS $$
    SELECT EXISTS (SELECT 1 FROM state_transitions
                    WHERE machine = p_machine AND from_state = p_from AND to_state = p_to)
$$;

-- Generic guard: CREATE TRIGGER ... EXECUTE FUNCTION enforce_state_machine('<machine>').
CREATE FUNCTION enforce_state_machine() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    from_state text := CASE WHEN TG_OP = 'INSERT' THEN '' ELSE OLD.status END;
BEGIN
    IF from_state IS DISTINCT FROM NEW.status AND NOT state_transition_allowed(TG_ARGV[0], from_state, NEW.status) THEN
        RAISE EXCEPTION '% cannot move from % to %', TG_ARGV[0], coalesce(nullif(from_state, ''), '(new)'), NEW.status
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER organisations_state_machine BEFORE INSERT OR UPDATE ON organisations
    FOR EACH ROW EXECUTE FUNCTION enforce_state_machine('subscription');
CREATE TRIGGER companies_state_machine BEFORE INSERT OR UPDATE ON companies
    FOR EACH ROW EXECUTE FUNCTION enforce_state_machine('company');
CREATE TRIGGER memberships_state_machine BEFORE INSERT OR UPDATE ON memberships
    FOR EACH ROW EXECUTE FUNCTION enforce_state_machine('membership');
CREATE TRIGGER fiscal_years_state_machine BEFORE INSERT OR UPDATE ON fiscal_years
    FOR EACH ROW EXECUTE FUNCTION enforce_state_machine('fiscal_year');
CREATE TRIGGER payments_state_machine BEFORE INSERT OR UPDATE ON payments
    FOR EACH ROW EXECUTE FUNCTION enforce_state_machine('payment');
CREATE TRIGGER einvoice_submissions_state_machine BEFORE INSERT OR UPDATE ON einvoice_submissions
    FOR EACH ROW EXECUTE FUNCTION enforce_state_machine('einvoice');
CREATE TRIGGER bank_transactions_state_machine BEFORE INSERT OR UPDATE ON bank_transactions
    FOR EACH ROW EXECUTE FUNCTION enforce_state_machine('bank_transaction');

-- Documents: the machine depends on the document type, paid states follow amount_due (they are never
-- set by hand), every save bumps lock_version, and the cross-machine guards live here.
CREATE FUNCTION documents_state_machine() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    machine    text := CASE WHEN NEW.type IN ('quote', 'sales_order', 'purchase_order') THEN 'quote' ELSE 'invoice' END;
    from_state text := CASE WHEN TG_OP = 'INSERT' THEN '' ELSE OLD.status END;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        NEW.lock_version := OLD.lock_version + 1;

        IF OLD.status IN ('posted', 'part_paid', 'paid') AND NEW.status IN ('posted', 'part_paid', 'paid') THEN
            NEW.status := CASE WHEN NEW.amount_due = NEW.total  THEN 'posted'
                               WHEN NEW.amount_due = 0          THEN 'paid'
                               ELSE 'part_paid' END;
        END IF;

        -- A document cleared by the tax authority is corrected by credit note, never edited or voided.
        IF (NEW.status = 'void' OR NEW.journal_entry_id IS DISTINCT FROM OLD.journal_entry_id)
           AND OLD.status IN ('posted', 'part_paid', 'paid')
           AND EXISTS (SELECT 1 FROM einvoice_submissions s
                        WHERE s.company_id = OLD.company_id AND s.document_id = OLD.id AND s.status = 'cleared') THEN
            RAISE EXCEPTION 'Document % is cleared by the tax authority; correct it with a credit note', OLD.number
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    IF from_state IS DISTINCT FROM NEW.status AND NOT state_transition_allowed(machine, from_state, NEW.status) THEN
        RAISE EXCEPTION '% % cannot move from % to %', NEW.type, NEW.number, coalesce(nullif(from_state, ''), '(new)'), NEW.status
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER documents_state_machine BEFORE INSERT OR UPDATE ON documents
    FOR EACH ROW EXECUTE FUNCTION documents_state_machine();

-- A payment can only be allocated to a posted, unpaid document of the same company, never beyond its
-- open amount. The document row is locked so two allocations cannot both pass the check.
CREATE FUNCTION payment_allocations_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    doc record;
BEGIN
    SELECT status, amount_due, number INTO doc
      FROM documents WHERE id = NEW.document_id AND company_id = NEW.company_id FOR UPDATE;
    IF NOT FOUND OR doc.status NOT IN ('posted', 'part_paid') THEN
        RAISE EXCEPTION 'Payments can only be allocated to a posted, unpaid document' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.amount > doc.amount_due THEN
        RAISE EXCEPTION 'Allocation % is more than the open amount % of %', NEW.amount, doc.amount_due, doc.number
            USING ERRCODE = 'check_violation';
    END IF;
    UPDATE documents SET amount_due = amount_due - NEW.amount, updated_at = now() WHERE id = NEW.document_id;
    RETURN NEW;
END $$;

CREATE TRIGGER payment_allocations_apply BEFORE INSERT ON payment_allocations
    FOR EACH ROW EXECUTE FUNCTION payment_allocations_guard();

-- Removing an allocation (a reversed payment) reopens the document.
CREATE FUNCTION payment_allocations_release() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    UPDATE documents SET amount_due = amount_due + OLD.amount, updated_at = now() WHERE id = OLD.document_id;
    RETURN OLD;
END $$;

CREATE TRIGGER payment_allocations_release BEFORE DELETE ON payment_allocations
    FOR EACH ROW EXECUTE FUNCTION payment_allocations_release();
