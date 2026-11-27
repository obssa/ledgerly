import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getBankAccounts, getTaxReturn } from "@/lib/data";
import { SettleReturn } from "../../TaxForms";
import { Worksheet } from "../../Worksheet";

export const metadata = { title: "Tax return" };

/** Module 7: a filed return with its figures as filed, and its settlement. */
export default async function TaxReturnPage({ params }: PageProps<"/tax/returns/[id]">) {
  const { id } = await params;
  const [r, banks] = await Promise.all([getTaxReturn(id), getBankAccounts()]);
  if (!r) notFound();
  const label = r.kind === "vat" ? "VAT return" : "Withholding tax return";

  return (
    <>
      <PageTitle eyebrow={<><Link href="/tax" className="hover:underline">Tax centre</Link> / Return</>}
        title={<>{label}, <span className="num">{r.period_start}</span> to <span className="num">{r.period_end}</span> {r.settled_on ? <Pill tone="good">Paid</Pill> : <Pill tone="warn">Filed, not paid</Pill>}</>} />
      <Card className="mb-4" label="Return">
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3 text-sm">
          <div><dt className="text-muted">Filed on</dt><dd className="num"><Dt v={r.filed_on} /></dd></div>
          <div><dt className="text-muted">Reference</dt><dd>{r.reference ?? "—"}</dd></div>
          <div><dt className="text-muted">Recorded by</dt><dd>{r.filed_by ?? "—"}</dd></div>
          {r.settlement && <div><dt className="text-muted">Settled</dt><dd><Link href={`/journal/${encodeURIComponent(r.settlement.id)}`} className="num hover:underline">#{r.settlement.number}</Link>, <span className="num"><Dt v={r.settlement.date} /></span></dd></div>}
        </dl>
        <p className="mt-3 text-[13px] text-muted">The figures below are as they were when the return was filed. The period is locked for this tax: a correction goes into a later return.</p>
      </Card>
      {r.can?.settle && (
        <Card className="mb-4 max-w-[900px]" label="Settle">
          <h2 className="mb-3 text-[15px] font-bold">Record the payment</h2>
          <SettleReturn id={r.id} periodEnd={r.period_end} due={r.net} banks={banks.data.filter((b) => b.is_active && b.kind !== "undeposited_funds")} />
        </Card>
      )}
      {r.figures && <Worksheet sheet={r.figures} />}
    </>
  );
}
