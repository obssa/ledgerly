import { redirect } from "next/navigation";
import { Suspense } from "react";
import { ApiError } from "@/lib/api";
import { staffApi, staffToken } from "@/lib/staff";
import { staffSignOut } from "./actions";
import { StartSessionForm } from "./StaffForms";

export const metadata = { title: "Staff console" };

/** The customers, to open a read-only support session for one of them with a reason (SECURITY.md S8). */
export default function StaffHome({ searchParams }: PageProps<"/staff">) {
  return <Suspense fallback={null}><Customers searchParams={searchParams} /></Suspense>;
}

async function Customers({ searchParams }: Pick<PageProps<"/staff">, "searchParams">) {
  const token = await staffToken();
  if (!token) redirect("/staff/sign-in");
  const { search } = await searchParams;
  const q = typeof search === "string" ? search.slice(0, 100) : "";
  let me, companies;
  try {
    [me, companies] = await Promise.all([staffApi.me(token), staffApi.companies(token, q)]);
  } catch (error) {
    if (error instanceof ApiError && (error.status === 401 || error.status === 404)) redirect("/staff/sign-in");
    throw error;
  }

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-[22px] font-extrabold tracking-tight">Customers</h1>
        <form action={staffSignOut} className="flex items-center gap-3 text-[13px]"><span className="text-muted">{me.name}</span><button type="submit" className="btn">Sign out</button></form>
      </div>
      <form action="/staff" className="mb-4 flex gap-2">
        <input className="field max-w-[320px]" name="search" defaultValue={q} placeholder="Company name" aria-label="Find a company" />
        <button type="submit" className="btn">Find</button>
      </form>
      {companies.data.length === 0 ? <p className="text-sm text-muted">No company found.</p> : (
        <ul className="grid gap-4">
          {companies.data.map((c) => (
            <li key={c.id} className="rounded-xl border border-line p-3">
              <p className="font-bold">{c.trading_name || c.legal_name} <span className="text-[12px] font-normal text-muted">{c.country_code} · {c.status} · since {c.created_at.slice(0, 10)}</span></p>
              <div className="mt-2"><StartSessionForm companyId={c.id} name={c.trading_name || c.legal_name} /></div>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
