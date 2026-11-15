import { Card, PageTitle, Pill } from "@/components/ui";
import { getCompanySettings, getCurrencies } from "@/lib/data";
import { CompanyProfileForm, CurrenciesPanel, PeriodLockForm } from "./SettingsForms";

export const metadata = { title: "Company settings" };

/**
 * Module 1, company profile and fiscal settings. Every member can read them; an owner or admin changes them; an
 * accountant closes periods. What a role cannot change is shown read-only (the API refuses it anyway).
 */
export default async function SettingsPage() {
  const [settings, currencies] = await Promise.all([getCompanySettings(), getCurrencies()]);
  const year = settings.fiscal_years.at(-1);

  return (
    <>
      <PageTitle eyebrow="Company setup" title="Company settings" />

      <div className="flex max-w-[1100px] flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="Company profile">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-[19px] font-bold">Profile and books</h2>
            {settings.books_started ? <Pill tone="neutral">Books started</Pill> : <Pill tone="warn">Nothing posted yet</Pill>}
          </div>
          <p className="mb-5 mt-1.5 max-w-[640px] text-sm text-muted">
            {settings.books_started
              ? "Something is posted, so the currency, calendar, fiscal year and conversion date are fixed. A company that needs other ones starts new books."
              : "The currency, calendar, fiscal year and conversion date can change until the first entry is posted. After that they are fixed."}
          </p>
          <CompanyProfileForm settings={settings} />
        </Card>

        <div className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          <Card label="Period lock">
            <h2 className="text-[19px] font-bold">Closed periods</h2>
            <p className="mt-1.5 text-sm text-muted">
              Nothing can be posted, edited or voided on or before the lock date. Accountants move it forward; only an owner or
              admin can move it back, and must say why.
            </p>
            <p className="mt-3 text-sm">
              {settings.books_closed_until ? <>Books are closed up to <strong className="num">{settings.books_closed_until}</strong>.</> : "No period is closed."}
            </p>
            <PeriodLockForm settings={settings} />
          </Card>

          <Card label="Currencies">
            <h2 className="text-[19px] font-bold">Currencies</h2>
            <p className="mt-1.5 text-sm text-muted">
              Books are kept in {currencies.base_currency}. An invoice in another currency takes the rate of its own date and keeps it.
            </p>
            <CurrenciesPanel settings={settings} currencies={currencies} />
          </Card>

          <Card label="Fiscal year">
            <h2 className="text-[19px] font-bold">Fiscal year</h2>
            {year ? (
              <p className="mt-1.5 text-sm">
                <span className="num">{year.starts_on}</span> to <span className="num">{year.ends_on}</span>{" "}
                <Pill tone={year.status === "open" ? "good" : "neutral"}>{year.status}</Pill>
              </p>
            ) : (
              <p className="mt-1.5 text-sm text-muted">No fiscal year is open.</p>
            )}
            <p className="mt-3 text-[13px] text-muted">
              Framework: IFRS for SMEs · Country: {settings.country_code}
            </p>
          </Card>
        </div>
      </div>
    </>
  );
}
