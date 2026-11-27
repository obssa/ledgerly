import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getPostDatedCheques, getDateFormatter } from "@/lib/data";
import { formatMoney } from "@/lib/format";
import { ChequeStep, RecordPostDatedCheque } from "./PostDatedForms";

export const metadata = { title: "Post-dated cheques" };

const STATUS = { held: { label: "Held", tone: "warn" }, presented: { label: "Presented", tone: "good" }, cancelled: { label: "Cancelled", tone: "neutral" } } as const;

/**
 * Module 5: post-dated cheques (062). Cheques dated later than the day they changed hands are held here, out of the
 * books; on their date they are presented and become the cheque received or paid, or they are cancelled.
 */
export default async function PostDatedChequesPage() {
  const { date: fmtDate } = await getDateFormatter();
  const data = await getPostDatedCheques();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/banking" className="hover:underline">Banking and cash</Link> / Cheques</>} title="Post-dated cheques" />
      {data === null ? <p className="text-sm text-muted">Not available in the demo.</p> : (
        <div className="flex flex-wrap items-start gap-4">
          <div className="flex min-w-0 flex-[2_1_620px] flex-col gap-4">
            {data.cheques.held.length > 0 && (
              <p className="text-sm">
                Held: {data.cheques.held.map((h) => `${h.cheques} ${h.direction === "in" ? "received" : "written"}, ${formatMoney(h.amount, h.currency)}`).join("; ")}.
                {" "}They are not in the books until presented.
              </p>
            )}
            <Card className="!p-2" label="Post-dated cheques">
              {data.cheques.data.length === 0 ? <p className="p-3 text-sm text-muted">No post-dated cheques.</p> : (
                <div className="overflow-x-auto">
                  <table className="tbl">
                    <thead>
                      <tr><th scope="col">Dated</th><th scope="col">Cheque</th><th scope="col">From or to</th><th scope="col">Account</th><th scope="col" className="!text-right">Amount</th><th scope="col">Status</th><th scope="col"><span className="sr-only">Actions</span></th></tr>
                    </thead>
                    <tbody>
                      {data.cheques.data.map((c) => {
                        const s = STATUS[c.status];
                        const allowed = c.direction === "in" ? data.cheques.can.receive : data.cheques.can.pay;
                        return (
                          <tr key={c.id}>
                            <td className="num"><Dt v={c.cheque_date} />{c.due && <span className="block text-[12px] font-semibold text-warn">Due</span>}</td>
                            <td className="num">{c.cheque_number}<span className="block text-[12px] text-muted">{c.direction === "in" ? `received ${fmtDate(c.handed_on)}` : `written ${fmtDate(c.handed_on)}`}{c.drawn_on ? ` · ${fmtDate(c.drawn_on)}` : ""}</span></td>
                            <td>{c.contact.name}</td>
                            <td className="text-[13px]">{c.account.code} {c.account.name}</td>
                            <td className="num text-right">{formatMoney(c.amount, c.currency)}</td>
                            <td>
                              <Pill tone={s.tone}>{s.label}</Pill>
                              {c.payment_id && (
                                <Link href={`/${c.direction === "in" ? "payments" : "payments-made"}/${encodeURIComponent(c.payment_id)}`} className="block text-[12px] font-semibold text-brand hover:underline">
                                  The payment, <Dt v={c.closed_on} />
                                </Link>
                              )}
                            </td>
                            <td>{c.status === "held" && allowed && <ChequeStep id={c.id} due={c.due} />}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </Card>
          </div>
          {(data.cheques.can.receive || data.cheques.can.pay) && (
            <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
              <Card label="Record a post-dated cheque">
                <h2 className="mb-2 text-[17px] font-bold">Record a post-dated cheque</h2>
                <RecordPostDatedCheque customers={data.customers} suppliers={data.suppliers} banks={data.banks} currencies={data.currencies}
                  canReceive={data.cheques.can.receive} canPay={data.cheques.can.pay} />
              </Card>
            </aside>
          )}
        </div>
      )}
    </>
  );
}
