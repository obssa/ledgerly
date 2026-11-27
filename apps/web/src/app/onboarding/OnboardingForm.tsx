"use client";
import { DateInput } from "@/components/DateInput";

import { useActionState } from "react";
import { createCompany, resendVerification } from "@/app/(auth)/actions";
import { COUNTRIES, INDUSTRIES } from "@/lib/countries";
import { CURRENCIES } from "@/lib/decimal";
import { FiscalStartFields } from "@/components/FiscalStartFields";

/** Creates the company through POST /api/v1/companies (createCompany), then opens its books. */
/** organisation: from the company switcher, the new company joins the same organisation (the API checks who may). */
export function OnboardingForm({ organisation = null }: { organisation?: string | null }) {
  const [state, formAction, pending] = useActionState(createCompany, undefined);
  const [resent, resend, resending] = useActionState(resendVerification, undefined);
  const bad = (name: string) => Boolean(state?.fields?.[name]);

  // The API opens a company's books only for a confirmed email address (SECURITY.md S3).
  if (state?.code === "EmailNotVerified") {
    return (
      <form action={resend} className="max-w-[560px]">
        <h2 className="text-[26px] font-extrabold tracking-tight">Confirm your email address first</h2>
        <p className="mt-2 text-[15px] leading-relaxed text-muted">
          We sent a link to your email address when you signed up. Open it, then come back and create your company.
          Check your spam folder if it is not in your inbox.
        </p>
        {resent?.notice && <p role="status" className="mt-4 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{resent.notice}</p>}
        {resent?.error && <p role="alert" className="mt-4 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{resent.error}</p>}
        <div className="mt-5 flex flex-wrap gap-2.5">
          <button type="submit" className="btn" disabled={resending}>{resending ? "Sending…" : "Send the email again"}</button>
          <a href="/onboarding" className="btn btn-primary">I have confirmed it</a>
        </div>
      </form>
    );
  }

  return (
    <form action={formAction} className="max-w-[760px]" noValidate>
      {state?.error && (
        <p role="alert" className="mb-4 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>
      )}
      {organisation && <input type="hidden" name="organisation_id" value={organisation} />}
      <div className="text-[13px] font-bold text-muted">{organisation ? "Another company in your organisation" : "Step 2 of 6"}</div>
      <h2 className="mb-6 mt-1.5 text-[26px] font-extrabold tracking-tight">{organisation ? "Add a company" : "Tell us about your business"}</h2>

      <div className="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-[18px]">
        <Field id="legal_name" label="Legal name" required invalid={bad("legal_name")} />
        <Field id="trading_name" label="Trading name (optional)" invalid={bad("trading_name")} />
        <div>
          <label className="field-label" htmlFor="country_code">Country</label>
          <select className="field" id="country_code" name="country_code" defaultValue="ET" aria-describedby="country_help">
            <optgroup label="With local taxes and e-invoicing">
              {COUNTRIES.filter((c) => c.localised).map((c) => <option key={c.code} value={c.code}>{c.name}</option>)}
            </optgroup>
            <optgroup label="All other countries (you enter your own tax rates)">
              {COUNTRIES.filter((c) => !c.localised).map((c) => <option key={c.code} value={c.code}>{c.name}</option>)}
            </optgroup>
          </select>
          <p id="country_help" className="mt-1.5 text-[13px] text-muted">Books follow IFRS for SMEs in every country.</p>
        </div>
        <div>
          <label className="field-label" htmlFor="base_currency">Currency of your books</label>
          <select className="field" id="base_currency" name="base_currency" defaultValue="ETB">
            {CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
        <Field id="tin" label="Tax ID (TIN)" invalid={bad("tin")} />
        <Field id="phone" label="Phone" type="tel" invalid={bad("phone")} />
        <Field id="email" label="Company email" type="email" invalid={bad("email")} />
        <Field id="website" label="Website" type="url" invalid={bad("website")} />
        <FiscalStartFields calendars={["gregorian", "ethiopian"]} defaultCalendar="gregorian" defaultMonth={7} ethiopianLabel="Ethiopian (tax year from Hamle)" />
        <div>
          <label className="field-label" htmlFor="date_format">Date format</label>
          <select className="field" id="date_format" name="date_format" defaultValue="DD/MM/YYYY">
            <option value="DD/MM/YYYY">DD/MM/YYYY (27/11/2026)</option>
            <option value="MM/DD/YYYY">MM/DD/YYYY (11/27/2026)</option>
            <option value="YYYY-MM-DD">YYYY-MM-DD (2026-11-27)</option>
          </select>
          <p className="mt-1.5 text-[13px] text-muted">How every date is written. You can change it later in Settings.</p>
        </div>
        <div>
          <label className="field-label" htmlFor="conversion_date">Last bank statement date</label>
          <DateInput className="field" id="conversion_date" name="conversion_date" />
          <p className="mt-1.5 text-[13px] text-muted">Opening balances are entered as at this date.</p>
        </div>
      </div>

      <fieldset className="mt-6">
        <legend className="field-label">What does your business do? (adds the right accounts)</legend>
        <div className="grid grid-cols-[repeat(auto-fill,minmax(170px,1fr))] gap-2.5">
          {INDUSTRIES.map(({ key, name }) => (
            <label key={key} className="flex min-h-16 cursor-pointer items-center rounded-xl border border-[#d5dcd8] bg-surface p-3.5 text-sm font-semibold has-[:checked]:border-2 has-[:checked]:border-brand has-[:checked]:bg-[#f0faf7]">
              <input type="radio" name="industry" value={key} defaultChecked={key === "retail_wholesale"} className="sr-only" />
              {name}
            </label>
          ))}
        </div>
      </fieldset>

      <label className="mt-6 flex items-center gap-2.5 text-sm font-semibold">
        <input type="checkbox" name="vat_registered" className="size-4" /> The company is registered for VAT
      </label>

      <div className="mt-7 flex flex-wrap items-center gap-2.5">
        <button type="submit" className="btn btn-primary min-w-[200px] justify-center" disabled={pending}>
          {pending ? "Setting up your books…" : "Create company"}
        </button>
      </div>
    </form>
  );
}

function Field({ id, label, type = "text", required, invalid }: { id: string; label: string; type?: string; required?: boolean; invalid?: boolean }) {
  return (
    <div>
      <label className="field-label" htmlFor={id}>{label}</label>
      {type === "date" ? <DateInput className={`field ${invalid ? "!border-bad" : ""}`} id={id} name={id} required={required} aria-invalid={invalid || undefined} /> : (
        <input className={`field ${invalid ? "!border-bad" : ""}`} id={id} name={id} type={type} required={required} aria-invalid={invalid || undefined} />)}
    </div>
  );
}
