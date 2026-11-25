-- Evenbooks core schema, part 43: disposing of fixed assets (Module 9c).
--
-- An asset is sold or scrapped by one entry (source 'asset_disposal', naming the asset) dated the day it goes: its
-- accumulated depreciation and its cost come off, what it was sold for comes in, and the difference is a gain or loss
-- on disposal (section 13). Rules here:
--   1. An asset is disposed of once, by its own disposal entry dated on or after it was acquired.
--   2. A disposed asset is never changed again, nor depreciated.

ALTER TABLE fixed_assets ADD COLUMN disposed_on date;
ALTER TABLE fixed_assets ADD COLUMN disposal_entry_id uuid;
ALTER TABLE fixed_assets ADD COLUMN proceeds numeric(20,2) CHECK (proceeds >= 0);
ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_disposal_entry FOREIGN KEY (company_id, disposal_entry_id) REFERENCES journal_entries (company_id, id);
ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_disposed_with_entry
    CHECK ((status = 'disposed') = (disposed_on IS NOT NULL AND disposal_entry_id IS NOT NULL)) NOT VALID;

CREATE FUNCTION fixed_assets_disposal_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF OLD.status = 'disposed' THEN
        RAISE EXCEPTION 'Asset % was disposed of on %: it stays as it was', OLD.code, OLD.disposed_on USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.status = 'disposed' THEN
        IF NEW.disposed_on < NEW.acquired_on THEN
            RAISE EXCEPTION 'An asset is disposed of on or after the day it was acquired' USING ERRCODE = 'check_violation';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM journal_entries WHERE company_id = NEW.company_id AND id = NEW.disposal_entry_id
                         AND source_type = 'asset_disposal' AND source_id = NEW.id AND entry_date = NEW.disposed_on) THEN
            RAISE EXCEPTION 'An asset is disposed of by its own disposal entry, dated the day it goes' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER fixed_assets_disposal_valid BEFORE UPDATE ON fixed_assets
    FOR EACH ROW EXECUTE FUNCTION fixed_assets_disposal_valid();

CREATE FUNCTION journal_entries_asset_disposal_valid() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = {{schema}}, pg_temp AS $$
BEGIN
    IF NEW.source_type = 'asset_disposal' AND NOT EXISTS (
        SELECT 1 FROM fixed_assets WHERE id = NEW.source_id AND company_id = NEW.company_id AND status = 'active' AND acquired_on <= NEW.entry_date) THEN
        RAISE EXCEPTION 'A disposal entry names an asset of the company still on the register' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER journal_entries_asset_disposal_valid BEFORE INSERT ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION journal_entries_asset_disposal_valid();
