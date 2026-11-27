import { Dt } from "@/components/Dt";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getCompanySettings, getCurrencies, getDimensions, getNumbering, getPaymentTerms, getSubscription } from "@/lib/data";
import { subscriptionStatus } from "@/lib/subscription";
import { CompanyProfileForm, CurrenciesPanel, DimensionsPanel, NumberingPanel, PeriodLockForm, TermsPanel, FormContentPanel } from "./SettingsForms";
import { LogoCard } from "./LogoCard";
import { DemoBooks } from "./DemoBooks";
import { getSession } from "@/lib/session";

export const metadata = { title: "Company settings" };

/**
 * Module 1, company profile and fiscal settings. Every member can read them; an owner or admin changes them; an
 * accountant closes periods. What a role cannot change is shown read-only (the API refuses it anyway).
 */
export default async function SettingsPage({ searchParams }: PageProps<"/settings">) {
  const { logo } = await searchParams;
  const [settings, currencies, numbering, dimensions, subscription, terms] = await Promise.all([getCompanySettings(), getCurrencies(), getNumbering(), getDimensions(), getSubscription(), getPaymentTerms(true)]);
  const year = settings.fiscal_years.at(-1);
  const { demo } = await getSession();   // the demo role's label: the demo's own buttons are its owner's
  // The same two buttons for the owners of a test company (104), which is real books, not the demo.
  const booksButtons = demo === "Owner" ? "demo" : settings.can.test_books ? "test" : null;

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
          {booksButtons && <DemoBooks kind={booksButtons} />}
          <LogoCard hasLogo={Boolean(settings.has_logo)} manage={settings.can.manage} flag={typeof logo === "string" ? logo : undefined} />

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
              A document is numbered when it is saved: the next in the sequence, or a number a person types (each number once).
              A draft deleted later leaves a gap.
            </p>
            <NumberingPanel settings={settings} numbering={numbering} />
          </Card>

          <div id="form-content" className="scroll-mt-20">
            <Card label="Customize forms">
              <h2 className="text-[19px] font-bold">Customize forms</h2>
              <p className="mt-1.5 text-sm text-muted">
                The fields invoices, quotes and cash sales (sales forms) and bills, expenses and orders to suppliers (purchase forms) show, as in QuickBooks.
              </p>
              <FormContentPanel settings={settings} />
            </Card>
          </div>

          <div id="terms" className="scroll-mt-20">
            <Card label="Terms">
              <h2 className="text-[19px] font-bold">Terms</h2>
              <p className="mt-1.5 text-sm text-muted">
                The terms customers and suppliers are on. A term sets the due date of an invoice or bill from its date; the due date can still be changed on the document.
              </p>
              <TermsPanel settings={settings} terms={terms} />
            </Card>
          </div>

          <Card label="Fiscal year">
            <h2 className="text-[19px] font-bold">Fiscal year</h2>
            {year ? (
              <p className="mt-1.5 text-sm">
                <span className="num"><Dt v={year.starts_on} /></span> to <span className="num"><Dt v={year.ends_on} /></span>{" "}
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
                {subscription.until && <div><dt className="text-muted">{subscription.status === "trial" ? "Trial ends" : "Paid until"}</dt><dd className="num"><Dt v={subscription.until} /></dd></div>}
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
                Everything the company keeps in Evenbooks, as CSV files in one ZIP: to keep a copy or take your books
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
