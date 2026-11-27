import { parseDate } from "@/lib/dates";
import { DateInput } from "@/components/DateInput";
import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getBankAccounts, getBankPaymentFile, getDatePrefs } from "@/lib/data";
import { todayIn } from "@/lib/dates";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Bank payment file" };


/**
 * Module 3: the bank payment file. The supplier payments recorded from one account on one day, by bank transfer or
 * mobile money, as a CSV to upload to the bank's bulk transfer. It moves no money: the payments are recorded already;
 * the bank carries them out. Suppliers without their account details are listed apart, and recent changes flagged.
 */
export default async function BankPaymentFilePage({ searchParams }: PageProps<"/payments-made/bank-file">) {
  const q = await searchParams;
  // As stored, or typed the company's way (the form works without JavaScript).
  const date = (typeof q.date === "string" ? parseDate(q.date, await getDatePrefs()) : null) ?? todayIn();
  const method = q.method === "mobile_money" ? "mobile_money" : "bank_transfer";
  const accounts = await getBankAccounts();
  const money = accounts.data.filter((a) => a.is_active && (method === "mobile_money" ? a.kind === "mobile_money" : a.kind === "bank"));
  const account = money.find((a) => a.id === q.account) ?? money[0];
  const title = <PageTitle eyebrow={<><Link href="/payments-made" className="hover:underline">Payments made</Link> / Bank file</>} title="Bank payment file" />;
  if (accounts.demo) return <>{title}<p className="text-[14px] text-muted">Not available in the demo.</p></>;
  if (!account) return <>{title}<p className="text-[14px] text-muted">Add a {method === "mobile_money" ? "mobile money" : "bank"} account first.</p></>;
  const file = await getBankPaymentFile({ date, account_id: account.id, method });
  const query = new URLSearchParams({ date, account: account.id, method }).toString();

  return (
    <>
      {title}
      <Card className="mb-4 max-w-[900px]" label="Which payments">
        <form className="flex flex-wrap items-end gap-2.5" method="get">
          <div><label className="field-label" htmlFor="date">Paid on</label><DateInput plain className="field" id="date" name="date" defaultValue={date} /></div>
          <div>
            <label className="field-label" htmlFor="method">By</label>
            <select className="field" id="method" name="method" defaultValue={method}>
              <option value="bank_transfer">Bank transfer</option><option value="mobile_money">Mobile money</option>
            </select>
          </div>
          <div>
            <label className="field-label" htmlFor="account">From</label>
            <select className="field" id="account" name="account" defaultValue={account.id}>{money.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}</select>
          </div>
          <button type="submit" className="btn">Show</button>
        </form>
        <p className="mt-3 text-[13px] text-muted">
          Record the payments first (one by one or in a batch, by bank transfer or mobile money), then download the file and upload it to your bank&rsquo;s bulk transfer.
          The file moves no money: your bank does, when you approve the upload there.
        </p>
      </Card>

      {!file ? <p className="text-sm text-muted">Your role cannot make the payment file.</p> : (
        <Card className="max-w-[900px]" label="Payments in the file">
          {file.recently_changed > 0 && (
            <p role="alert" className="mb-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">
              {file.recently_changed} supplier{file.recently_changed === 1 ? "'s" : "s'"} account details changed in the last 30 days. Call them on a number you already had and confirm before uploading.
            </p>
          )}
          {file.rows.length === 0 ? <p className="text-sm text-muted">No {method === "mobile_money" ? "mobile money" : "bank transfer"} payments to suppliers from {account.name} on {date}.</p> : (
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Supplier</th><th scope="col">{method === "mobile_money" ? "Provider" : "Bank"}</th><th scope="col">Account</th><th scope="col">Reference</th><th scope="col" className="!text-right">Amount</th></tr></thead>
                <tbody>
                  {file.rows.map((r) => (
                    <tr key={r.payment_id}>
                      <td><Link href={`/payments-made/${encodeURIComponent(r.payment_id)}`} className="hover:underline">{r.supplier}</Link>
                        {r.details_changed_on && <span className="ml-1.5 text-[12px] font-semibold text-bad">details changed <Dt v={r.details_changed_on} /></span>}</td>
                      <td>{r.institution}</td>
                      <td className="num">{r.account_number}<span className="block text-[12px] text-muted">{r.account_name}</span></td>
                      <td>{r.reference ?? ""}</td>
                      <td className="num text-right">{formatAmount(r.amount)} <span className="text-[12px] text-muted">{r.currency}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {Object.keys(file.totals).length > 0 && (
            <p className="mt-2 text-[13px] font-semibold">Total: {Object.entries(file.totals).map(([cur, t]) => `${formatAmount(t)} ${cur}`).join(", ")}</p>
          )}
          {file.missing.length > 0 && (
            <div className="mt-4">
              <h2 className="text-[15px] font-bold">Not in the file</h2>
              <ul className="mt-1 text-[13px]">
                {file.missing.map((m) => (
                  <li key={m.payment_id} className="border-t border-line py-1.5">
                    <Link href={`/suppliers/${encodeURIComponent(m.contact_id)}`} className="font-semibold hover:underline">{m.supplier}</Link>: {formatAmount(m.amount)} {m.currency}. Add {m.needs} to the supplier, then come back.
                  </li>
                ))}
              </ul>
            </div>
          )}
          {file.rows.length > 0 && <a className="btn btn-primary mt-4" href={`/payments-made/bank-file/download?${query}`}>Download the file ({file.rows.length} payment{file.rows.length === 1 ? "" : "s"})</a>}
        </Card>
      )}
    </>
  );
}
