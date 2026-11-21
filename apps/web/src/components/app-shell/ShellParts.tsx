import Link from "next/link";
import { signOut, switchCompany } from "@/app/(auth)/actions";
import { getShellContext } from "@/lib/data";
import { Icon } from "./Icon";

/** Server parts of the app shell; the layout renders each inside <Suspense>. */

/** The company being worked in, and the switcher: other companies, and adding one to the same organisation. */
/** On paper only: whose books a printed report is from. */
export async function PrintedCompany() {
  const { companyName } = await getShellContext();
  return <p className="hidden text-[13px] font-bold print:block">{companyName}</p>;
}

export async function CompanyBadge() {
  const { companyName, companyNote, companies, companyId, organisationId, demo } = await getShellContext();
  const others = companies.filter((c) => c.id !== companyId && !c.archived);
  const archived = companies.filter((c) => c.id !== companyId && c.archived);

  return (
    <details className="group w-full rounded-xl border border-[#23493f] bg-[#102e28] text-white">
      <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2.5 [&::-webkit-details-marker]:hidden">
        <span className="flex min-w-0 items-center gap-2.5">
          <span aria-hidden="true" className="grid size-8 shrink-0 place-items-center rounded-lg bg-[#1d4a40] text-[12px] font-extrabold text-mint">{initials(companyName)}</span>
          <span className="min-w-0">
            <span className="block truncate text-[13.5px] font-bold">{companyName}</span>
            <span className="mt-0.5 block truncate text-[11.5px] text-[#a9bdb7]">{companyNote}</span>
          </span>
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
        {archived.length > 0 && (
          <details className="mt-1 border-t border-[#23493f] pt-1">
            <summary className="cursor-pointer list-none rounded-md px-2 py-1.5 text-xs font-semibold text-[#a9bdb7] hover:bg-side [&::-webkit-details-marker]:hidden">
              Archived ({archived.length})
            </summary>
            {archived.map((c) => (
              <form key={c.id} action={switchCompany}>
                <input type="hidden" name="company_id" value={c.id} />
                <button type="submit" className="block w-full rounded-md px-2 py-1.5 text-left text-sm text-[#a9bdb7] hover:bg-side">{c.name} <span className="text-[11px]">· read-only</span></button>
              </form>
            ))}
          </details>
        )}
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
  const { userName, companyName, demo } = await getShellContext();
  return (
    <details className="group relative">
      <summary aria-label={userName ? `Signed in as ${userName}` : "Signed in"}
        className="flex cursor-pointer list-none items-center gap-2 rounded-full border border-line bg-surface py-1 pl-1 pr-2.5 shadow-[0_1px_2px_rgba(14,26,23,0.04)] hover:border-[#c5cec9] [&::-webkit-details-marker]:hidden">
        <span className="grid size-8 place-items-center rounded-full bg-brand text-[13px] font-bold text-white">{initials(userName)}</span>
        <span className="hidden max-w-[140px] truncate text-[13px] font-semibold xl:inline">{userName || "Signed in"}</span>
        <Icon name="chevron" className="size-3.5 rotate-90 text-muted" />
      </summary>
      <div className="absolute right-0 top-[calc(100%+6px)] z-40 w-64 rounded-2xl border border-line bg-surface p-2 shadow-[0_16px_48px_rgba(12,36,32,0.18)]">
        <div className="border-b border-line-soft px-2.5 pb-2.5 pt-1.5">
          <div className="truncate text-sm font-bold">{userName || "Signed in"}</div>
          <div className="truncate text-xs text-muted">{companyName}</div>
        </div>
        <Link href="/security" className="mt-1 block rounded-lg px-2.5 py-2 text-sm font-semibold text-ink no-underline hover:bg-canvas">Sign-in security</Link>
        <Link href="/settings" className="block rounded-lg px-2.5 py-2 text-sm font-semibold text-ink no-underline hover:bg-canvas">Company settings</Link>
        {!demo && (
          <form action={signOut}>
            <button type="submit" className="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm font-semibold text-bad hover:bg-bad-tint">
              <Icon name="signout" className="size-4" /> Sign out
            </button>
          </form>
        )}
      </div>
    </details>
  );
}
