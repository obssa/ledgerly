import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { EmailStatements } from "@/components/EmailStatements";
import type { StatementType } from "@/lib/api";
import { getContactStatements, getCustomerChoices, getReportPeriod, getSupplierChoices, getDateFormatter } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { rangeLabel } from "@/lib/periods";
import { periodQuery } from "@/lib/periodParams";
import { typeLabel } from "@/lib/transactionColumns";
import { PrintButton } from "@/app/(app)/reports/PrintButton";

type Query = Record<string, string | string[] | undefined>;
const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);
export const STATEMENT_TYPES: { value: StatementType; label: string; note: string }[] = [
  { value: "balance_forward", label: "Balance forward", note: "What was owed before the period, every transaction in it, and the balance at the end." },
  { value: "open_item", label: "Open item", note: "Only what is still owed at the end of the period, with days overdue." },
  { value: "transaction", label: "Transaction statement", note: "Every transaction of the period, without a balance brought forward." },
];
const BUCKET_LABEL = (b: string) => (b === "current" ? "Current" : b.replace(/^days_(\d+)_(\d+)$/, "$1–$2 days").replace(/^days_(\d+)_plus$/, "Over $1 days").replace(/^over_(\d+)$/, "Over $1 days"));

/**
 * QuickBooks' Create statements, in Sales (customers) and Purchases (suppliers): choose the customer (or everyone with a
 * balance, everyone overdue, or everyone), the kind of statement and the period; each statement follows, with its
 * ageing at the end of the period, one to a printed page. Customers' statements are sent by email from here.
 */
export async function StatementsList({ side, q }: { side: "customer" | "supplier"; q: Query }) {
  const { date: fmtDate } = await getDateFormatter();
  const payable = side === "supplier";
  const period = await getReportPeriod(q, "this_month");
  const type = (STATEMENT_TYPES.find((t) => t.value === one(q.type))?.value ?? "balance_forward") as StatementType;
  const who = (["balance", "overdue", "all"] as const).find((w) => w === one(q.who)) ?? "balance";
  const contactId = one(q.contact_id) && /^[0-9a-f-]{36}$/i.test(one(q.contact_id)!) ? one(q.contact_id) : undefined;
  const [data, choices] = await Promise.all([
    getContactStatements(side, { type, from: period.from, to: period.to, who, ...(contactId ? { contact_id: contactId } : {}) }),
    payable ? getSupplierChoices() : getCustomerChoices(),
  ]);
  const party = payable ? "supplier" : "customer";
  const parties = payable ? "suppliers" : "customers";
  const statements = data?.statements ?? [];
  const keep = periodQuery(period.picked);

  return (
    <>
      <PageTitle title={payable ? "Supplier statements" : "Customer statements"}
        description={STATEMENT_TYPES.find((t) => t.value === type)?.note}
        actions={statements.length > 0 ? <PrintButton report={payable ? "supplier-statement" : "customer-statement"} params={{ from: period.from, to: period.to }} /> : undefined} />
      <DateRangeFilter picked={period.picked} fields={["type", "who", "contact_id"]} label="Statement period" button="Show statements">
        <div>
          <label className="field-label" htmlFor="statement_type">Statement type</label>
          <select className="field" id="statement_type" name="type" defaultValue={type}>
            {STATEMENT_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
          </select>
        </div>
        <div>
          <label className="field-label" htmlFor="statement_contact">{payable ? "Supplier" : "Customer"}</label>
          <select className="field" id="statement_contact" name="contact_id" defaultValue={contactId ?? ""}>
            <option value="">All {parties}</option>
            {choices.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
        <div>
          <label className="field-label" htmlFor="statement_who">Which {parties}</label>
          <select className="field" id="statement_who" name="who" defaultValue={who} disabled={Boolean(contactId)}>
            <option value="balance">With a balance</option>
            <option value="overdue">Overdue</option>
            <option value="all">All {parties}</option>
          </select>
        </div>
      </DateRangeFilter>

      {!payable && statements.length > 0 && (
        <EmailStatements ids={statements.map((s) => s.contact.id)} type={type} from={period.from} to={period.to}
          withEmail={statements.filter((s) => s.contact.email).length} />
      )}
      {data?.more && <p role="status" className="mb-3 text-[13px] text-muted">The first 100 are shown: choose a {party}, or overdue only, to see the rest.</p>}
      {statements.length === 0 && (
        <Card label="No statements"><p className="text-sm text-muted">No {party} has a statement for this choice. Try all {parties} or another period.</p></Card>
      )}

      <div className="flex flex-col gap-4">
        {statements.map((s) => {
          const base = `/${parties}/${encodeURIComponent(s.contact.id)}`;
          const openItem = type === "open_item";
          return (
            <Card key={s.contact.id} className="max-w-[1100px] break-after-page" label={`Statement for ${s.contact.name}`}>
              <ReportHeader title={`${STATEMENT_TYPES.find((t) => t.value === type)?.label}: ${s.contact.name}`}
                period={openItem ? `At ${fmtDate(data!.to)}` : rangeLabel(period.picked, period.ctx)}
                filters={[s.contact.tax_id ? `TIN ${s.contact.tax_id}` : null, s.contact.email]} />
              <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={`Statement for ${s.contact.name}`}>
                <table className="tbl">
                  <thead>
                    <tr>
                      <th scope="col">Date</th><th scope="col">Transaction</th><th scope="col">No.</th><th scope="col">Due date</th>
                      {openItem
                        ? <><th scope="col" className="!text-right">Days overdue</th><th scope="col" className="!text-right">Amount</th><th scope="col" className="!text-right">Open balance</th></>
                        : <><th scope="col" className="!text-right">{payable ? "Bills" : "Charges"}</th><th scope="col" className="!text-right">Payments</th><th scope="col" className="!text-right">Balance</th></>}
                    </tr>
                  </thead>
                  <tbody>
                    {s.opening !== null && (
                      <tr className="font-semibold"><td colSpan={6}>Balance brought forward at {fmtDate(data!.from)}</td><td className="num text-right">{formatAmount(s.opening)}</td></tr>
                    )}
                    {s.lines.length === 0 && <tr><td colSpan={7} className="py-5 text-center text-muted">Nothing in this period.</td></tr>}
                    {s.lines.map((l, i) => (
                      <tr key={`${l.document_id ?? l.reference}-${i}`}>
                        <td className="num"><Dt v={l.date} /></td>
                        <td>{typeLabel(l.type)}</td>
                        <td className="num">{l.reference ?? "—"}</td>
                        <td className="num">{l.due_date ? <Dt v={l.due_date} /> : ""}</td>
                        {openItem
                          ? <><td className="num text-right">{l.days_overdue && l.days_overdue > 0 ? l.days_overdue : "—"}</td><td className="num text-right">{formatAmount(l.total ?? l.amount)}</td><td className="num text-right">{formatAmount(l.amount)}</td></>
                          : <><td className="num text-right">{l.charge && l.charge !== "0.00" ? formatAmount(l.charge) : ""}</td><td className="num text-right">{l.payment && l.payment !== "0.00" ? formatAmount(l.payment) : ""}</td><td className="num text-right">{l.balance ? formatAmount(l.balance) : ""}</td></>}
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="border-t-2 border-ink font-bold">
                      <td colSpan={6}>{payable ? "We owe" : "Amount due"} at {fmtDate(data!.to)}</td>
                      <td className="num text-right">{formatMoney(s.closing, data!.currency)}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
              <dl className="mt-3 grid grid-cols-[repeat(auto-fit,minmax(110px,1fr))] gap-2 text-[13px]" aria-label="Ageing at the end of the period">
                {data!.buckets.map((b) => (
                  <div key={b} className="rounded-lg border border-line px-3 py-2"><dt className="text-muted">{BUCKET_LABEL(b)}</dt><dd className="num font-semibold">{formatAmount(s.ageing[b] ?? "0.00")}</dd></div>
                ))}
              </dl>
              <p className="mt-3 text-[13px] print:hidden">
                <Link href={`${base}/statement?${new URLSearchParams(keep)}`} className="font-semibold text-primary hover:underline">Open {s.contact.name}&apos;s statement</Link>
              </p>
              <ReportFooter basis={null} currency={data!.currency} />
            </Card>
          );
        })}
      </div>
    </>
  );
}
