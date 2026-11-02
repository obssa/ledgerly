import { Card, PageTitle, Pill } from "@/components/ui";
import type { ProfitAndLossRow } from "@/lib/api";
import { parse } from "@/lib/decimal";
import { getProfitAndLoss } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Profit and loss" };

const CURRENCY = "ETB";

function change(current: string, previous: string | null): string {
  if (!previous) return "—";
  const p = parse(previous);
  if (p === 0n) return "—";
  const c = parse(current);
  // percentage with one decimal, exact: (c - p) / |p| * 100
  const tenths = ((c - p) * 1000n) / (p < 0n ? -p : p);
  const sign = tenths >= 0n ? "+" : "−";
  const abs = tenths < 0n ? -tenths : tenths;
  return `${sign}${abs / 10n}.${abs % 10n}%`;
}

/** Report groups follow the accounting cycle: statements first, then the ledgers and checks behind them. */
const cycle = [
  { stage: "Statements", reports: ["Profit and loss", "Balance sheet (A = L + E checked)", "Cash flow statement"] },
  { stage: "Ledger and trial balance", reports: ["Trial balance", "General ledger", "Journal report"] },
  { stage: "Receivables and payables", reports: ["AR ageing", "AP ageing", "Customer statements"] },
  { stage: "Tax", reports: ["VAT return worksheet", "Withholding summary", "E-invoice register"] },
  { stage: "Operations", reports: ["Sales by item", "Inventory valuation", "Bin card (stock card)"] },
  { stage: "Financial health", reports: ["Liquidity ratios", "Cash runway", "Audit log of changes"] },
];

export default async function ReportsPage() {
  const pl = await getProfitAndLoss({ from: "2026-10-01", to: "2026-10-31", compare_from: "2026-09-01", compare_to: "2026-09-30" });
  const t = pl.totals;
  const margin = (num: string, den: string) => {
    const tenths = (parse(num) * 1000n) / parse(den);
    return `${tenths / 10n}.${tenths % 10n}%`;
  };

  const section = (title: string, rows: ProfitAndLossRow[], total: { current: string; previous: string | null }) => (
    <>
      <tr><th scope="rowgroup" colSpan={4} className="!border-0 !pt-5 !text-[13px] !text-ink">{title}</th></tr>
      {rows.map((r) => (
        <tr key={r.code}>
          <td className="pl-7">{r.name}</td>
          <td className="num text-right">{formatAmount(r.current, CURRENCY)}</td>
          <td className="num text-right">{r.previous ? formatAmount(r.previous, CURRENCY) : "—"}</td>
          <td className="num text-right">{change(r.current, r.previous)}</td>
        </tr>
      ))}
      <tr className="font-bold">
        <td>Total {title.toLowerCase()}</td>
        <td className="num text-right">{formatAmount(total.current, CURRENCY)}</td>
        <td className="num text-right">{total.previous ? formatAmount(total.previous, CURRENCY) : "—"}</td>
        <td className="num text-right">{change(total.current, total.previous)}</td>
      </tr>
    </>
  );

  const keyRow = (label: string, v: { current: string; previous: string | null }, dark = false) => (
    <tr className={dark ? "bg-side text-white font-extrabold" : "bg-[#f0faf7] font-extrabold"}>
      <td>{label}</td>
      <td className="num text-right">{formatAmount(v.current, CURRENCY)}</td>
      <td className="num text-right">{v.previous ? formatAmount(v.previous, CURRENCY) : "—"}</td>
      <td className="num text-right">{change(v.current, v.previous)}</td>
    </tr>
  );

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Profit and loss" actions={<><button className="btn">Export Excel</button><button className="btn btn-primary">Download PDF</button></>} />
      <div className="flex flex-wrap items-start gap-4">
        <nav aria-label="Reports by accounting cycle" className="min-w-0 flex-[1_1_220px] rounded-[14px] border border-line bg-surface p-3">
          {cycle.map((g) => (
            <div key={g.stage} className="mb-2">
              <div className="px-3 pb-1 pt-2 text-[13px] font-bold text-muted">{g.stage}</div>
              {g.reports.map((r) => (
                <a key={r} href="#" className={`block rounded-lg px-3 py-2 text-sm font-semibold ${r === "Profit and loss" ? "bg-[#f0faf7] text-brand" : "hover:bg-[#f0faf7]"}`}>{r}</a>
              ))}
            </div>
          ))}
        </nav>

        <Card className="min-w-0 flex-[4_1_560px]" label="Profit and loss statement">
          <div className="mb-3 text-center">
            <div className="text-[17px] font-extrabold">Abyssinia Coffee Traders PLC</div>
            <div className="text-[13px] text-muted">Profit and loss · 1–31 October 2026 · {CURRENCY}</div>
          </div>
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead><tr><th scope="col">Account</th><th scope="col" className="!text-right">Oct 2026</th><th scope="col" className="!text-right">Sep 2026</th><th scope="col" className="!text-right">Change</th></tr></thead>
              <tbody>
                {section("Income", pl.sections.income, t.income)}
                {section("Cost of goods sold", pl.sections.cost_of_sales, t.cost_of_sales)}
                {keyRow("Gross profit", t.gross_profit)}
                {section("Expenses", pl.sections.expenses, t.expenses)}
                {keyRow("Net profit before tax", t.net_profit, true)}
              </tbody>
            </table>
          </div>
        </Card>

        <aside className="flex min-w-0 flex-[1_1_240px] flex-col gap-4">
          <Card><div className="text-[13px] text-muted">Gross margin</div><div className="num mt-1.5 text-[26px] font-semibold">{margin(t.gross_profit.current, t.income.current)}</div></Card>
          <Card><div className="text-[13px] text-muted">Net margin</div><div className="num mt-1.5 text-[26px] font-semibold">{margin(t.net_profit.current, t.income.current)}</div></Card>
          <Card>
            <div className="text-[13px] text-muted">Accounting equation</div>
            <div className="mt-2"><Pill tone="good">Assets = Liabilities + Equity</Pill></div>
            <p className="mt-2 text-[13px] text-muted">Checked on every balance sheet; any difference is shown, never hidden.</p>
          </Card>
        </aside>
      </div>
    </>
  );
}
