import { signOut } from "@/app/(auth)/actions";
import { getShellContext } from "@/lib/data";

/** Server parts of the app shell; the layout renders each inside <Suspense>. */

export async function CompanyBadge() {
  const { companyName, companyNote } = await getShellContext();
  return (
    <div className="w-full rounded-[10px] border border-[#23493f] bg-side-hover px-3 py-2.5 text-left text-white">
      <span className="block text-sm font-bold">{companyName}</span>
      <span className="mt-0.5 block text-xs text-[#a9bdb7]">{companyNote}</span>
    </div>
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
