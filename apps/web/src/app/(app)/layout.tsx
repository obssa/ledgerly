import { Suspense } from "react";
import { connection } from "next/server";
import { Sidebar } from "@/components/app-shell/Sidebar";
import { Topbar } from "@/components/app-shell/Topbar";
import { CompanyBadge, PrintedCompany, UserMenu } from "@/components/app-shell/ShellParts";
import { isDemoMode } from "@/lib/api";
import { getSession } from "@/lib/session";
import { getSubscription } from "@/lib/data";
import { subscriptionNotice } from "@/lib/subscription";

/** The subscription's notice: read-only books, an overdue payment, a trial ending soon (src/lib/subscription.ts). */
async function SubscriptionBanner() {
  const notice = subscriptionNotice(await getSubscription());
  if (!notice) return null;
  return (
    <p role="status" className={`mb-4 rounded-lg px-3 py-2 text-[13px] font-semibold ${notice.tone === "bad" ? "bg-bad-tint text-bad" : "bg-warn-tint text-warn"}`}>
      {notice.text}
    </p>
  );
}

/** Decided per request, so one built image can run in demo mode or against an API. */
async function DemoBanner() {
  await connection();
  const { demo } = await getSession();
  if (demo) {
    return (
      <p role="status" className="mb-4 inline-flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1 text-[12.5px] font-semibold text-muted">
        <span aria-hidden="true" className="size-1.5 rounded-full bg-brand" />
        Demo · signed in as {demo}
      </p>
    );
  }
  if (!isDemoMode()) return null;
  return (
    <p className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">
      Demo mode: showing sample data. Set LEDGERLY_API_URL to connect to the API.
    </p>
  );
}

export default function AppLayout({ children }: LayoutProps<"/">) {
  return (
    // From a laptop up the menu's colour runs the full height of a long page, behind the sticky menu.
    <div className="flex min-h-screen lg:bg-[linear-gradient(to_right,var(--color-side)_272px,transparent_272px)]">
      <Sidebar
        company={
          <Suspense fallback={<div className="h-[54px] rounded-xl border border-[#23493f] bg-[#102e28]" />}>
            <CompanyBadge />
          </Suspense>
        }
      />
      <div className="min-w-0 flex-1">
        <div className="z-30 border-b border-line/70 bg-canvas/90 px-4 py-3 backdrop-blur-md sm:px-6 md:sticky md:top-0 lg:px-8 print:hidden">
          <div className="mx-auto max-w-[1440px]">
            <Topbar
              user={
                <Suspense fallback={<div className="h-10 w-[84px] rounded-full bg-line-soft" />}>
                  <UserMenu />
                </Suspense>
              }
            />
          </div>
        </div>
        <main id="content" className="mx-auto max-w-[1440px] px-4 pb-12 pt-5 sm:px-6 lg:px-8 print:p-0">
          <div className="print:hidden">
            <Suspense fallback={null}>
              <DemoBanner />
            </Suspense>
            <Suspense fallback={null}>
              <SubscriptionBanner />
            </Suspense>
          </div>
          <Suspense fallback={null}>
            <PrintedCompany />
          </Suspense>
          {/* Pages read the session and call the API per request (src/lib/data.ts). */}
          <Suspense fallback={<PageSkeleton />}>{children}</Suspense>
        </main>
      </div>
    </div>
  );
}

/** While a page loads: the shape of a page, not a word. */
function PageSkeleton() {
  return (
    <div aria-busy="true" aria-label="Loading" className="mt-2 animate-pulse">
      <div className="h-7 w-64 rounded-lg bg-line-soft" />
      <div className="mt-3 h-4 w-96 max-w-full rounded bg-line-soft" />
      <div className="mt-6 grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-4">
        {[0, 1, 2, 3].map((i) => <div key={i} className="h-[112px] rounded-[14px] border border-line bg-surface" />)}
      </div>
      <div className="mt-4 h-72 rounded-[14px] border border-line bg-surface" />
    </div>
  );
}
