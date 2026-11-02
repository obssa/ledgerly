import Link from "next/link";

export const metadata = { title: "Set up your company" };

const steps = [
  { title: "Your account", note: "Email or phone, verified", state: "done" },
  { title: "Business profile", note: "Name, tax ID, industry", state: "current" },
  { title: "Money settings", note: "Fiscal year, currency, calendar", state: "todo" },
  { title: "Connect money", note: "Last statement date, opening balances", state: "todo" },
  { title: "E-invoicing", note: "Tax-authority account", state: "todo" },
  { title: "Team and accountant", note: "Invite people with roles", state: "todo" },
] as const;

const industries = [
  "Retail shop", "Wholesale and distribution", "Restaurant or café", "Manufacturing", "Import and export", "Construction",
  "Professional services", "Hotel and hospitality", "Clinic or pharmacy", "School", "Agriculture", "NGO or non-profit",
];

const months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];

/**
 * Company setup. Posts to POST /api/v1/companies, which installs the country pack
 * (chart of accounts for IFRS for SMEs or SYSCOHADA, tax rates, e-invoicing connector).
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
              <select className="field" id="country_code" name="country_code" defaultValue="ET">
                <option value="ET">Ethiopia · IFRS for SMEs</option>
                <option value="KE">Kenya · IFRS for SMEs</option>
                <option value="CI">Côte d&apos;Ivoire · SYSCOHADA</option>
              </select>
            </div>
            <Field id="tin" label="TIN" defaultValue="0098765432" />
            <div>
              <label className="field-label" htmlFor="fiscal_year_start_month">Fiscal year starts in</label>
              <select className="field" id="fiscal_year_start_month" name="fiscal_year_start_month" defaultValue="7">
                {months.map((m, i) => <option key={m} value={i + 1}>{m}{i === 6 ? " (Hamle, Ethiopian fiscal year)" : ""}</option>)}
              </select>
            </div>
            <div>
              <label className="field-label" htmlFor="conversion_date">Last bank statement date</label>
              <input className="field" id="conversion_date" name="conversion_date" type="date" defaultValue="2026-10-31" />
              <p className="mt-1.5 text-[13px] text-muted">Opening balances are entered as at this date.</p>
            </div>
          </div>

          <fieldset className="mt-6">
            <legend className="field-label">What does your business do?</legend>
            <div className="grid grid-cols-[repeat(auto-fill,minmax(170px,1fr))] gap-2.5">
              {industries.map((name) => (
                <label key={name} className="flex min-h-16 cursor-pointer items-center rounded-xl border border-[#d5dcd8] bg-surface p-3.5 text-sm font-semibold has-[:checked]:border-2 has-[:checked]:border-brand has-[:checked]:bg-[#f0faf7]">
                  <input type="radio" name="industry" value={name} defaultChecked={name === "Wholesale and distribution"} className="sr-only" />
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

function Field({ id, label, defaultValue }: { id: string; label: string; defaultValue: string }) {
  return (
    <div>
      <label className="field-label" htmlFor={id}>{label}</label>
      <input className="field" id={id} name={id} defaultValue={defaultValue} />
    </div>
  );
}
