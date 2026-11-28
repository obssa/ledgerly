import Link from "@/components/Link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { KIND_LABELS, naturalBalance, TYPE_LABELS } from "@/lib/chart";
import { getChart } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { EditAccount } from "../AccountForms";

export const metadata = { title: "Account" };

/** One account of the chart: what it is, its balance, and (for accountants) its name, code, kind and place. */
export default async function AccountPage({ params }: PageProps<"/accounts/[id]">) {
  const { id } = await params;
  const chart = await getChart(true);
  const account = chart.data.find((a) => a.id === id);
  if (!account) notFound();
  const under = chart.data.find((a) => a.id === account.parent_id);

  return (
    <>
      <PageTitle crumbs={<>{TYPE_LABELS[account.type]}</>}
        title={<><span className="num">{account.code}</span> {account.name} {account.is_system && <Pill>System</Pill>} {!account.is_active && <Pill>Archived</Pill>}</>} />
      <Card className="mb-4 max-w-[1000px]" label="Account">
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3 text-sm">
          <div><dt className="text-muted">Type</dt><dd>{TYPE_LABELS[account.type]}</dd></div>
          <div><dt className="text-muted">Kind</dt><dd>{account.kind ? (KIND_LABELS[account.kind] ?? account.kind) : "General"}</dd></div>
          <div><dt className="text-muted">Under</dt><dd>{under ? <Link href={`/accounts/${encodeURIComponent(under.id)}`} className="hover:underline">{under.code} {under.name}</Link> : "Top level"}</dd></div>
          <div><dt className="text-muted">Balance</dt><dd className="num font-semibold">{formatAmount(naturalBalance(account.type, account.balance))}</dd></div>
        </dl>
        {account.is_system && <p className="mt-3 text-[13px] text-muted">A system account: the books post to it on their own (invoices, bills, payments, tax). It can be renamed and re-coded, not archived.</p>}
        <p className="mt-3 text-[13px]"><Link href={`/reports/general-ledger?account_id=${encodeURIComponent(account.id)}`} className="font-semibold text-brand hover:underline">Transactions on this account</Link></p>
        {account.is_money && <p className="mt-3 text-[13px] text-muted">A bank, wallet or cash account: its details, register and statement are under <Link href={`/banking/${encodeURIComponent(account.id)}`} className="font-semibold text-brand hover:underline">Banking and cash</Link>.</p>}
      </Card>
      {chart.can.manage && (
        <Card className="max-w-[1000px]" label="Change">
          <h2 className="mb-2 text-[15px] font-bold">Change this account</h2>
          <EditAccount account={account} accounts={chart.data} kinds={chart.kinds} />
        </Card>
      )}
    </>
  );
}
