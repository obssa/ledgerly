import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getRefund } from "@/lib/data";
import { formatMoney } from "@/lib/format";
import { ReverseRefund } from "../ReverseRefund";

export const metadata = { title: "Refund" };

export default async function RefundPage({ params }: PageProps<"/refunds/[id]">) {
  const { id } = await params;
  const refund = await getRefund(id);
  if (!refund) notFound();
  const out = refund.direction === "out";
  const credit = refund.allocations?.[0];

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/refunds" className="hover:underline">Refunds</Link> / Refund</>}
        title={<>{formatMoney(refund.amount, refund.currency)} {out ? "to" : "from"} {refund.contact?.name} {refund.status === "reversed" ? <Pill tone="bad">Reversed</Pill> : <Pill tone="good">Recorded</Pill>}</>}
      />
      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_520px]" label="Refund">
          <dl className="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3 text-sm">
            <div><dt className="text-muted">Date</dt><dd className="num">{refund.payment_date}</dd></div>
            <div><dt className="text-muted">Way</dt><dd>{out ? "Paid to the customer" : "Received from the supplier"}</dd></div>
            <div><dt className="text-muted">Method</dt><dd>{refund.method.replace("_", " ")}</dd></div>
            <div><dt className="text-muted">Reference</dt><dd className="num">{refund.reference ?? "—"}</dd></div>
            <div>
              <dt className="text-muted">Gives back</dt>
              <dd>
                {credit?.document
                  ? <Link href={`/${out ? "invoices" : "bills"}/${encodeURIComponent(credit.document_id)}`} className="num hover:underline">{credit.document.number}</Link>
                  : refund.refunds_payment_id
                    ? <Link href={`/${out ? "payments" : "payments-made"}/${encodeURIComponent(refund.refunds_payment_id)}`} className="hover:underline">The unused part of a payment</Link>
                    : "—"}
              </dd>
            </div>
          </dl>
        </Card>
        {refund.status === "posted" && refund.can.reverse && <aside className="min-w-0 flex-[1_1_300px]"><ReverseRefund id={refund.id} /></aside>}
      </div>
    </>
  );
}
