"use client";
import { DateInput } from "@/components/DateInput";
import { formatDate, todayIn } from "@/lib/dates";

import { Dt } from "@/components/Dt";

import { useActionState, useId, useState, type ReactNode } from "react";
import { FiscalStartFields } from "@/components/FiscalStartFields";
import type { CompanySettings, Currencies, Dimension, FormContent, Numbering, PaymentTerm } from "@/lib/api";
import { describeTerm } from "@/lib/terms";
import { INDUSTRIES } from "@/lib/countries";
import { CURRENCIES } from "@/lib/decimal";
import type { FormState } from "@/lib/forms";
import { FORM_FIELDS, DATE_FORMAT_CHOICES, VAT_SHOWN, REMINDERS_SHOWN, TAX_REMINDERS_SHOWN, TEMPLATE_SHOWN, INTEREST_SHOWN } from "@/lib/settings";
import { addDimension, addExchangeRate, saveCompanySettings, saveNumbering, addPaymentTerm, savePaymentTerm, saveFormContent, setCurrency, setPeriodLock, toggleDimension } from "./actions";

type Settings = CompanySettings & { demo: boolean };


const LEGAL_FORMS: Record<string, string> = {
  sole_proprietor: "Sole proprietor",
  partnership: "Partnership",
  private_limited: "Private limited company (PLC)",
  share_company: "Share company",
  cooperative: "Cooperative",
  non_profit: "Non-profit or NGO",
  branch_of_foreign: "Branch of a foreign company",
  public_body: "Public body",
  other: "Other",
};

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-4 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-4 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

/** Profile and the foundation of the books. Fields the role cannot change, or that are fixed, are disabled and not sent. */
export function CompanyProfileForm({ settings }: { settings: Settings }) {
  const [state, action, pending] = useActionState(saveCompanySettings, undefined);
  const editable = settings.can.manage && !settings.demo;
  const locked = (name: string) => !editable || settings.locked_fields.includes(name);
  const bad = (name: string) => Boolean(state?.fields?.[name]);

  const field = (name: keyof CompanySettings, label: string, opts: { type?: string; required?: boolean; hint?: string } = {}) => (
    <TextField key={name} name={name} label={label} defaultValue={(settings[name] as string | null) ?? ""} disabled={locked(name)} invalid={bad(name)} {...opts} />
  );

  return (
    <form action={action} noValidate>
      <Message state={state} />
      <input type="hidden" name="version" value={settings.version} />

      <fieldset disabled={!editable} className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-[18px]">
        <legend className="sr-only">Company profile</legend>
        {field("legal_name", "Legal name", { required: true })}
        {field("trading_name", "Trading name")}
        <Select name="legal_form" label="Legal form" defaultValue={settings.legal_form ?? ""} disabled={locked("legal_form")}>
          <option value="">Not set</option>
          {settings.options.legal_forms.map((f) => <option key={f} value={f}>{LEGAL_FORMS[f] ?? f}</option>)}
        </Select>
        <Select name="industry" label="Industry" defaultValue={settings.industry ?? ""} disabled={locked("industry")}>
          <option value="">Not set</option>
          {INDUSTRIES.map((i) => <option key={i.key} value={i.key}>{i.name}</option>)}
        </Select>
        {field("tin", settings.options.tax_id_label)}
        {field("vat_number", "VAT registration number")}
        {field("phone", "Phone", { type: "tel" })}
        {field("email", "Company email", { type: "email" })}
        {field("website", "Website", { type: "url" })}
        {field("address_line1", "Address")}
        {field("address_line2", "Address, second line")}
        {field("city", "City")}
        {field("region", "Region")}
        {field("postal_code", "Postal code")}
        {field("timezone", "Time zone", { required: true, hint: "For example Africa/Addis_Ababa" })}
        {/* How every date is written: lists, reports, documents, emails and exports (QuickBooks: Date format). */}
        <Select name="date_format" label="Date format" defaultValue={settings.date_format ?? "DD/MM/YYYY"}>
          {DATE_FORMAT_CHOICES.map((f) => <option key={f} value={f}>{f} (today: {formatDate(todayIn(settings.timezone), { calendar: settings.calendar, dateFormat: f })})</option>)}
        </Select>
        {/* QuickBooks' default Amounts are (110): how new invoices, bills and receipts start. */}
        <Select name="amounts_are" label="New documents: amounts are" defaultValue={settings.amounts_are ?? "exclusive"}>
          <option value="exclusive">Exclusive of tax</option>
          <option value="inclusive">Inclusive of tax</option>
          <option value="out_of_scope">Out of scope of tax</option>
        </Select>
        <Select name="accounting_basis" label="Reports default to" defaultValue={settings.accounting_basis} disabled={locked("accounting_basis")}>
          <option value="accrual">Accrual basis</option>
          <option value="cash">Cash basis (a view of the same books)</option>
        </Select>
      </fieldset>

      <label className="mt-5 flex items-center gap-2.5 text-sm font-semibold">
        <input type="hidden" name={VAT_SHOWN} value="1" disabled={!editable} />
        <input type="checkbox" name="vat_registered" defaultChecked={settings.vat_registered} disabled={!editable} className="size-4" />
        The company is registered for VAT
      </label>

      <h3 className="mb-3 mt-7 text-[15px] font-bold">Foundation of the books</h3>
      <fieldset disabled={!editable} className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-[18px]">
        <legend className="sr-only">Foundation of the books</legend>
        <Select name="base_currency" label="Currency of the books" defaultValue={settings.base_currency} disabled={locked("base_currency")}>
          {CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
        </Select>
        <FiscalStartFields calendars={settings.options.calendars} defaultCalendar={settings.calendar} defaultMonth={settings.fiscal_year_start_month}
          calendarDisabled={locked("calendar")} monthDisabled={locked("fiscal_year_start_month")} />
        {field("conversion_date", "Conversion date", { type: "date", hint: "Last bank statement date before Evenbooks" })}
      </fieldset>

      <h3 className="mb-1 mt-7 text-[15px] font-bold">Approval</h3>
      <p className="mb-3 text-[13px] text-muted">Above these amounts ({settings.base_currency}), someone other than the person who made it approves an invoice, a bill or a manual journal. Leave empty for no limit.</p>
      <fieldset disabled={!editable} className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-[18px]">
        <legend className="sr-only">Approval limits</legend>
        {([["invoice_above", "Invoices above"], ["bill_above", "Bills above"], ["journal_above", "Manual journals above"]] as const).map(([key, label]) => (
          <div key={key}>
            <label className="field-label" htmlFor={`approval-${key}`}>{label}</label>
            <input className="field num" id={`approval-${key}`} name={`approval.${key}`} inputMode="decimal" defaultValue={settings.approval?.[key] ?? ""} placeholder="No limit" />
          </div>
        ))}
      </fieldset>

      <h3 className="mb-1 mt-7 text-[15px] font-bold">Payment reminders</h3>
      <p className="mb-3 text-[13px] text-muted">An email to the customer so many days after an invoice falls due, while something is still owed. Up to three steps; each goes once.</p>
      <fieldset disabled={!editable} className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] items-end gap-[18px]">
        <legend className="sr-only">Payment reminders</legend>
        <input type="hidden" name={REMINDERS_SHOWN} value="1" />
        <label className="flex items-center gap-2 text-sm" htmlFor="reminders-enabled">
          <input type="checkbox" id="reminders-enabled" name="reminders.enabled" defaultChecked={settings.reminders?.enabled ?? false} />
          Send reminders automatically
        </label>
        <div>
          <label className="field-label" htmlFor="reminders-days">Days after the due date</label>
          <input className="field num" id="reminders-days" name="reminders.days" inputMode="numeric" defaultValue={(settings.reminders?.days ?? [7, 30]).join(", ")} placeholder="7, 30" />
        </div>
      </fieldset>

      <fieldset disabled={!editable} className="mt-4">
        <legend className="sr-only">Tax return reminders</legend>
        <input type="hidden" name={TAX_REMINDERS_SHOWN} value="1" />
        <label className="flex items-center gap-2 text-sm" htmlFor="tax-reminders">
          <input type="checkbox" id="tax-reminders" name="tax_reminders" defaultChecked={settings.tax_reminders ?? true} />
          Email the people who file tax when a return is due within ten days, and when it is overdue
        </label>
      </fieldset>

      <h3 className="mb-1 mt-7 text-[15px] font-bold">Interest on late payment</h3>
      <p className="mb-3 text-[13px] text-muted">Simple interest a year on what an overdue invoice still owes, charged by hand on a draft invoice to the customer. Leave the rate empty to charge none.</p>
      <fieldset disabled={!editable} className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-[18px]">
        <legend className="sr-only">Interest on late payment</legend>
        <input type="hidden" name={INTEREST_SHOWN} value="1" />
        <div>
          <label className="field-label" htmlFor="interest-rate">Rate, % a year</label>
          <input className="field num" id="interest-rate" name="late_interest.rate" inputMode="decimal" defaultValue={settings.late_interest?.rate ?? ""} placeholder="None" />
        </div>
        <div>
          <label className="field-label" htmlFor="interest-account">Income account</label>
          <select className="field" id="interest-account" name="late_interest.account_id" defaultValue={settings.late_interest?.account_id ?? ""}>
            <option value="">Choose…</option>
            {(settings.options.income_accounts ?? []).map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
          </select>
        </div>
      </fieldset>

      <h3 className="mb-1 mt-7 text-[15px] font-bold">Invoices on paper and as PDF files</h3>
      <p className="mb-3 text-[13px] text-muted">An accent colour for the headings, how customers pay you (printed on invoices), and a line at the foot of the page.</p>
      <fieldset disabled={!editable} className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-[18px]">
        <legend className="sr-only">Invoice template</legend>
        <input type="hidden" name={TEMPLATE_SHOWN} value="1" />
        <div>
          <label className="field-label" htmlFor="template-accent">Accent colour</label>
          <input className="field h-[42px] w-[90px] p-1" id="template-accent" name="invoice_template.accent" type="color" defaultValue={settings.invoice_template?.accent ?? "#111111"} />
        </div>
        <div className="sm:col-span-2">
          <label className="field-label" htmlFor="template-payment">How to pay (bank, account, reference)</label>
          <textarea className="field min-h-[72px]" id="template-payment" name="invoice_template.payment_details" maxLength={600}
            defaultValue={settings.invoice_template?.payment_details ?? ""} placeholder="CBE 1000123456789, Abyssinia Coffee PLC. Quote the invoice number." />
        </div>
        <div className="sm:col-span-2">
          <label className="field-label" htmlFor="template-footer">Footer line</label>
          <input className="field" id="template-footer" name="invoice_template.footer" maxLength={300} defaultValue={settings.invoice_template?.footer ?? ""} placeholder="Thank you for your business" />
        </div>
      </fieldset>

      {editable && (
        <button type="submit" className="btn btn-primary mt-7 min-w-[180px] justify-center" disabled={pending}>
          {pending ? "Saving…" : "Save settings"}
        </button>
      )}
      {!editable && !settings.demo && <p className="mt-6 text-sm text-muted">Only an owner or admin can change these settings.</p>}
      {settings.demo && <p className="mt-6 text-sm text-muted">Not available in the demo.</p>}
    </form>
  );
}

/** Moves the lock date. An earlier date, or clearing it, reopens periods and needs a reason (owner or admin). */
export function PeriodLockForm({ settings }: { settings: Settings }) {
  const [state, action, pending] = useActionState(setPeriodLock, undefined);
  if (settings.demo || !settings.can.close_periods) return null;

  return (
    <form action={action} className="mt-4" noValidate>
      <Message state={state} />
      <TextField name="books_closed_until" label="Close the books up to" type="date" defaultValue={settings.books_closed_until ?? ""} invalid={Boolean(state?.fields?.books_closed_until)} />
      {settings.can.reopen_periods && (
        <>
          <label className="mt-3 flex items-center gap-2.5 text-sm font-semibold">
            <input type="checkbox" name="clear" className="size-4" /> Reopen every period
          </label>
          <div className="mt-3">
            <TextField name="reason" label="Reason (needed to reopen)" invalid={Boolean(state?.fields?.reason)} />
          </div>
        </>
      )}
      <button type="submit" className="btn mt-4" disabled={pending}>{pending ? "Saving…" : "Save lock date"}</button>
    </form>
  );
}

/**
 * Foreign currencies and their rates. An owner or admin adds and turns off currencies; an accountant enters the rate
 * of a day (a second entry for the same day corrects it). Invoices take the rate of their own date.
 */
export function CurrenciesPanel({ settings, currencies }: { settings: Settings; currencies: Currencies }) {
  const [added, add, adding] = useActionState(setCurrency, undefined);
  const [rated, rate, rating] = useActionState(addExchangeRate, undefined);
  const [toggled, toggle, toggling] = useActionState(setCurrency, undefined);
  const enabled = currencies.data.filter((c) => c.is_enabled);
  const manage = settings.can.manage && !settings.demo;
  const enterRates = settings.can.enter_rates && !settings.demo && enabled.length > 0;
  const today = todayIn(settings.timezone);
  // A rate older than the API uses (7 days) asks for a fresh one before the next document in that currency.
  const maxAge = currencies.rate_max_age_days ?? 7;
  const stale = (date: string | null) => !date || (Date.parse(`${today}T00:00:00Z`) - Date.parse(`${date}T00:00:00Z`)) / 86_400_000 > maxAge;

  return (
    <div>
      <div className="mt-3"><Message state={toggled} /></div>
      {currencies.data.length === 0 ? (
        <p className="mt-3 text-sm text-muted">Only {currencies.base_currency}. Add a currency to invoice or pay in it.</p>
      ) : (
        <table className="mt-3 w-full text-sm">
          <thead>
            <tr className="text-left text-[12px] uppercase tracking-wide text-muted">
              <th className="py-1.5 font-bold">Currency</th>
              <th className="py-1.5 font-bold">Accounts held in it</th>
              <th className="py-1.5 text-right font-bold">Latest rate ({currencies.base_currency})</th>
              <th className="py-1.5" />
            </tr>
          </thead>
          <tbody>
            {currencies.data.map((c) => (
              <tr key={c.currency} className="border-t border-line">
                <td className="py-2 font-semibold">{c.currency}{!c.is_enabled && <span className="ml-2 text-[12px] font-normal text-muted">off</span>}</td>
                <td className="py-2 text-[13px]">{c.accounts && c.accounts.length > 0
                  ? c.accounts.map((a) => <a key={a.id} href={`/banking/${encodeURIComponent(a.id)}`} className="block hover:underline"><span className="num text-muted">{a.code}</span> {a.name}</a>)
                  : <span className="text-muted">—</span>}</td>
                <td className="num py-2 text-right">
                  {c.latest_rate ? <>{c.latest_rate}<span className="block text-[12px] text-muted"><Dt v={c.latest_rate_date} /></span></> : <span className="text-muted">none yet</span>}
                  {c.is_enabled && stale(c.latest_rate_date) && (
                    <span role="status" className="mt-1 block text-[12px] font-semibold text-warn">
                      {c.latest_rate ? `Older than ${maxAge} days` : "Enter a rate"}: new {c.currency} documents need a rate of the day
                    </span>
                  )}
                </td>
                <td className="py-2 text-right">
                  {manage && (
                    <form action={toggle}>
                      <input type="hidden" name="currency" value={c.currency} />
                      <input type="hidden" name="enabled" value={c.is_enabled ? "false" : "true"} />
                      <button type="submit" className="text-[13px] font-bold text-brand" disabled={toggling}>{c.is_enabled ? "Turn off" : "Turn on"}</button>
                    </form>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {manage && (
        <form action={add} className="mt-4">
          <Message state={added} />
          <div className="flex flex-wrap items-end gap-2.5">
          <div className="min-w-[140px] flex-1">
            <Select name="currency" label="Add a currency" defaultValue="USD">
              {CURRENCIES.filter((c) => c !== currencies.base_currency && !currencies.data.some((d) => d.currency === c)).map((c) => (
                <option key={c} value={c}>{c}</option>
              ))}
            </Select>
          </div>
          <button type="submit" className="btn" disabled={adding}>{adding ? "Adding…" : "Add"}</button>
          </div>
        </form>
      )}

      {enterRates && (
        <form action={rate} className="mt-5 border-t border-line pt-4" noValidate>
          <h3 className="mb-2 text-[15px] font-bold">Enter a rate</h3>
          <Message state={rated} />
          <div className="grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-2.5">
            <Select name="currency" label="Currency" defaultValue={enabled[0].currency}>
              {enabled.map((c) => <option key={c.currency} value={c.currency}>{c.currency}</option>)}
            </Select>
            <TextField name="rate_date" label="Date" type="date" defaultValue={today} invalid={Boolean(rated?.fields?.rate_date)} />
            <TextField name="rate" label={`${currencies.base_currency} for 1 unit`} invalid={Boolean(rated?.fields?.rate)} />
          </div>
          <button type="submit" className="btn mt-3" disabled={rating}>{rating ? "Saving…" : "Save rate"}</button>
        </form>
      )}
    </div>
  );
}

const KINDS: { kind: Dimension["kind"]; label: string; hint: string }[] = [
  { kind: "location", label: "Branches and locations", hint: "Shops, branches, sites" },
  { kind: "class", label: "Departments and classes", hint: "Departments, lines of business" },
  { kind: "project", label: "Projects", hint: "Jobs and contracts" },
];

/**
 * Locations, classes and projects that transactions are tagged with, so reports can be cut by them. Turned off,
 * never deleted: old entries keep their meaning.
 */
export function DimensionsPanel({ settings, dimensions }: { settings: Settings; dimensions: Dimension[] }) {
  const [added, add, adding] = useActionState(addDimension, undefined);
  const [toggled, toggle, toggling] = useActionState(toggleDimension, undefined);
  const manage = settings.can.manage_dimensions && !settings.demo;

  return (
    <div className="mt-3">
      <Message state={toggled} />
      {KINDS.map(({ kind, label }) => {
        const rows = dimensions.filter((d) => d.kind === kind);
        return (
          <div key={kind} className="mt-3">
            <h3 className="text-[14px] font-bold">{label}</h3>
            {rows.length === 0 ? (
              <p className="text-[13px] text-muted">None yet.</p>
            ) : (
              <ul className="mt-1">
                {rows.map((d) => (
                  <li key={d.id} className="flex items-center justify-between gap-2 border-t border-line py-1.5 text-sm">
                    <span className={d.is_active ? "" : "text-muted line-through"}>
                      <span className="num mr-2 text-muted">{d.code}</span>{d.name}
                    </span>
                    {manage && (
                      <form action={toggle}>
                        <input type="hidden" name="id" value={d.id} />
                        <input type="hidden" name="is_active" value={d.is_active ? "false" : "true"} />
                        <button type="submit" className="text-[13px] font-bold text-brand" disabled={toggling}>{d.is_active ? "Turn off" : "Turn on"}</button>
                      </form>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
        );
      })}

      {manage && (
        <form action={add} className="mt-5 border-t border-line pt-4" noValidate>
          <h3 className="mb-2 text-[15px] font-bold">Add</h3>
          <Message state={added} />
          <div className="grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-2.5">
            <Select name="kind" label="Kind" defaultValue="location">
              {KINDS.map((k) => <option key={k.kind} value={k.kind}>{k.label}</option>)}
            </Select>
            <TextField name="code" label="Code" hint="e.g. HQ, MRK" invalid={Boolean(added?.fields?.code)} />
            <TextField name="name" label="Name" invalid={Boolean(added?.fields?.name)} />
          </div>
          <button type="submit" className="btn mt-3" disabled={adding}>{adding ? "Adding…" : "Add"}</button>
        </form>
      )}
    </div>
  );
}

const DOC_TYPES: Record<string, string> = {
  invoice: "Invoices", credit_note: "Credit notes", sales_receipt: "Cash sales", quote: "Quotes", sales_order: "Sales orders",
  bill: "Bills (our number)", vendor_credit: "Supplier credits", purchase_order: "Purchase orders", expense: "Expenses",
};

/**
 * QuickBooks' Customize (111): which fields the invoice and bill forms show, for sales and for purchases; how classes and
 * locations are tracked (off, one for the whole document, or one per line); and up to three custom fields.
 */
export function FormContentPanel({ settings }: { settings: Settings }) {
  const [state, action, pending] = useActionState(saveFormContent, undefined);
  const manage = settings.can.manage && !settings.demo;
  const c: FormContent = settings.form_content ?? {
    sales: {} as FormContent["sales"], purchases: {} as FormContent["purchases"], track_classes: "off", track_locations: "off", custom_fields: [],
  };
  return (
    <form action={action} className="mt-3" noValidate>
      <input type="hidden" name="version" value={settings.version} />
      <Message state={state} />
      <fieldset disabled={!manage}>
        <legend className="sr-only">Form content</legend>
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Field</th><th scope="col" className="!text-center">Sales forms</th><th scope="col" className="!text-center">Purchase forms</th></tr></thead>
            <tbody>
              {FORM_FIELDS.map((f) => (
                <tr key={f.key}>
                  <td>{f.label}</td>
                  {(["sales", "purchases"] as const).map((side) => (
                    <td key={side} className="text-center">
                      {f[side]
                        ? <input type="checkbox" name={`${side}.${f.key}`} defaultChecked={Boolean(c[side]?.[f.key])} aria-label={`${f.label}, ${side === "sales" ? "sales" : "purchase"} forms`} className="size-4" />
                        : <span className="text-muted" aria-hidden="true">—</span>}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="mt-4 grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-2.5">
          <Select name="track_classes" label="Track classes" defaultValue={c.track_classes}>
            <option value="off">Off</option>
            <option value="transaction">One to each transaction</option>
            <option value="line">One to each line</option>
          </Select>
          <Select name="track_locations" label="Track locations" defaultValue={c.track_locations}>
            <option value="off">Off</option>
            <option value="transaction">One to each transaction</option>
            <option value="line">One to each line</option>
          </Select>
        </div>
        <h3 className="mt-5 text-[15px] font-bold">Custom fields</h3>
        <p className="mt-1 text-[13px] text-muted">Up to three, such as a PO number or crop year. Leave a name empty to remove it.</p>
        {[0, 1, 2].map((i) => {
          const f = c.custom_fields[i];
          return (
            <div key={i} className="mt-2 flex flex-wrap items-end gap-3">
              <div className="min-w-[200px] flex-1"><TextField name={`custom.${i}.name`} label={`Field ${i + 1} name`} defaultValue={f?.name ?? ""} /></div>
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" name={`custom.${i}.sales`} defaultChecked={f?.sales ?? true} className="size-4" />Sales</label>
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" name={`custom.${i}.purchases`} defaultChecked={f?.purchases ?? false} className="size-4" />Purchases</label>
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" name={`custom.${i}.print`} defaultChecked={f?.print ?? true} className="size-4" />Print on the document</label>
            </div>
          );
        })}
      </fieldset>
      {manage && <button type="submit" className="btn mt-4" disabled={pending}>{pending ? "Saving…" : "Save form content"}</button>}
    </form>
  );
}

/**
 * QuickBooks' Terms list (109): each term sets the due date of the invoices and bills on it, from their date in the
 * company's calendar. Days ("Net 30"), or a day of the month ("Due end of month" is day 31, the last day of any month),
 * due the next month when a document is dated within so many days of it. Turned off, never deleted.
 */
export function TermsPanel({ settings, terms }: { settings: Settings; terms: PaymentTerm[] }) {
  const manage = settings.can.manage && !settings.demo;
  return (
    <div className="mt-3 flex flex-col gap-4">
      {terms.map((t) => <TermRow key={t.id} term={t} manage={manage} />)}
      {manage && <TermRow manage />}
    </div>
  );
}

function TermRow({ term, manage }: { term?: PaymentTerm; manage: boolean }) {
  const [state, action, pending] = useActionState(term ? savePaymentTerm : addPaymentTerm, undefined);
  const [kind, setKind] = useState<PaymentTerm["kind"]>(term?.kind ?? "days");
  const bad = (name: string) => Boolean(state?.fields?.[name]);
  return (
    <form action={action} className="border-t border-line pt-3" noValidate>
      {term && <input type="hidden" name="id" value={term.id} />}
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-[15px] font-bold">{term ? term.name : "Add a term"}</h3>
        {term && <span className="text-[13px] text-muted">{term.is_active ? describeTerm(term) : "Turned off"}</span>}
      </div>
      <Message state={state} />
      <fieldset disabled={!manage} className="mt-2 grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-2.5">
        <legend className="sr-only">{term ? `Term ${term.name}` : "New term"}</legend>
        <TextField name="name" label="Name" defaultValue={term?.name ?? ""} invalid={bad("name")} />
        <Select name="kind" label="Due" defaultValue={kind} onChange={(v) => setKind(v as PaymentTerm["kind"])}>
          <option value="days">In a number of days</option>
          <option value="day_of_month">On a day of the month</option>
        </Select>
        {kind === "days"
          ? <TextField name="days" label="Days" type="number" defaultValue={String(term?.days ?? 30)} invalid={bad("days")} />
          : (
            <>
              <Select name="day_of_month" label="Day" defaultValue={String(term?.day_of_month ?? 31)}>
                {Array.from({ length: 30 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}
                <option value="31">Last day of the month</option>
              </Select>
              <TextField name="next_month_within" label="Next month if dated within (days)" type="number" defaultValue={String(term?.next_month_within ?? 0)} invalid={bad("next_month_within")} />
            </>
          )}
      </fieldset>
      {term && (
        <label className="mt-2 flex items-center gap-2.5 text-sm">
          <input type="checkbox" name="is_active" defaultChecked={term.is_active} disabled={!manage} className="size-4" />
          In use (turned off, it stays on the documents that have it)
        </label>
      )}
      {manage && <button type="submit" className="btn mt-3" disabled={pending}>{pending ? "Saving…" : term ? "Save" : "Add term"}</button>}
    </form>
  );
}

/**
 * Numbering per document type. Automatic numbers are given in order when a document is saved, as QuickBooks does (a draft
 * deleted later leaves a gap), and a person may type their own unless custom numbers are off; manual numbering is for
 * pre-printed, authority-approved invoice books. The counter is never set by hand: it skips numbers already used.
 */
export function NumberingPanel({ settings, numbering }: { settings: Settings; numbering: Numbering[] }) {
  const manage = settings.can.manage && !settings.demo;
  return (
    <div className="mt-3 flex flex-col gap-4">
      {numbering.map((n) => <NumberingRow key={n.doc_type} n={n} manage={manage} />)}
    </div>
  );
}

function NumberingRow({ n, manage }: { n: Numbering; manage: boolean }) {
  const [state, action, pending] = useActionState(saveNumbering, undefined);
  return (
    <form action={action} className="border-t border-line pt-3" noValidate>
      <input type="hidden" name="doc_type" value={n.doc_type} />
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-[15px] font-bold">{DOC_TYPES[n.doc_type] ?? n.doc_type}</h3>
        <span className="text-[13px] text-muted">
          {n.mode === "manual" ? "Typed from the invoice book" : <>Next: <strong className="num text-ink">{n.next_number}</strong></>}
        </span>
      </div>
      <Message state={state} />
      <fieldset disabled={!manage} className="mt-2 grid grid-cols-[repeat(auto-fit,minmax(110px,1fr))] gap-2.5">
        <legend className="sr-only">{DOC_TYPES[n.doc_type] ?? n.doc_type} numbering</legend>
        <Select name="mode" label="Numbers" defaultValue={n.mode}>
          <option value="automatic">Automatic</option>
          <option value="manual">From a printed book</option>
        </Select>
        <TextField name="prefix" label="Prefix" defaultValue={n.prefix} invalid={Boolean(state?.fields?.prefix)} />
        <Select name="padding" label="Digits" defaultValue={String(n.padding)}>
          {[3, 4, 5, 6, 7, 8].map((d) => <option key={d} value={d}>{d}</option>)}
        </Select>
      </fieldset>
      <label className="mt-2 flex items-center gap-2.5 text-sm">
        <input type="checkbox" name="include_year" defaultChecked={n.include_year} disabled={!manage} className="size-4" />
        Fiscal year in the number (restarts at 1 each year)
      </label>
      <label className="mt-1.5 flex items-center gap-2.5 text-sm">
        <input type="checkbox" name="allow_custom" defaultChecked={n.allow_custom ?? true} disabled={!manage} className="size-4" />
        Custom numbers: a person may type their own number (each number once)
      </label>
      {manage && <button type="submit" className="btn mt-3" disabled={pending}>{pending ? "Saving…" : "Save"}</button>}
    </form>
  );
}

function TextField({ name, label, type = "text", defaultValue = "", required, disabled, invalid, hint }: {
  name: string; label: string; type?: string; defaultValue?: string; required?: boolean; disabled?: boolean; invalid?: boolean; hint?: string;
}) {
  const id = useId();
  return (
    <div>
      <label className="field-label" htmlFor={id}>{label}</label>
      {type === "date" ? <DateInput className={`field ${invalid ? "!border-bad" : ""}`} id={id} name={name} defaultValue={defaultValue} required={required} disabled={disabled} aria-invalid={invalid || undefined} aria-describedby={hint ? `${id}-hint` : undefined} /> : (
        <input
        className={`field ${invalid ? "!border-bad" : ""}`}
        id={id}
        name={name}
        type={type}
        defaultValue={defaultValue}
        required={required}
        disabled={disabled}
        aria-invalid={invalid || undefined}
        aria-describedby={hint ? `${id}-hint` : undefined}
      />)}
      {hint && <p id={`${id}-hint`} className="mt-1.5 text-[13px] text-muted">{hint}</p>}
    </div>
  );
}

function Select({ name, label, defaultValue, disabled, onChange, children }: {
  name: string; label: string; defaultValue: string; disabled?: boolean; onChange?: (value: string) => void; children: ReactNode;
}) {
  const id = useId();
  return (
    <div>
      <label className="field-label" htmlFor={id}>{label}</label>
      <select className="field" id={id} name={name} defaultValue={defaultValue} disabled={disabled} onChange={onChange && ((e) => onChange(e.target.value))}>{children}</select>
    </div>
  );
}
