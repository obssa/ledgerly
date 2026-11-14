import { Suspense } from "react";
import { connection } from "next/server";
import { Sidebar } from "@/components/app-shell/Sidebar";
import { Topbar } from "@/components/app-shell/Topbar";
import { CompanyBadge, UserMenu } from "@/components/app-shell/ShellParts";
import { isDemoMode } from "@/lib/api";

/** Decided per request, so one built image can run in demo mode or against an API. */
async function DemoBanner() {
  await connection();
  if (!isDemoMode()) return null;
  return (
    <p className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">
      Demo mode: showing sample data. Set LEDGERLY_API_URL to connect to the API.
    </p>
  );
}

export default function AppLayout({ children }: LayoutProps<"/">) {
  return (
    <div className="flex min-h-screen flex-wrap">
      <Sidebar
        company={
          <Suspense fallback={<div className="h-[58px] rounded-[10px] border border-[#23493f] bg-side-hover" />}>
            <CompanyBadge />
          </Suspense>
        }
      />
      <main className="min-w-0 flex-[999_1_560px] px-8 pb-10 pt-5">
        <Suspense fallback={null}>
          <DemoBanner />
        </Suspense>
        <Topbar
          user={
            <Suspense fallback={<div className="size-10 rounded-full bg-line-soft" />}>
              <UserMenu />
            </Suspense>
          }
        />
        {/* Pages read the session and call the API per request (src/lib/data.ts). */}
        <Suspense fallback={<p className="mt-8 text-sm text-muted">Loading…</p>}>{children}</Suspense>
      </main>
    </div>
  );
}
