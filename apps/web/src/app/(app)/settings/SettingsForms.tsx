"use client";

import { useActionState, useId, type ReactNode } from "react";
import type { CompanySettings } from "@/lib/api";
import { INDUSTRIES } from "@/lib/countries";
import { CURRENCIES } from "@/lib/decimal";
import type { FormState } from "@/lib/forms";
import { VAT_SHOWN } from "@/lib/settings";
import { saveCompanySettings, setPeriodLock } from "./actions";

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
