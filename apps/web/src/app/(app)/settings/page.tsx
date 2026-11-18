import { Card, PageTitle, Pill } from "@/components/ui";
import { getCompanySettings, getCurrencies, getDimensions, getNumbering, getSubscription } from "@/lib/data";
import { subscriptionStatus } from "@/lib/subscription";
import { CompanyProfileForm, CurrenciesPanel, DimensionsPanel, NumberingPanel, PeriodLockForm } from "./SettingsForms";

export const metadata = { title: "Company settings" };

/**
 * Module 1, company profile and fiscal settings. Every member can read them; an owner or admin changes them; an
 * accountant closes periods. What a role cannot change is shown read-only (the API refuses it anyway).
 */
export default async function SettingsPage() {
  const [settings, currencies, numbering, dimensions, subscription] = await Promise.all([getCompanySettings(), getCurrencies(), getNumbering(), getDimensions(), getSubscription()]);
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

          <Card label="Locations and dimensions">
            <h2 className="text-[19px] font-bold">Locations and dimensions</h2>
            <p className="mt-1.5 text-sm text-muted">Tag transactions with a branch, department or project to see reports for each.</p>
            <DimensionsPanel settings={settings} dimensions={dimensions} />
          </Card>

          <Card label="Numbering">
            <h2 className="text-[19px] font-bold">Numbering</h2>
            <p className="mt-1.5 text-sm text-muted">
              Drafts have no number. A number is given when the document is posted, in order and without gaps.
            </p>
            <NumberingPanel settings={settings} numbering={numbering} />
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

          {subscription && !subscription.demo && (
            <Card label="Plan and billing">
              <h2 className="text-[19px] font-bold">Plan and billing</h2>
              <dl className="mt-2 grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3 text-sm">
                <div><dt className="text-muted">Plan</dt><dd className="font-semibold">{subscription.plan_name}</dd></div>
                <div><dt className="text-muted">Status</dt><dd><Pill tone={subscription.read_only ? "bad" : subscription.status === "active" ? "good" : "warn"}>{subscriptionStatus(subscription)}</Pill></dd></div>
                {subscription.until && <div><dt className="text-muted">{subscription.status === "trial" ? "Trial ends" : "Paid until"}</dt><dd className="num">{subscription.until}</dd></div>}
              </dl>
              {subscription.instructions && (
                <div className="mt-4 rounded-lg border border-line p-3 text-[13px]">
                  <p className="font-semibold">How to pay</p>
                  <p className="mt-1 whitespace-pre-line">{subscription.instructions}</p>
                  <p className="mt-2 text-muted">Once the transfer arrives, your subscription is marked paid.</p>
                </div>
              )}
              {subscription.contact && <p className="mt-3 text-[13px] text-muted">Questions about billing: <a className="font-semibold text-brand hover:underline" href={`mailto:${subscription.contact}`}>{subscription.contact}</a></p>}
            </Card>
          )}

          {!settings.demo && (
            <Card label="Company data">
              <h2 className="text-[19px] font-bold">Company data</h2>
              <p className="mt-1.5 text-sm text-muted">
                Everything the company keeps in Ledgerly, as CSV files in one ZIP: to keep a copy or take your books
                elsewhere. Owners and admins.
              </p>
              {/* A plain link: the answer is a file, not a page. */}
              <a href="/settings/export" className="btn mt-4" download>Download all company data</a>
            </Card>
          )}
        </div>
      </div>
    </>
  );
}
