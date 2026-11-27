import { Dt } from "@/components/Dt";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import Link from "@/components/Link";
import { AccountTabs } from "./AccountTabs";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getBankAccounts, getBankRegister, getCashCounts, getReportPeriod } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { ArchiveBankAccount, EditBankAccount } from "../BankingForms";
import { CashCountForm, FloatForm, TopUpForm } from "../PettyCashForms";

export const metadata = { title: "Bank account" };

/** One money account: every entry on it in a period with the running balance, and its details. */
export default async function BankAccountPage({ params, searchParams }: PageProps<"/banking/[id]">) {
  const { id } = await params;
  const q = await searchParams;
  const period = await getReportPeriod(q, "last_90_days");
  const register = await getBankRegister(id, { from: period.from, to: period.to });
  if (!register) notFound();
  const a = register.account;
  const isCash = a.kind === "cash";
  // An account kept in another currency: each line also at its rate in the company's currency.
  const fx = register.base_currency !== undefined;
  const [counts, accounts] = isCash ? await Promise.all([getCashCounts(id), getBankAccounts()]) : [null, null];
  const sources = (accounts?.data ?? []).filter((x) => x.id !== a.id && x.kind !== "undeposited_funds" && x.currency === a.currency && x.is_active);

  return (
    <>
      <PageTitle eyebrow={<><Link href="/banking" className="hover:underline">Banking and cash</Link> / Account</>}
        title={<><span className="num">{a.code}</span> {a.name} {!a.is_active && <Pill>Archived</Pill>}</>} />
      <AccountTabs id={a.id} current="register" kind={a.kind} />
      <DateRangeFilter picked={period.picked} keep={q} fields={[]}>

      </DateRangeFilter>
      <Card className="!p-2" label="Register">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Date</th><th scope="col">Entry</th><th scope="col">What</th><th scope="col">Who</th><th scope="col" className="!text-right">In ({register.currency})</th><th scope="col" className="!text-right">Out ({register.currency})</th><th scope="col" className="!text-right">Balance</th>{fx && <><th scope="col" className="!text-right">Rate</th><th scope="col" className="!text-right">Amount ({register.base_currency})</th></>}</tr></thead>
            <tbody>
              <tr><td className="num"><Dt v={register.from} /></td><td colSpan={5} className="font-semibold">Brought forward</td><td className="num text-right font-semibold">{formatAmount(register.opening)}</td>{fx && <><td /><td className="num text-right text-muted">{formatAmount(register.opening_base ?? "0.00")}</td></>}</tr>
              {register.lines.map((l, i) => (
                <tr key={`${l.entry}-${i}`}>
                  <td className="num"><Dt v={l.date} /></td>
                  <td className="num">#{l.entry}</td>
                  <td>{l.source_type === "revaluation" ? "Currency revaluation" : l.memo ?? l.description ?? l.source_type}</td>
                  <td>{l.contact ?? "—"}</td>
                  <td className="num text-right">{l.in === "0.00" ? "" : formatAmount(l.in)}</td>
                  <td className="num text-right">{l.out === "0.00" ? "" : formatAmount(l.out)}</td>
                  <td className="num text-right">{formatAmount(l.balance)}</td>
                  {fx && <><td className="num text-right text-muted">{l.rate ?? "—"}</td><td className="num text-right">{formatAmount(l.amount_base ?? "0.00")}</td></>}
                </tr>
              ))}
            </tbody>
            <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={6}>Balance at <Dt v={register.to} /></td><td className="num text-right">{formatMoney(register.closing, register.currency)}</td>{fx && <><td /><td className="num text-right">{formatMoney(a.balance_base, register.base_currency!)}</td></>}</tr></tfoot>
          </table>
        </div>
      </Card>
      {isCash && (
        <Card className="mt-4 max-w-[900px]" label="Petty cash">
          <h2 className="mb-1 text-[15px] font-bold">Petty cash</h2>
          <p className="mb-3 text-[14px] text-muted">
            {a.float_amount ? <>Kept at a float of <span className="num font-semibold text-ink">{formatAmount(a.float_amount)}</span>; {a.top_up === "0.00" ? "it is full." : <>it takes <span className="num font-semibold text-ink">{formatAmount(a.top_up ?? "0")}</span> to top it up.</>}</> : "No float set."}
            {" "}Spend from it with an <Link href="/expenses/new" className="font-semibold text-brand hover:underline">expense</Link> paid from this account (a voucher, with its receipt number).
          </p>
          {accounts?.can.manage && <div className="mb-4"><FloatForm account={a} /></div>}
          {accounts?.can.transfer && sources.length > 0 && <div className="mb-2"><h3 className="mb-1 text-[14px] font-bold">Top up</h3><TopUpForm account={a} sources={sources} /></div>}
        </Card>
      )}
      {isCash && counts && (
        <Card className="mt-4 max-w-[900px]" label="Cash counts">
          <h2 className="mb-2 text-[15px] font-bold">Cash counts</h2>
          {counts.can.count && <div className="mb-4"><CashCountForm account={a} /></div>}
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead><tr><th scope="col">Counted on</th><th scope="col">By</th><th scope="col" className="!text-right">Counted</th><th scope="col" className="!text-right">Books</th><th scope="col" className="!text-right">Difference</th><th scope="col">Note</th></tr></thead>
              <tbody>
                {counts.data.length === 0 && <tr><td colSpan={6} className="text-muted">Not counted yet.</td></tr>}
                {counts.data.map((k) => (
                  <tr key={k.id}>
                    <td className="num"><Dt v={k.counted_on} /></td><td>{k.counted_by}</td>
                    <td className="num text-right">{formatAmount(k.counted)}</td><td className="num text-right">{formatAmount(k.book_balance)}</td>
                    <td className={`num text-right font-semibold ${k.difference === "0.00" ? "" : k.difference.startsWith("-") ? "text-bad" : "text-good"}`}>{formatAmount(k.difference)}{k.entry ? <span className="ml-1 text-[12px] text-muted">#{k.entry}</span> : null}</td>
                    <td className="text-[13px] text-muted">{k.note ?? ""}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
      {a.kind !== "undeposited_funds" && (
        <Card className="mt-4 max-w-[900px]" label="Details">
          <div className="mb-3 flex items-center justify-between gap-2"><h2 className="text-[15px] font-bold">Details</h2><ArchiveBankAccount account={a} /></div>
          <EditBankAccount account={a} />
        </Card>
      )}
    </>
  );
}
