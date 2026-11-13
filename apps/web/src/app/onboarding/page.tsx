import Link from "next/link";
import { COUNTRIES, INDUSTRIES } from "@/lib/countries";
import { CURRENCIES } from "@/lib/decimal";

export const metadata = { title: "Set up your company" };

const steps = [
  { title: "Your account", note: "Email or phone, verified", state: "done" },
  { title: "Business profile", note: "Name, country, tax ID, industry", state: "current" },
  { title: "Money settings", note: "Fiscal year, currency, calendar", state: "todo" },
  { title: "Connect money", note: "Last statement date, opening balances", state: "todo" },
  { title: "E-invoicing", note: "Tax-authority account", state: "todo" },
  { title: "Team and accountant", note: "Invite people with roles", state: "todo" },
] as const;

const months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

/**
 * Company setup. Posts to POST /api/v1/companies, which installs the IFRS for SMEs chart, the accounts
 * for the chosen industry and, for a country with its own pack (Ethiopia first), its tax rates, tax rules
 * and e-invoicing connector. Any other country works too: the company picks its currency here and enters
 * its own tax rates afterwards.
 */
export default function OnboardingPage() {
  return (
    <div className="flex min-h-screen flex-wrap">
      <aside className="max-w-full flex-[1_1_340px] bg-side px-9 py-8 text-white">
        <div className="text-xl font-extrabold tracking-tight">Ledgerly</div>
        <h1 className="mt-12 text-[30px] font-extrabold leading-tight tracking-tight">Set up your books in about 10 minutes</h1>
        <p className="mb-7 mt-2.5 text-[15px] leading-relaxed text-[#a9bdb7]">Skip any step and finish it later from your home screen.</p>
        <ol aria-label="Setup steps">
          {steps.map((s, i) => (
            <li key={s.title} aria-current={s.state === "current" ? "step" : undefined} className="flex items-start gap-3 py-2.5">
              <span className={`grid size-7 shrink-0 place-items-center rounded-full text-[13px] font-extrabold ${
                s.state === "done" ? "bg-mint text-side" : s.state === "current" ? "bg-white text-side" : "border border-[#3a5f55] text-[#a9bdb7]"
              }`}>{s.state === "done" ? "✓" : i + 1}</span>
              <span><b>{s.title}</b><br /><span className="text-[13px] text-side-label">{s.note}</span></span>
            </li>
          ))}
        </ol>
      </aside>

      <main className="min-w-0 flex-[999_1_560px] px-12 py-10">
        <form className="max-w-[760px]">
          <div className="text-[13px] font-bold text-muted">Step 2 of 6</div>
          <h2 className="mb-6 mt-1.5 text-[26px] font-extrabold tracking-tight">Tell us about your business</h2>

          <div className="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-[18px]">
            <Field id="legal_name" label="Legal name" defaultValue="Abyssinia Coffee Traders PLC" />
            <Field id="trading_name" label="Trading name" defaultValue="Abyssinia Coffee" />
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
            <Field id="tin" label="Tax ID (TIN)" defaultValue="0098765432" />
            <Field id="phone" label="Phone" defaultValue="+251 11 555 0142" type="tel" />
            <Field id="email" label="Email" defaultValue="accounts@abyssiniacoffee.example" type="email" />
            <Field id="website" label="Website" defaultValue="https://abyssiniacoffee.example" type="url" />
            <div>
              <label className="field-label" htmlFor="calendar">Calendar</label>
              <select className="field" id="calendar" name="calendar" defaultValue="gregorian">
                <option value="gregorian">Gregorian</option>
                <option value="ethiopian">Ethiopian (year starts in Hamle)</option>
              </select>
            </div>
            <div>
              <label className="field-label" htmlFor="fiscal_year_start_month">Fiscal year starts in</label>
              <select className="field" id="fiscal_year_start_month" name="fiscal_year_start_month" defaultValue="7">
                {months.map((m, i) => <option key={m} value={i + 1}>{m}{i === 6 ? " (usual in Ethiopia)" : ""}</option>)}
              </select>
            </div>
            <div>
              <label className="field-label" htmlFor="conversion_date">Last bank statement date</label>
              <input className="field" id="conversion_date" name="conversion_date" type="date" defaultValue="2026-10-31" />
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

          <div className="mt-7 flex flex-wrap items-center gap-2.5">
            <Link href="/" className="btn">Back</Link>
            <button type="submit" className="btn btn-primary min-w-[200px] justify-center">Continue</button>
            <Link href="/" className="ml-1.5 text-sm font-bold text-brand">Skip for now</Link>
          </div>
        </form>
      </main>
    </div>
  );
}

function Field({ id, label, defaultValue, type = "text" }: { id: string; label: string; defaultValue: string; type?: string }) {
  return (
    <div>
      <label className="field-label" htmlFor={id}>{label}</label>
      <input className="field" id={id} name={id} type={type} defaultValue={defaultValue} />
    </div>
  );
}
