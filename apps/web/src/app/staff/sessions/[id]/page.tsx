import Link from "@/components/Link";
import { redirect } from "next/navigation";
import { Suspense } from "react";
import { ApiError } from "@/lib/api";
import { formatAmount } from "@/lib/format";
import { staffApi, staffToken } from "@/lib/staff";
import { staffEndSession } from "../../actions";

export const metadata = { title: "Support session" };

/** One company's books as a support session sees them: read only, for the reason given, until it ends (SECURITY.md S8). */
export default function StaffSessionPage({ params }: PageProps<"/staff/sessions/[id]">) {
  return <Suspense fallback={null}><Session params={params} /></Suspense>;
}

async function Session({ params }: Pick<PageProps<"/staff/sessions/[id]">, "params">) {
  const token = await staffToken();
  if (!token) redirect("/staff/sign-in");
  const { id } = await params;
  let view;
  try {
    view = await staffApi.session(token, id);
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/staff/sign-in");
    if (error instanceof ApiError && error.status === 404) {
      return <p className="text-sm">This support session is over. <Link href="/staff" className="font-bold text-brand">Start a new one, with a reason.</Link></p>;
    }
    throw error;
  }
  const c = view.company;

  return (
    <>
      <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="text-[13px] text-muted">Support session, read only, until {new Date(view.session.expires_at).toLocaleTimeString("en-GB")}</p>
          <h1 className="text-[22px] font-extrabold tracking-tight">{c.trading_name || c.legal_name}</h1>
          <p className="text-[13px]">Reason: {view.session.reason}</p>
        </div>
        <form action={staffEndSession}><input type="hidden" name="id" value={view.session.id} /><button type="submit" className="btn">End the session</button></form>
      </div>
      <p className="mb-4 text-[13px] text-muted">
        {c.country_code} · {c.base_currency} · {c.status} · converted {c.conversion_date ?? "—"} · books closed until {c.books_closed_until ?? "—"} ·
        {" "}{view.journal_entries.n} journal entries, the last on {view.journal_entries.last ?? "—"}
      </p>
      <h2 className="mb-1 text-[15px] font-bold">Documents</h2>
      <p className="mb-4 text-[13px]">{view.documents.map((d) => `${d.type} ${d.status}: ${d.n}`).join(" · ") || "None."}</p>
      <h2 className="mb-1 text-[15px] font-bold">Trial balance</h2>
      <div className="mb-5 overflow-x-auto">
        <table className="tbl">
          <thead><tr><th scope="col">Account</th><th scope="col" className="!text-right">Debit</th><th scope="col" className="!text-right">Credit</th></tr></thead>
          <tbody>{view.trial_balance.map((r) => <tr key={r.code}><td><span className="num">{r.code}</span> {r.name}</td><td className="num text-right">{formatAmount(r.debit)}</td><td className="num text-right">{formatAmount(r.credit)}</td></tr>)}</tbody>
        </table>
      </div>
      <h2 className="mb-1 text-[15px] font-bold">Latest audit records</h2>
      <ul className="text-[13px]">
        {view.audit.map((a, i) => <li key={i} className="border-b border-line py-1"><span className="num text-muted">{a.created_at.slice(0, 16).replace("T", " ")}</span> {a.action} · {a.subject_type}{a.reason ? ` · ${a.reason}` : ""}</li>)}
      </ul>
    </>
  );
}
