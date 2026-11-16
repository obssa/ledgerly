import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getBankRegister } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { ArchiveBankAccount, EditBankAccount } from "../BankingForms";

export const metadata = { title: "Bank account" };

const date = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);

/** One money account: every entry on it in a period with the running balance, and its details. */
export default async function BankAccountPage({ params, searchParams }: PageProps<"/banking/[id]">) {
  const { id } = await params;
  const q = await searchParams;
  const register = await getBankRegister(id, { from: date(q.from), to: date(q.to) });
  if (!register) notFound();
  const a = register.account;

  return (
    <>
      <PageTitle eyebrow={<><Link href="/banking" className="hover:underline">Banking and cash</Link> / Account</>}
        title={<><span className="num">{a.code}</span> {a.name} {!a.is_active && <Pill>Archived</Pill>}</>} />
      <form className="mb-4 flex flex-wrap items-end gap-2.5 print:hidden">
        <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={register.from} /></div>
        <div><label className="field-label" htmlFor="to">To</label><input className="field" id="to" name="to" type="date" defaultValue={register.to} /></div>
        <button type="submit" className="btn">Show</button>
      </form>
      <Card className="!p-2" label="Register">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Date</th><th scope="col">Entry</th><th scope="col">What</th><th scope="col">Who</th><th scope="col" className="!text-right">In</th><th scope="col" className="!text-right">Out</th><th scope="col" className="!text-right">Balance</th></tr></thead>
            <tbody>
              <tr><td className="num">{register.from}</td><td colSpan={5} className="font-semibold">Brought forward</td><td className="num text-right font-semibold">{formatAmount(register.opening)}</td></tr>
              {register.lines.map((l, i) => (
                <tr key={`${l.entry}-${i}`}>
                  <td className="num">{l.date}</td>
                  <td className="num">#{l.entry}</td>
                  <td>{l.memo ?? l.description ?? l.source_type}</td>
                  <td>{l.contact ?? "—"}</td>
                  <td className="num text-right">{l.in === "0.00" ? "" : formatAmount(l.in)}</td>
                  <td className="num text-right">{l.out === "0.00" ? "" : formatAmount(l.out)}</td>
                  <td className="num text-right">{formatAmount(l.balance)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={6}>Balance at {register.to}</td><td className="num text-right">{formatMoney(register.closing, register.currency)}</td></tr></tfoot>
          </table>
        </div>
      </Card>
      {a.kind !== "undeposited_funds" && (
        <Card className="mt-4 max-w-[900px]" label="Details">
          <div className="mb-3 flex items-center justify-between gap-2"><h2 className="text-[15px] font-bold">Details</h2><ArchiveBankAccount account={a} /></div>
          <EditBankAccount account={a} />
        </Card>
      )}
    </>
  );
}
