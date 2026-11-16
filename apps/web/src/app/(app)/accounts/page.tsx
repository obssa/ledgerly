import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { byType, KIND_LABELS, naturalBalance } from "@/lib/chart";
import { getChart } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { AddAccount } from "./AccountForms";

export const metadata = { title: "Chart of accounts" };

/** Module 6: the chart of accounts, by type, each account under its parent, with its balance. */
export default async function ChartPage({ searchParams }: PageProps<"/accounts">) {
  const archived = (await searchParams).archived === "1";
  const chart = await getChart(archived);

  return (
    <>
      <PageTitle eyebrow="Books" title="Chart of accounts"
        actions={<Link href={archived ? "/accounts" : "/accounts?archived=1"} className="btn">{archived ? "Hide archived" : "Show archived"}</Link>} />
      {chart.demo && <p className="mb-4 text-[14px] text-muted">Not available in the demo.</p>}
      {byType(chart.data).map((group) => (
        <Card key={group.type} className="mb-4 !p-2" label={group.label}>
          <h2 className="px-3 pt-2 text-[15px] font-bold">{group.label}</h2>
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead><tr><th scope="col">Code</th><th scope="col">Account</th><th scope="col">Kind</th><th scope="col" className="!text-right">Balance</th></tr></thead>
              <tbody>
                {group.accounts.map((a) => (
                  <tr key={a.id} className={a.is_active ? "" : "text-muted"}>
                    <td className="num">{a.code}</td>
                    <td style={{ paddingInlineStart: `${0.75 + (a.depth ?? 0) * 1.25}rem` }}>
                      <Link href={a.is_money ? `/banking/${encodeURIComponent(a.id)}` : `/accounts/${encodeURIComponent(a.id)}`} className="font-semibold hover:underline">{a.name}</Link>
                      {a.is_system && <span className="ml-2"><Pill>System</Pill></span>}
                      {!a.is_active && <span className="ml-2"><Pill>Archived</Pill></span>}
                      {a.currency && <span className="ml-2 text-[12px] text-muted">{a.currency}</span>}
                    </td>
                    <td className="text-[13px] text-muted">{a.kind ? (KIND_LABELS[a.kind] ?? a.kind) : "—"}</td>
                    <td className="num text-right">{a.has_entries ? formatAmount(naturalBalance(a.type, a.balance)) : ""}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      ))}
      {chart.can.manage && (
        <Card className="max-w-[1000px]" label="Add an account">
          <h2 className="mb-2 text-[15px] font-bold">Add an account</h2>
          <AddAccount accounts={chart.data} kinds={chart.kinds} />
        </Card>
      )}
    </>
  );
}
