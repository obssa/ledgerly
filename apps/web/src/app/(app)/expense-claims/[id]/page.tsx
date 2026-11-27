import { Dt } from "@/components/Dt";
import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle } from "@/components/ui";
import { getExpenseClaim } from "@/lib/data";
import { formatAmount, formatMoney } from "@/lib/format";
import { AttachmentsPanel } from "../../attachments/AttachmentsPanel";
import { SubmitClaim } from "../ClaimForms";

export const metadata = { title: "Expense claim" };

/** One expense claim (083): its receipts, their photos, and what happens next. */
export default async function ExpenseClaimPage({ params, searchParams }: PageProps<"/expense-claims/[id]">) {
  const { id } = await params;
  const { attachment } = await searchParams;
  const claim = await getExpenseClaim(id);
  if (!claim) notFound();
  return (
    <>
      <PageTitle eyebrow={<><Link href="/expense-claims" className="hover:underline">Expense claims</Link> / {claim.contact.name}</>} title={<>Expense claim of <span className="num"><Dt v={claim.issue_date} /></span></>} />
      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_560px]" label="Receipts">
          {claim.notes && <p className="mb-3 text-sm">{claim.notes}</p>}
          <table className="tbl">
            <thead><tr><th scope="col">Receipt</th><th scope="col" className="!text-right">Tax</th><th scope="col" className="!text-right">Paid</th></tr></thead>
            <tbody>
              {(claim.lines ?? []).map((l) => (
                <tr key={l.line_no}><td>{l.description}</td><td className="num text-right">{formatAmount(l.line_tax)}</td><td className="num text-right">{formatAmount(l.unit_price)}</td></tr>
              ))}
            </tbody>
          </table>
          <div className="mt-3 flex justify-between border-t-2 border-ink pt-2 text-lg font-extrabold"><span>Claimed</span><span className="num">{formatMoney(claim.total, claim.currency)}</span></div>
        </Card>
        <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
          {claim.can?.submit && <SubmitClaim id={claim.id} />}
          {claim.can?.approve && <Link href={`/bills/${encodeURIComponent(claim.id)}`} className="btn btn-primary justify-center">Review and approve it as a bill</Link>}
          {claim.status === "awaiting_approval" && !claim.can?.approve && <p className="text-sm text-muted">Waiting for someone who approves bills.</p>}
          {["posted", "part_paid"].includes(claim.status) && <p className="text-sm">Approved: <span className="num font-semibold">{formatMoney(claim.amount_due, claim.currency)}</span> is still to be paid to {claim.contact.name}.</p>}
          {claim.can?.attach && <AttachmentsPanel kind="expense_claim" documentId={claim.id} flag={typeof attachment === "string" ? attachment : undefined} />}
        </aside>
      </div>
    </>
  );
}
