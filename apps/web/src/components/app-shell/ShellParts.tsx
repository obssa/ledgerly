import Link from "next/link";
import { signOut, switchCompany } from "@/app/(auth)/actions";
import { getShellContext } from "@/lib/data";

/** Server parts of the app shell; the layout renders each inside <Suspense>. */

/** The company being worked in, and the switcher: other companies, and adding one to the same organisation. */
/** On paper only: whose books a printed report is from. */
export async function PrintedCompany() {
  const { companyName } = await getShellContext();
  return <p className="hidden text-[13px] font-bold print:block">{companyName}</p>;
}

export async function CompanyBadge() {
  const { companyName, companyNote, companies, companyId, organisationId, demo } = await getShellContext();
  const others = companies.filter((c) => c.id !== companyId);

  return (
    <details className="group w-full rounded-[10px] border border-[#23493f] bg-side-hover text-white">
      <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2.5 [&::-webkit-details-marker]:hidden">
        <span>
          <span className="block text-sm font-bold">{companyName}</span>
          <span className="mt-0.5 block text-xs text-[#a9bdb7]">{companyNote}</span>
        </span>
        <span aria-hidden="true" className="text-xs text-[#a9bdb7] group-open:rotate-180">▾</span>
        <span className="sr-only">Switch company</span>
      </summary>
      <div className="border-t border-[#23493f] px-1.5 py-1.5">
        {others.map((c) => (
          <form key={c.id} action={switchCompany}>
            <input type="hidden" name="company_id" value={c.id} />
            <button type="submit" className="block w-full rounded-md px-2 py-1.5 text-left text-sm hover:bg-side">{c.name}</button>
          </form>
        ))}
        {!demo && (
          <Link
            href={organisationId ? `/onboarding?organisation=${encodeURIComponent(organisationId)}` : "/onboarding"}
            className="block rounded-md px-2 py-1.5 text-sm font-semibold text-mint hover:bg-side"
          >
            + Add a company
          </Link>
        )}
      </div>
    </details>
  );
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  return ((parts[0]?.[0] ?? "") + (parts.length > 1 ? parts[parts.length - 1][0] : "")).toUpperCase() || "?";
}

export async function UserMenu() {
  const { userName, demo } = await getShellContext();
  return (
    <div className="flex items-center gap-2">
      <div aria-label={userName ? `Signed in as ${userName}` : "Signed in"} className="grid size-10 place-items-center rounded-full bg-brand text-sm font-bold text-white">
        {initials(userName)}
      </div>
      {!demo && (
        <form action={signOut}>
          <button type="submit" className="btn">Sign out</button>
        </form>
      )}
    </div>
  );
}
