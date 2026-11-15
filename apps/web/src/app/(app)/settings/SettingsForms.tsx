"use client";

import { useActionState, useId, type ReactNode } from "react";
import type { CompanySettings, Currencies } from "@/lib/api";
import { INDUSTRIES } from "@/lib/countries";
import { CURRENCIES } from "@/lib/decimal";
import type { FormState } from "@/lib/forms";
import { VAT_SHOWN } from "@/lib/settings";
import { addExchangeRate, saveCompanySettings, setCurrency, setPeriodLock } from "./actions";

type Settings = CompanySettings & { demo: boolean };

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

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
        <Select name="calendar" label="Calendar" defaultValue={settings.calendar} disabled={locked("calendar")}>
          {settings.options.calendars.map((c) => <option key={c} value={c}>{c === "ethiopian" ? "Ethiopian" : "Gregorian"}</option>)}
        </Select>
        <Select name="fiscal_year_start_month" label="Fiscal year starts in" defaultValue={String(settings.fiscal_year_start_month)} disabled={locked("fiscal_year_start_month")}>
          {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
        </Select>
        {field("conversion_date", "Conversion date", { type: "date", hint: "Last bank statement date before Ledgerly" })}
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
  const today = new Date().toISOString().slice(0, 10);

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
              <th className="py-1.5 text-right font-bold">Latest rate ({currencies.base_currency})</th>
              <th className="py-1.5" />
            </tr>
          </thead>
          <tbody>
            {currencies.data.map((c) => (
              <tr key={c.currency} className="border-t border-line">
                <td className="py-2 font-semibold">{c.currency}{!c.is_enabled && <span className="ml-2 text-[12px] font-normal text-muted">off</span>}</td>
                <td className="num py-2 text-right">
                  {c.latest_rate ? <>{c.latest_rate}<span className="block text-[12px] text-muted">{c.latest_rate_date}</span></> : <span className="text-muted">none yet</span>}
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

function TextField({ name, label, type = "text", defaultValue = "", required, disabled, invalid, hint }: {
  name: string; label: string; type?: string; defaultValue?: string; required?: boolean; disabled?: boolean; invalid?: boolean; hint?: string;
}) {
  const id = useId();
  return (
    <div>
      <label className="field-label" htmlFor={id}>{label}</label>
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
      />
      {hint && <p id={`${id}-hint`} className="mt-1.5 text-[13px] text-muted">{hint}</p>}
    </div>
  );
}

function Select({ name, label, defaultValue, disabled, children }: { name: string; label: string; defaultValue: string; disabled?: boolean; children: ReactNode }) {
  const id = useId();
  return (
    <div>
      <label className="field-label" htmlFor={id}>{label}</label>
      <select className="field" id={id} name={name} defaultValue={defaultValue} disabled={disabled}>{children}</select>
    </div>
  );
}
