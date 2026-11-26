import Link from "@/components/Link";
import { AccountTabs } from "../AccountTabs";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill, Segmented } from "@/components/ui";
import { getCheques } from "@/lib/data";
import { formatAmount } from "@/lib/format";

export const metadata = { title: "Cheques" };

const STATUS = {
  issued: ["Issued", "warn"], cleared: ["Cleared", "good"], cancelled: ["Cancelled", "neutral"],
  received: ["Received", "warn"], bounced: ["Bounced", "bad"], reversed: ["Reversed", "neutral"],
} as const;

/** Module 5: a bank account's cheque register. Cheques written until the bank shows them, and cheques received. */
export default async function ChequesPage({ params, searchParams }: PageProps<"/banking/[id]/cheques">) {
  const { id } = await params;
  const direction = (await searchParams).direction === "in" ? "in" : "out";
  const register = await getCheques(id, direction);
  if (!register) notFound();
  const a = register.account;
  const base = `/banking/${encodeURIComponent(id)}/cheques`;
  const detail = (row: (typeof register.data)[number]) =>
    row.kind === "refund" ? `/refunds/${encodeURIComponent(row.id)}` : direction === "out" ? `/payments-made/${encodeURIComponent(row.id)}` : `/payments/${encodeURIComponent(row.id)}`;

  return (
    <>
      <PageTitle
        eyebrow={<><Link href="/banking" className="hover:underline">Banking and cash</Link> / <Link href={`/banking/${encodeURIComponent(id)}`} className="hover:underline">
          <span className="num">{a.code}</span> {a.name}</Link> / Cheques</>}
        title="Cheques"
      />
      <AccountTabs id={id} current="cheques" kind="bank" />
      <Segmented label="Cheques" items={[
        { href: base, label: "Written", active: direction === "out" },
        { href: `${base}?direction=in`, label: "Received", active: direction === "in" },
      ]} />
      {direction === "out" && (
        <p className="mb-4 text-[14px] text-muted">
          {register.next_number ? <>Next cheque number: <span className="num font-semibold text-ink">{register.next_number}</span>. </> : null}
          A cheque is cleared once the bank statement shows it. A cancelled cheque keeps its number.
        </p>
      )}
      <Card className="!p-2" label="Register">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Number</th><th scope="col">Date</th><th scope="col">{direction === "out" ? "Paid to" : "From"}</th><th scope="col">Status</th><th scope="col" className="!text-right">Amount ({a.currency})</th></tr></thead>
            <tbody>
              {register.data.length === 0 && <tr><td colSpan={5} className="text-muted">{direction === "out" ? "No cheques written from this account yet." : "No cheques received into this account yet."}</td></tr>}
              {register.data.map((c) => (
                <tr key={c.id}>
                  <td className="num font-semibold"><Link href={detail(c)} className="hover:underline">{c.number ?? "—"}</Link></td>
                  <td className="num">{c.date}</td>
                  <td>{c.contact ?? "—"}</td>
                  <td><Pill tone={STATUS[c.status][1]}>{STATUS[c.status][0]}</Pill>{c.bounced_on && <span className="num ml-2 text-[13px] text-muted">{c.bounced_on}</span>}</td>
                  <td className="num text-right">{formatAmount(c.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  );
}
