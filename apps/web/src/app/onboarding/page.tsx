import { Suspense } from "react";
import { OnboardingForm } from "./OnboardingForm";

export const metadata = { title: "Set up your company" };

const steps = [
  { title: "Your account", note: "Email or phone, verified", state: "done" },
  { title: "Business profile", note: "Name, country, tax ID, industry", state: "current" },
  { title: "Money settings", note: "Fiscal year, currency, calendar", state: "todo" },
  { title: "Connect money", note: "Last statement date, opening balances", state: "todo" },
  { title: "E-invoicing", note: "Tax-authority account", state: "todo" },
  { title: "Team and accountant", note: "Invite people with roles", state: "todo" },
] as const;

/**
 * Company setup. Posts to POST /api/v1/companies, which installs the IFRS for SMEs chart, the accounts
 * for the chosen industry and, for a country with its own pack (Ethiopia first), its tax rates, tax rules
 * and e-invoicing connector. Any other country works too: the company picks its currency here and enters
 * its own tax rates afterwards.
 */
/** Reads ?organisation= on the server, so the form is in the HTML; the plain form is the static fallback. */
async function Form({ searchParams }: Pick<PageProps<"/onboarding">, "searchParams">) {
  const { organisation } = await searchParams;
  return <OnboardingForm organisation={typeof organisation === "string" && /^[0-9a-f-]{36}$/i.test(organisation) ? organisation : null} />;
}

export default function OnboardingPage({ searchParams }: PageProps<"/onboarding">) {
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
        <Suspense fallback={<OnboardingForm />}>
          <Form searchParams={searchParams} />
        </Suspense>
      </main>
    </div>
  );
}

