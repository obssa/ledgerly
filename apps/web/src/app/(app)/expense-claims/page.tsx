import { ListPeriod } from "@/components/ListPeriod";
import { listQuery } from "@/lib/lists";
import Link from "next/link";
import { redirect } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getExpenseClaims, getReportPeriod } from "@/lib/data";
import { formatMoney } from "@/lib/format";

export const metadata = { title: "Expense claims" };

const STATUS: Record<string, { label: string; tone: "neutral" | "warn" | "good" }> = {
  draft: { label: "Draft", tone: "neutral" }, awaiting_approval: { label: "Awaiting approval", tone: "warn" },
  posted: { label: "Approved, to be paid", tone: "warn" }, part_paid: { label: "Part paid", tone: "warn" }, paid: { label: "Paid", tone: "good" }, void: { label: "Void", tone: "neutral" },
};

/** Module 3, employee expense claims (083): one's own claims, or everyone's for someone who sees bills. */
export default async function ExpenseClaimsPage({ searchParams }: PageProps<"/expense-claims">) {
  const q = await searchParams;
  // All dates unless a period is chosen: nothing disappears from the list.
  const period = await getReportPeriod(q, "all");
  const claims = await getExpenseClaims(listQuery(q, period.picked));
  if (!claims) redirect("/no-access");
  return (
    <>
      <PageTitle eyebrow="Money out" title="Expense claims" actions={<Link href="/expense-claims/new" className="btn btn-primary">+ New claim</Link>} />
      <ListPeriod picked={period.picked} />
      <Card className="max-w-[1000px]" label="Expense claims">
        <p className="mb-3 text-sm text-muted">
          Paid for the company out of your own pocket? Claim it back: the claim is a bill payable to you, approved by someone else and paid like any bill.
          {claims.can.see_all ? " You see everyone's claims." : " You see your own claims."}
        </p>
        {claims.data.length === 0 ? (
          <p className="text-sm text-muted">No claims yet.</p>
        ) : (
          <table className="tbl">
            <thead><tr><th scope="col">Date</th><th scope="col">Claimant</th><th scope="col">For</th><th scope="col">Status</th><th scope="col" className="!text-right">Amount</th></tr></thead>
            <tbody>
              {claims.data.map((c) => (
                <tr key={c.id}>
                  <td className="num"><Link href={`/expense-claims/${encodeURIComponent(c.id)}`} className="font-semibold hover:underline">{c.issue_date}</Link></td>
                  <td>{c.contact.name}</td>
                  <td className="text-muted">{c.notes ?? "—"}</td>
                  <td><Pill tone={STATUS[c.status]?.tone ?? "neutral"}>{STATUS[c.status]?.label ?? c.status}</Pill></td>
                  <td className="num text-right">{formatMoney(c.total, c.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </>
  );
}
