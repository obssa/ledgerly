import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { byType, KIND_LABELS, naturalBalance } from "@/lib/chart";
import { getChart, getCurrencies } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { AddAccount } from "./AccountForms";

export const metadata = { title: "Chart of accounts" };

/** Module 6: the chart of accounts, by type, each account under its parent, with its balance. */
export default async function ChartPage({ searchParams }: PageProps<"/accounts">) {
  const archived = (await searchParams).archived === "1";
  const [chart, currencies] = await Promise.all([getChart(archived), getCurrencies().catch(() => null)]);
  const base = currencies?.base_currency;
  const foreign = chart.data.some((a) => a.foreign_balance !== undefined);

  return (
    <>
      <PageTitle eyebrow="Books" title="Chart of accounts"
        actions={<>
          {!chart.demo && <Link href="/accounts/industry" className="btn">Industry accounts</Link>}
          <Link href={archived ? "/accounts" : "/accounts?archived=1"} className="btn">{archived ? "Hide archived" : "Show archived"}</Link>
        </>} />
      {chart.demo && <p className="mb-4 text-[14px] text-muted">Not available in the demo.</p>}
      {byType(chart.data).map((group) => (
        <Card key={group.type} className="mb-4 !p-2" label={group.label}>
          <h2 className="px-3 pt-2 text-[15px] font-bold">{group.label}</h2>
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead><tr><th scope="col">Code</th><th scope="col">Account</th><th scope="col">Kind</th>{foreign && <><th scope="col">Currency</th><th scope="col" className="!text-right">Foreign balance</th></>}<th scope="col" className="!text-right">{foreign && base ? `Balance (${base})` : "Balance"}</th></tr></thead>
              <tbody>
                {group.accounts.map((a) => (
                  <tr key={a.id} className={a.is_active ? "" : "text-muted"}>
                    <td className="num">{a.code}</td>
                    <td style={{ paddingInlineStart: `${0.75 + (a.depth ?? 0) * 1.25}rem` }}>
                      <Link href={a.is_money ? `/banking/${encodeURIComponent(a.id)}` : `/accounts/${encodeURIComponent(a.id)}`} className="font-semibold hover:underline">{a.name}</Link>
                      {a.is_system && <span className="ml-2"><Pill>System</Pill></span>}
                      {!a.is_active && <span className="ml-2"><Pill>Archived</Pill></span>}
                    </td>
                    <td className="text-[13px] text-muted">{a.kind ? (KIND_LABELS[a.kind] ?? a.kind) : "—"}</td>
                    {foreign && <><td className="text-[13px]">{a.currency ?? base ?? ""}</td>
                      <td className="num text-right">{a.foreign_balance !== undefined && a.has_entries ? formatAmount(naturalBalance(a.type, a.foreign_balance)) : ""}</td></>}
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
