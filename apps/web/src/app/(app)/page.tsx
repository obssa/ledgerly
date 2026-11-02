import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { demoAccounts, demoKpis } from "@/lib/demo";
import { getInvoices } from "@/lib/data";
import { formatAmount, formatMoney, toEthiopian } from "@/lib/format";

export const metadata = { title: "Home" };

function daysOverdue(due: string | null, today: Date): number {
  if (!due) return 0;
  return Math.max(0, Math.floor((today.getTime() - new Date(`${due}T00:00:00Z`).getTime()) / 86_400_000));
}

export default async function HomePage() {
  const today = new Date("2026-11-02T00:00:00Z"); // demo "today"; use new Date() with live data
  const invoices = await getInvoices();
  const overdue = invoices
    .filter((i) => i.status === "posted" && daysOverdue(i.due_date, today) > 0)
    .sort((a, b) => daysOverdue(b.due_date, today) - daysOverdue(a.due_date, today))
    .slice(0, 4);
  const k = demoKpis;

  return (
    <>
      <PageTitle
        title="Good evening, Hana"
        eyebrow={`Monday, 2 November 2026 · ${toEthiopian(today).label} · Books closed to 30 September`}
      />

      <section aria-label="Key figures" className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-4">
        <Kpi label="Cash and wallets" value={formatMoney(k.cash, "ETB")} note="▲ 6.1% since last month" tone="good" />
        <Kpi label="Customers owe you" value={formatMoney(k.receivable, "ETB")} note={`${k.overdueCount} invoices overdue · ETB ${formatAmount(k.overdue, "ETB")}`} tone="bad" />
        <Kpi label="You owe vendors" value={formatMoney(k.payable, "ETB")} note={`${k.billsDueThisWeek} bills due this week`} />
        <Kpi label="Net profit · October" value={formatMoney(k.netProfit, "ETB")} note="▲ 8.4% vs September" tone="good" />
      </section>

      <div className="mt-4 flex flex-wrap gap-4">
        <Card className="min-w-0 flex-[2_1_520px]" label="Overdue invoices">
          <div className="flex items-center justify-between">
            <h2 className="text-[17px] font-bold">Overdue invoices</h2>
            <Link href="/invoices" className="text-sm font-semibold text-brand">View all</Link>
          </div>
          <div className="mt-2.5 overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Customer</th><th scope="col">Invoice</th><th scope="col">Overdue</th><th scope="col" className="!text-right">Amount</th></tr>
              </thead>
              <tbody>
                {overdue.map((inv) => {
                  const days = daysOverdue(inv.due_date, today);
                  return (
                    <tr key={inv.id}>
                      <td className="font-semibold">{inv.contact.name}</td>
                      <td className="num">{inv.number}</td>
                      <td><Pill tone={days > 30 ? "bad" : "warn"}>{days} days</Pill></td>
                      <td className="num text-right">{formatMoney(inv.amount_due, inv.currency)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>

        <Card className="flex min-w-0 flex-[1_1_300px] flex-col gap-3.5" label="Tax and compliance">
          <h2 className="text-[17px] font-bold">Tax and compliance</h2>
          <div className="rounded-xl bg-warn-tint p-3.5">
            <div className="text-[13px] font-bold text-warn">VAT return due in {k.vatDueDays} days</div>
            <div className="num mt-1.5 text-[22px] font-semibold">{formatMoney(k.vatDue, "ETB")}</div>
          </div>
          <dl className="text-sm">
            <Row label="E-invoices cleared" value={k.einvoices.cleared} tone="text-good" />
            <Row label="Waiting to send (offline)" value={k.einvoices.waiting} tone="text-warn" />
            <Row label="Rejected" value={k.einvoices.rejected} tone="text-bad" />
          </dl>
        </Card>
      </div>

      <Card className="mt-4" label="Bank and wallets">
        <h2 className="text-[17px] font-bold">Bank and wallets</h2>
        <ul className="mt-2 grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-x-8">
          {demoAccounts.map((a) => (
            <li key={a.name} className="flex items-center justify-between border-b border-line-soft py-3">
              <div>
                <div className="text-sm font-semibold">{a.name}</div>
                <div className="text-[13px] text-muted">{a.note}</div>
              </div>
              <div className="num font-semibold">{formatAmount(a.balance, "ETB")}</div>
            </li>
          ))}
        </ul>
      </Card>
    </>
  );
}

function Kpi({ label, value, note, tone }: { label: string; value: string; note: string; tone?: "good" | "bad" }) {
  return (
    <Card>
      <div className="text-[13px] text-muted">{label}</div>
      <div className="num mt-2 text-[24px] font-semibold">{value}</div>
      <div className={`mt-1.5 text-[13px] font-semibold ${tone === "good" ? "text-good" : tone === "bad" ? "text-bad" : "text-muted"}`}>{note}</div>
    </Card>
  );
}

function Row({ label, value, tone }: { label: string; value: number; tone: string }) {
  return (
    <div className="flex justify-between py-1.5">
      <dt>{label}</dt>
      <dd className={`num font-semibold ${tone}`}>{value}</dd>
    </div>
  );
}
