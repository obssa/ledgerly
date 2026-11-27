import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import type { JournalDetail } from "@/lib/api";
import { getJournalEntry } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { ReverseJournal } from "../JournalForms";
import { AttachmentsPanel } from "../../attachments/AttachmentsPanel";
import { TagsPanel } from "../../tags/TagsPanel";

export const metadata = { title: "Journal entry" };

/** Where an entry came from, as a link to the document or payment that made it. */
function sourceHref(e: JournalDetail): string | null {
  const d = e.document;
  if (d) {
    const base = { invoice: "/invoices", credit_note: "/invoices", bill: "/bills", vendor_credit: "/bills", expense: "/expenses", sales_receipt: "/sales-receipts" }[d.type];
    return base ? `${base}/${encodeURIComponent(d.id)}` : null;
  }
  const p = e.payment;
  if (p) return p.kind === "refund" ? `/refunds/${encodeURIComponent(p.id)}` : p.direction === "in" ? `/payments/${encodeURIComponent(p.id)}` : `/payments-made/${encodeURIComponent(p.id)}`;
  return null;
}

/** Module 6: one entry of the books, its lines and dimensions, where it came from, and its reversal. */
export default async function JournalEntryPage({ params, searchParams }: PageProps<"/journal/[id]">) {
  const { id } = await params;
  const { attachment } = await searchParams;
  const e = await getJournalEntry(id);
  if (!e) notFound();
  const from = sourceHref(e);
  const dims = (l: JournalDetail["lines"][number]) => [l.location, l.class, l.project].filter(Boolean).join(" · ");

  return (
    <>
      <PageTitle eyebrow={<><Link href="/journal" className="hover:underline">Journal entries</Link> / Entry</>}
        title={<>Entry <span className="num">#{e.number}</span> {e.status === "reversed" ? <Pill>Reversed</Pill> : <Pill tone="good">Posted</Pill>}</>} />
      <Card className="mb-4" label="Entry">
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3 text-sm">
          <div><dt className="text-muted">Date</dt><dd className="num"><Dt v={e.date} /></dd></div>
          <div><dt className="text-muted">Made by</dt><dd>{from ? <Link href={from} className="font-semibold text-brand hover:underline">{e.source_label}{e.document?.number ? ` ${e.document.number}` : ""}</Link> : e.source_label}</dd></div>
          <div><dt className="text-muted">Posted by</dt><dd>{e.posted_by ?? "—"}</dd></div>
          {e.currency && Number(e.fx_rate) !== 1 && <div><dt className="text-muted">Currency</dt><dd className="num">{e.currency} at {e.fx_rate}</dd></div>}
          {e.reverses && <div><dt className="text-muted">Reverses</dt><dd><Link href={`/journal/${encodeURIComponent(e.reverses.id)}`} className="num hover:underline">#{e.reverses.number}</Link></dd></div>}
          {e.reversed_by && <div><dt className="text-muted">Reversed by</dt><dd><Link href={`/journal/${encodeURIComponent(e.reversed_by.id)}`} className="num hover:underline">#{e.reversed_by.number}</Link> on <span className="num"><Dt v={e.reversed_by.date} /></span></dd></div>}
        </dl>
        {e.memo && <p className="mt-3 text-[14px]">{e.memo}</p>}
      </Card>
      <Card className="mb-4 !p-2" label="Lines">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Account</th><th scope="col">Description</th><th scope="col">Who</th><th scope="col">Dimensions</th><th scope="col" className="!text-right">Debit</th><th scope="col" className="!text-right">Credit</th></tr></thead>
            <tbody>
              {e.lines.map((l) => (
                <tr key={l.line_no}>
                  <td><Link href={`/accounts/${encodeURIComponent(l.account_id)}`} className="hover:underline"><span className="num">{l.code}</span> {l.account}</Link></td>
                  <td>{l.description ?? ""}</td>
                  <td>{l.contact ?? ""}</td>
                  <td className="text-[13px] text-muted">{dims(l)}</td>
                  <td className="num text-right">{l.debit === "0.00" ? "" : formatAmount(l.debit)}</td>
                  <td className="num text-right">{l.credit === "0.00" ? "" : formatAmount(l.credit)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={4}>Total</td><td className="num text-right">{formatAmount(e.total)}</td><td className="num text-right">{formatAmount(e.total)}</td></tr></tfoot>
          </table>
        </div>
      </Card>
      {/* 086: the files behind the entry: a board minute, a loan schedule, the bank's letter. */}
      <div className="mb-4 max-w-[600px]"><AttachmentsPanel kind="journal" documentId={e.id} flag={typeof attachment === "string" ? attachment : undefined} /></div>
      <div className="mb-4 max-w-[600px]"><TagsPanel kind="journal" id={e.id} /></div>
      {e.can.reverse && (
        <Card className="max-w-[900px]" label="Reverse">
          <h2 className="mb-1 text-[15px] font-bold">Reverse this entry</h2>
          <p className="mb-3 text-[13px] text-muted">A mirror entry is posted on the day you choose; this one is kept and marked reversed. The reason goes in the audit log.</p>
          <ReverseJournal id={e.id} date={e.date} />
        </Card>
      )}
      {!e.can.reverse && e.status === "posted" && e.source_type !== "manual" && (
        <p className="text-[13px] text-muted">This entry was made by a {e.source_label}: correct it there (void, reverse or credit it), so the document and the books agree.</p>
      )}
    </>
  );
}
