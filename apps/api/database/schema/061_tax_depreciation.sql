-- Evenbooks core schema, part 61: a separate tax depreciation schedule per fixed asset (Module 9c).
--
-- The books depreciate an asset as it wears out (IFRS for SMEs); the tax return allows depreciation by the tax law's
-- rules, which may differ. An asset can say how tax depreciates it: straight line over a number of months, or a
-- reducing balance at an annual rate, from the same start, to nothing, with what was claimed before Evenbooks. It is a
-- schedule only: nothing posts, and it never changes the books. No rate is built in: the company's tax adviser gives
-- them. Without a tax method, the tax schedule is the book one.

ALTER TABLE fixed_assets
    ADD COLUMN tax_method               text         CHECK (tax_method IN ('straight_line', 'reducing_balance')),
    ADD COLUMN tax_useful_life_months   integer      CHECK (tax_useful_life_months BETWEEN 1 AND 1200),
    ADD COLUMN tax_annual_rate          numeric(9,6) CHECK (tax_annual_rate > 0 AND tax_annual_rate < 1),
    ADD COLUMN tax_opening_depreciation numeric(20,2) NOT NULL DEFAULT 0 CHECK (tax_opening_depreciation >= 0);

ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_tax_rule CHECK (
    (tax_method IS NULL AND tax_useful_life_months IS NULL AND tax_annual_rate IS NULL AND tax_opening_depreciation = 0)
    OR (tax_method = 'straight_line' AND tax_useful_life_months IS NOT NULL AND tax_annual_rate IS NULL)
    OR (tax_method = 'reducing_balance' AND tax_annual_rate IS NOT NULL AND tax_useful_life_months IS NULL));
ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_tax_within_cost CHECK (tax_opening_depreciation <= cost);
