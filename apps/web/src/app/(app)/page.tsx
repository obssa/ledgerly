import { fromGregorian, toGregorian } from "@/lib/ethiopian";
import { formatDate, type DatePrefs } from "@/lib/dates";
import { shiftDay, splitRange, type Calendar } from "@/lib/periods";
import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { connection } from "next/server";
import { Card, PageTitle, Pill, Stat } from "@/components/ui";
import { AgeingBar, BalanceLine, PairedColumns, type ColumnPoint } from "@/components/charts";
import { Icon } from "@/components/app-shell/Icon";
import { demoAccounts, demoKpis } from "@/lib/demo";
import { ApiError, isDemoMode, type ArAgeing, type BankAccount, type CashForecast, type Kpis } from "@/lib/api";
import { getApAgeing, getArAgeing, getBankAccounts, getCashForecast, getCompanyDay, getInvoices, getKpis, getProfitAndLoss } from "@/lib/data";
import { getSession } from "@/lib/session";
import { parse, toString } from "@/lib/decimal";
import { formatAmount, formatLongDate, formatMoney } from "@/lib/format";

export const metadata = { title: "Overview" };

function daysOverdue(due: string | null, today: Date): number {
  if (!due) return 0;
  return Math.max(0, Math.floor((today.getTime() - new Date(`${due}T00:00:00Z`).getTime()) / 86_400_000));
}

/** A part of the overview the signed-in role may not see (or the books can't answer yet) is left out, not an error. */
async function optional<T>(load: () => Promise<T>): Promise<T | null> {
  try {
    return await load();
  } catch (error) {
    if (error instanceof ApiError && [403, 404, 422].includes(error.status)) return null;
    throw error;
  }
}

const monthName = (d: Date, long = false) => d.toLocaleDateString("en-GB", { month: long ? "long" : "short", timeZone: "UTC" });

/** The last six months of the company's calendar (Gregorian or Ethiopian), this one to today. */
function lastMonths(today: string, calendar: Calendar, count = 6): { label: string; from: string; to: string }[] {
  const back = shiftDay(today, -(count - 1), calendar);
  const e = fromGregorian(back);
  const first = calendar === "ethiopian" ? toGregorian(e.year, Math.min(e.month, 12), 1) : `${back.slice(0, 8)}01`;
  const months = splitRange({ from: first, to: today }, "month", calendar) ?? [];
  return months.slice(-count).map((m) => ({ ...m, label: m.label.split(" ")[0] }));
}

const sum = (...values: string[]) => toString(values.reduce((acc, v) => acc + parse(v), 0n));
const greeting = (hour: number) => (hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening");

export default async function OverviewPage() {
  await connection(); // per request: one built image runs in demo mode or against an API
  const demo = isDemoMode();
  // One round for everything that doesn't depend on today; the profit and loss by month follows once today is known.
  const firstRound = demo ? null : Promise.all([
    getKpis(),
    getCashForecast(13),
    optional(() => getArAgeing()),
    optional(() => getApAgeing()),
    optional(() => getBankAccounts()),
  ]);
  firstRound?.catch(() => {}); // awaited below; a failure is reported there, not as an unhandled rejection
  // Today, written in the calendar chosen in Settings (Gregorian or Ethiopian), never both.
  const [invoices, { today: todayIso, calendar, dateFormat, timezone }] = await Promise.all([
    getInvoices(),
    demo ? { today: "2026-11-02", calendar: "gregorian" as const, dateFormat: "DD/MM/YYYY" as const, timezone: "Africa/Addis_Ababa" } : getCompanyDay(),
  ]);
  const today = new Date(`${todayIso}T00:00:00Z`);
  // The greeting by the hour where the company is (Settings, Time zone).
  const hourIn = (zone: string) => Number(new Intl.DateTimeFormat("en-GB", { hour: "2-digit", hourCycle: "h23", timeZone: zone }).format(new Date()));
  let hour: number;
  try { hour = hourIn(timezone || "Africa/Addis_Ababa"); } catch { hour = hourIn("Africa/Addis_Ababa"); }
  const firstName = demo ? "Hana" : ((await getSession()).name ?? "").split(" ")[0];

  const overdue = invoices
    .filter((i) => (i.status === "posted" || i.status === "part_paid") && daysOverdue(i.due_date, today) > 0)
    .sort((a, b) => daysOverdue(b.due_date, today) - daysOverdue(a.due_date, today))
    .slice(0, 6);

  const months = lastMonths(todayIso, calendar);
  const [[kpis, forecast, ar, ap, banks], pnl] = firstRound
    ? await Promise.all([firstRound, Promise.all(months.map((m) => optional(() => getProfitAndLoss({ from: m.from, to: m.to }))))])
    : [[null, null, null, null, null], null];

  const currency = kpis?.currency ?? "ETB";
  const columns: ColumnPoint[] | null = pnl && pnl.every(Boolean)
    ? months.map((m, i) => {
        const t = pnl[i]!.totals;
        const spent = sum(t.cost_of_sales.current, t.expenses.current);
        return { label: m.label, a: Number(t.income.current), b: Number(spent), aText: formatMoney(t.income.current, currency), bText: formatMoney(spent, currency) };
      })
    : null;

  return (
    <>
      <PageTitle
        kicker={formatLongDate(today, { calendar, dateFormat })}
        title={firstName ? `${greeting(hour)}, ${firstName}` : greeting(hour)}
        description="Where the business stands today: cash, who owes whom, and what the next weeks look like."
        actions={
          <>
            <Link href="/payments/new" className="btn"><Icon name="receivable" className="size-4" />Receive a payment</Link>
            <Link href="/payments-made/batch" className="btn"><Icon name="payable" className="size-4" />Pay bills</Link>
            <Link href="/invoices/new" className="btn btn-primary"><Icon name="plus" className="size-4" />New invoice</Link>
          </>
        }
      />

      {kpis ? <KeyFigures k={kpis} /> : demo ? <DemoFigures /> : null}

      {!demo && (
        <div className="mt-4 grid gap-4 xl:grid-cols-3">
          <Card className="min-w-0 xl:col-span-2" label="Income and spending">
            <CardHead title="Income and spending" note={`Last six months · ${currency} · accrual basis`} href="/reports" link="Profit and loss" />
            {columns ? (
              <PairedColumns title="Income and spending by month" points={columns} a="Income" b="Spending" />
            ) : (
              <Empty text="Profit and loss appears here for roles that can see reports." />
            )}
          </Card>
          <Attention k={kpis} forecast={forecast} overdueCount={kpis?.receivable.overdue_count ?? overdue.length} prefs={{ calendar, dateFormat }} />
        </div>
      )}

      {!demo && (
        <div className="mt-4 grid gap-4 xl:grid-cols-3">
          <Card className="min-w-0 xl:col-span-2" label="Cash outlook">
            <CardHead title="Cash outlook" note="Expected bank and cash balance, the next 13 weeks" href="/reports/cash-forecast" link="Cash forecast" />
            {forecast && forecast.data.length > 0 ? (
              <BalanceLine
                title="Expected cash balance by week"
                tableHead={["Week starting", `Balance (${currency})`]}
                points={[
                  { label: "Now", value: Number(forecast.opening), text: formatMoney(forecast.opening, currency) },
                  ...forecast.data.map((w) => ({ label: `W${w.week}`, value: Number(w.closing), text: formatMoney(w.closing, currency) })),
                ]}
              />
            ) : (
              <Empty text="The forecast appears once there are bank accounts and open invoices or bills." />
            )}
          </Card>
          <Banks banks={banks?.data ?? null} />
        </div>
      )}

      {!demo && (ar || ap) && (
        <div className="mt-4 grid gap-4 lg:grid-cols-2">
          {ar && <Ageing title="Customers owe you" ageing={ar} currency={currency} href="/reports/ar-ageing" link="AR ageing" contactHref="/customers" />}
          {ap && <Ageing title="You owe suppliers" ageing={ap} currency={currency} href="/reports/ap-ageing" link="AP ageing" contactHref="/suppliers" />}
        </div>
      )}

      <div className="mt-4 grid gap-4 xl:grid-cols-3">
        <Card className="min-w-0 xl:col-span-2 !p-0" label="Overdue invoices">
          <div className="p-5 pb-3"><CardHead title="Overdue invoices" note="Oldest first" href="/invoices" link="All invoices" /></div>
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col" className="!pl-5">Customer</th><th scope="col">Invoice</th><th scope="col">Due</th><th scope="col">Late by</th><th scope="col" className="!pr-5 !text-right">Amount due</th></tr>
              </thead>
              <tbody>
                {overdue.length === 0 && (
                  <tr><td colSpan={5} className="py-8 text-center text-muted">Nothing overdue. Every customer is paying on time.</td></tr>
                )}
                {overdue.map((inv) => {
                  const days = daysOverdue(inv.due_date, today);
                  return (
                    <tr key={inv.id}>
                      <td className="!pl-5 font-semibold"><Link href={`/invoices/${inv.id}`} className="text-ink no-underline hover:underline">{inv.contact.name}</Link></td>
                      <td className="num">{inv.number ?? <span className="text-muted">Draft</span>}</td>
                      <td className="num text-muted"><Dt v={inv.due_date} /></td>
                      <td><Pill tone={days > 30 ? "bad" : "warn"}>{days} days</Pill></td>
                      <td className="num !pr-5 text-right font-semibold">{formatMoney(inv.amount_due, inv.currency)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
        {demo ? <DemoTax /> : <Shortcuts />}
      </div>

      {demo && <DemoBanks />}
    </>
  );
}

function CardHead({ title, note, href, link }: { title: string; note?: string; href?: string; link?: string }) {
  return (
    <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 className="text-[15.5px] font-bold tracking-[-0.01em]">{title}</h2>
        {note && <p className="mt-0.5 text-[12.5px] text-muted">{note}</p>}
      </div>
      {href && link && (
        <Link href={href} className="inline-flex items-center gap-1 text-[13px] font-semibold text-brand no-underline hover:underline">
          {link}<Icon name="arrowRight" className="size-3.5" />
        </Link>
      )}
    </div>
  );
}

function Empty({ text }: { text: string }) {
  return <p className="grid h-40 place-items-center rounded-xl border border-dashed border-line text-center text-sm text-muted">{text}</p>;
}

/** "▲ ETB 12,000.00 since a month ago": the direction in words and arrow, not colour alone. */
function moved(change: string, currency: string, since: string) {
  const down = change.startsWith("-");
  return (
    <span className="inline-flex items-center gap-1">
      <Icon name={down ? "arrowDown" : "arrowUp"} className="size-3.5" />
      <span className="sr-only">{down ? "Down" : "Up"}</span>
      {formatMoney(down ? change.slice(1) : change, currency)} {since}
    </span>
  );
}

function KeyFigures({ k }: { k: Kpis }) {
  const month = monthName(new Date(`${k.month.from}T00:00:00Z`), true);
  const negate = (v: string) => (v.startsWith("-") ? v.slice(1) : `-${v}`);
  const profitChange = k.month.net_profit_last !== null ? sum(k.month.net_profit, negate(k.month.net_profit_last)) : null;
  return (
    <section aria-label="Key figures" className="grid grid-cols-[repeat(auto-fit,minmax(230px,1fr))] gap-4">
      <Stat label="Cash and wallets" value={formatMoney(k.cash.now, k.currency)} icon={<Icon name="wallet" />} href="/banking"
        note={moved(k.cash.change, k.currency, "in a month")} tone={k.cash.change.startsWith("-") ? "bad" : "good"} />
      <Stat label="Customers owe you" value={formatMoney(k.receivable.balance, k.currency)} icon={<Icon name="receivable" />} href="/reports/ar-ageing"
        note={k.receivable.overdue_count > 0 ? `${k.receivable.overdue_count} overdue · ${formatMoney(k.receivable.overdue, k.currency)}` : "Nothing overdue"}
        tone={k.receivable.overdue_count > 0 ? "bad" : "good"} />
      <Stat label="You owe suppliers" value={formatMoney(k.payable.balance, k.currency)} icon={<Icon name="payable" />} href="/reports/ap-ageing"
        note={k.payable.due_this_week_count > 0 ? `${k.payable.due_this_week_count} due within a week · ${formatMoney(k.payable.due_this_week, k.currency)}` : "Nothing due this week"} />
      <Stat label={`Net profit · ${month} so far`} value={formatMoney(k.month.net_profit, k.currency)} icon={<Icon name="profit" />} href="/reports"
        note={profitChange ? moved(profitChange, k.currency, "vs last month") : `Sales ${formatMoney(k.month.sales, k.currency)}`}
        tone={profitChange ? (profitChange.startsWith("-") ? "bad" : "good") : undefined} />
    </section>
  );
}

/** What needs doing, from the figures already loaded: no extra calls. */
function Attention({ k, forecast, overdueCount, prefs }: { k: Kpis | null; forecast: CashForecast | null; overdueCount: number; prefs: DatePrefs }) {
  const items: { tone: "bad" | "warn" | "good"; title: string; note: string; href: string }[] = [];
  const cur = k?.currency ?? "ETB";
  if (forecast?.goes_below_zero && forecast.lowest) {
    items.push({ tone: "bad", title: "Cash runs short", note: `Lowest ${formatMoney(forecast.lowest.closing, cur)} in the week of ${formatDate(forecast.lowest.from, prefs)}`, href: "/reports/cash-forecast" });
  }
  if (overdueCount > 0) items.push({ tone: "bad", title: `${overdueCount} invoice${overdueCount === 1 ? "" : "s"} overdue`, note: k ? `${formatMoney(k.receivable.overdue, cur)} to chase` : "Send reminders or statements", href: "/reports/ar-ageing" });
  if (k && k.payable.due_this_week_count > 0) items.push({ tone: "warn", title: `${k.payable.due_this_week_count} bill${k.payable.due_this_week_count === 1 ? "" : "s"} due this week`, note: `${formatMoney(k.payable.due_this_week, cur)} to pay`, href: "/payments-made/batch" });
  items.push({ tone: "warn", title: "Reconcile the bank", note: "Match statement lines to the books", href: "/banking" });
  items.push({ tone: "warn", title: "Close the month", note: "Work through the period-end checklist", href: "/close" });
  const dot = { bad: "bg-bad", warn: "bg-[#d97706]", good: "bg-good" } as const;
  return (
    <Card className="min-w-0" label="Needs attention">
      <CardHead title="Needs attention" note="From the books as they stand" />
      <ul className="grid gap-1.5">
        {items.slice(0, 5).map((i) => (
          <li key={i.title}>
            <Link href={i.href} className="group flex items-center gap-3 rounded-xl border border-line-soft px-3 py-2.5 text-ink no-underline hover:border-line hover:bg-[#fafcfb]">
              <span aria-hidden="true" className={`size-2 shrink-0 rounded-full ${dot[i.tone]}`} />
              <span className="min-w-0 flex-1">
                <span className="block text-[13.5px] font-semibold">{i.title}</span>
                <span className="block truncate text-[12.5px] text-muted">{i.note}</span>
              </span>
              <Icon name="chevron" className="size-4 text-faint group-hover:text-brand" />
            </Link>
          </li>
        ))}
      </ul>
    </Card>
  );
}

const KIND_LABEL: Record<BankAccount["kind"], string> = { bank: "Bank", mobile_money: "Mobile money", cash: "Cash", undeposited_funds: "Undeposited" };

function Banks({ banks }: { banks: BankAccount[] | null }) {
  const active = (banks ?? []).filter((b) => b.is_active && b.kind !== "undeposited_funds").slice(0, 6);
  return (
    <Card className="min-w-0" label="Bank and wallets">
      <CardHead title="Bank and wallets" note="Balances in the books" href="/banking" link="Banking" />
      {active.length === 0 ? (
        <Empty text="Add a bank account, wallet or cash box to see it here." />
      ) : (
        <ul>
          {active.map((a) => (
            <li key={a.id}>
              <Link href={`/banking/${a.id}`} className="flex items-center justify-between gap-3 border-b border-line-soft py-2.5 text-ink no-underline last:border-0 hover:text-brand">
                <span className="min-w-0">
                  <span className="block truncate text-[13.5px] font-semibold">{a.name}</span>
                  <span className="block text-[12px] text-muted">{KIND_LABEL[a.kind]} · {a.currency}</span>
                </span>
                <span className="num text-[13.5px] font-semibold">{formatAmount(a.balance, a.currency)}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

function Ageing({ title, ageing, currency, href, link, contactHref }: { title: string; ageing: ArAgeing; currency: string; href: string; link: string; contactHref: string }) {
  const t = ageing.totals;
  const parts = ([
    ["Not yet due", t.current], ["1–30 days", t.days_1_30], ["31–60 days", t.days_31_60], ["61–90 days", t.days_61_90], ["Over 90 days", t.over_90],
  ] as const).map(([label, v]) => ({ label, value: Number(v), text: formatAmount(v, currency) }));
  const total = parts.reduce((s, p) => s + Math.max(0, p.value), 0);
  const top = [...ageing.rows].sort((a, b) => Number(b.total) - Number(a.total)).slice(0, 4);
  return (
    <Card className="min-w-0" label={title}>
      <CardHead title={title} note={`${formatMoney(t.total, currency)} in all, by how late`} href={href} link={link} />
      {total > 0 ? <AgeingBar title={title} parts={parts} total={total} /> : <Empty text="Nothing outstanding." />}
      {top.length > 0 && (
        <div className="mt-4">
          <div className="mb-1 text-[11.5px] font-semibold uppercase tracking-[0.04em] text-muted">Largest balances</div>
          <ul>
            {top.map((r) => (
              <li key={r.contact_id} className="flex items-center justify-between gap-3 py-1.5 text-[13.5px]">
                <Link href={`${contactHref}/${r.contact_id}`} className="truncate font-semibold text-ink no-underline hover:text-brand">{r.name}</Link>
                <span className="flex items-center gap-2">
                  {Number(r.over_90) > 0 && <Pill tone="bad">90+ days</Pill>}
                  <span className="num font-semibold">{formatAmount(r.total, currency)}</span>
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Card>
  );
}

function Shortcuts() {
  const links: [string, string, string][] = [
    ["/reports/trial-balance", "Trial balance", "As at today"],
    ["/reports/balance-sheet", "Balance sheet", "Financial position"],
    ["/tax", "Tax centre", "VAT and withholding"],
    ["/journal/new", "Journal entry", "Adjust the books"],
    ["/banking", "Reconcile", "Bank and wallets"],
    ["/close", "Close the month", "Checklist and lock"],
  ];
  return (
    <Card className="min-w-0" label="Shortcuts">
      <CardHead title="Shortcuts" note="Where accountants go most" />
      <ul className="grid grid-cols-2 gap-2">
        {links.map(([href, label, note]) => (
          <li key={href + label}>
            <Link href={href} className="block h-full rounded-xl border border-line-soft p-3 text-ink no-underline hover:border-brand/40 hover:bg-brand-tint/50">
              <span className="block text-[13.5px] font-semibold">{label}</span>
              <span className="block text-[12px] text-muted">{note}</span>
            </Link>
          </li>
        ))}
      </ul>
      <p className="mt-3 text-[12.5px] text-muted">Press <kbd className="rounded border border-line bg-canvas px-1 font-sans text-[11px] font-semibold">Ctrl K</kbd> to open any page by name.</p>
    </Card>
  );
}

function DemoFigures() {
  const k = demoKpis;
  return (
    <section aria-label="Key figures" className="grid grid-cols-[repeat(auto-fit,minmax(230px,1fr))] gap-4">
      <Stat label="Cash and wallets" value={formatMoney(k.cash, "ETB")} icon={<Icon name="wallet" />} note="Up 6.1% since last month" tone="good" />
      <Stat label="Customers owe you" value={formatMoney(k.receivable, "ETB")} icon={<Icon name="receivable" />} note={`${k.overdueCount} overdue · ${formatMoney(k.overdue, "ETB")}`} tone="bad" />
      <Stat label="You owe suppliers" value={formatMoney(k.payable, "ETB")} icon={<Icon name="payable" />} note={`${k.billsDueThisWeek} bills due this week`} />
      <Stat label="Net profit · October" value={formatMoney(k.netProfit, "ETB")} icon={<Icon name="profit" />} note="Up 8.4% vs September" tone="good" />
    </section>
  );
}

function DemoTax() {
  const k = demoKpis;
  return (
    <Card className="flex min-w-0 flex-col gap-3.5" label="Tax and compliance">
      <CardHead title="Tax and compliance" />
      <div className="rounded-xl bg-warn-tint p-3.5">
        <div className="text-[13px] font-bold text-warn">VAT return due in {k.vatDueDays} days</div>
        <div className="figure mt-1.5 text-[22px] font-semibold">{formatMoney(k.vatDue, "ETB")}</div>
      </div>
      <dl className="text-sm">
        {([["E-invoices cleared", k.einvoices.cleared, "text-good"], ["Waiting to send (offline)", k.einvoices.waiting, "text-warn"], ["Rejected", k.einvoices.rejected, "text-bad"]] as const).map(([l, v, tone]) => (
          <div key={l} className="flex justify-between py-1.5"><dt>{l}</dt><dd className={`num font-semibold ${tone}`}>{v}</dd></div>
        ))}
      </dl>
    </Card>
  );
}

function DemoBanks() {
  return (
    <Card className="mt-4" label="Bank and wallets">
      <CardHead title="Bank and wallets" />
      <ul className="grid grid-cols-[repeat(auto-fit,minmax(300px,1fr))] gap-x-8">
        {demoAccounts.map((a) => (
          <li key={a.name} className="flex items-center justify-between border-b border-line-soft py-3">
            <div><div className="text-sm font-semibold">{a.name}</div><div className="text-[13px] text-muted">{a.note}</div></div>
            <div className="num font-semibold">{formatAmount(a.balance, "ETB")}</div>
          </li>
        ))}
      </ul>
    </Card>
  );
}
