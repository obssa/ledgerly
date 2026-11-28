import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { KIND_LABELS, TYPE_LABELS, type AccountType } from "@/lib/chart";
import { getIndustryTemplates } from "@/lib/data";
import { AddIndustryAccounts } from "../AccountForms";

export const metadata = { title: "Industry accounts" };

const STATUS = {
  have: { tone: "good", text: "In the chart" },
  taken: { tone: "warn", text: "Code used by another account" },
  add: { tone: "neutral", text: "To add" },
} as const;

/**
 * Module 1: the accounts each type of business usually needs, as set up for a new company. For a company that changes
 * its line of business, or started without one: pick one and add what the chart lacks.
 */
export default async function IndustryAccountsPage({ searchParams }: PageProps<"/accounts/industry">) {
  const { industry } = await searchParams;
  const t = await getIndustryTemplates();
  if (!t) {
    return (
      <>
        <PageTitle title="Industry accounts" />
        <p className="text-[14px] text-muted">Not available in the demo.</p>
      </>
    );
  }
  const key = typeof industry === "string" && t.data.some((d) => d.key === industry) ? industry : (t.industry ?? t.data[0]?.key);
  const chosen = t.data.find((d) => d.key === key);

  return (
    <>
      <PageTitle title="Industry accounts"
        actions={<Link href="/accounts" className="btn">Chart of accounts</Link>} />
      <div className="flex flex-wrap items-start gap-4">
        <nav aria-label="Industries" className="flex min-w-[220px] flex-[1_1_220px] flex-col gap-1 text-[14px]">
          {t.data.map((d) => (
            <Link key={d.key} href={`/accounts/industry?industry=${encodeURIComponent(d.key)}`} aria-current={d.key === key ? "page" : undefined}
              className={`block rounded-lg px-3 py-2 text-sm font-semibold ${d.key === key ? "bg-[#f0faf7] text-brand" : "hover:bg-[#f0faf7]"}`}>
              {d.name}{d.key === t.industry && <span className="ml-1 text-[12px] text-muted">(yours)</span>}
              {d.to_add > 0 && <span className="num ml-1 text-[12px] text-muted">+{d.to_add}</span>}
            </Link>
          ))}
        </nav>
        {chosen && (
          <Card className="min-w-0 flex-[4_1_560px]" label={chosen.name}>
            <h2 className="mb-2 text-[15px] font-bold">{chosen.name}</h2>
            <p className="mb-3 text-[13px] text-muted">
              What a new {chosen.name.toLowerCase()} company starts with on top of the standard chart. Adding puts in only the accounts the chart lacks;
              an account already in the chart, or a code another account uses, is left as it is.
            </p>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Code</th><th scope="col">Account</th><th scope="col">Type</th><th scope="col">Kind</th><th scope="col">In your chart</th></tr></thead>
                <tbody>
                  {chosen.accounts.map((a) => (
                    <tr key={a.code}>
                      <td className="num">{a.code}</td>
                      <td>{a.name}</td>
                      <td className="text-[13px] text-muted">{TYPE_LABELS[a.type as AccountType] ?? a.type}</td>
                      <td className="text-[13px] text-muted">{a.subtype ? (KIND_LABELS[a.subtype] ?? a.subtype) : "—"}</td>
                      <td><Pill tone={STATUS[a.status].tone}>{STATUS[a.status].text}</Pill></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {t.can.manage && <div className="mt-3"><AddIndustryAccounts industry={chosen.key} count={chosen.to_add} /></div>}
          </Card>
        )}
      </div>
    </>
  );
}
