import { Sidebar } from "@/components/app-shell/Sidebar";
import { Topbar } from "@/components/app-shell/Topbar";
import { isDemoMode } from "@/lib/api";

export default function AppLayout({ children }: LayoutProps<"/">) {
  return (
    <div className="flex min-h-screen flex-wrap">
      <Sidebar />
      <main className="min-w-0 flex-[999_1_560px] px-8 pb-10 pt-5">
        {isDemoMode && (
          <p className="mb-4 rounded-lg bg-warn-tint px-3 py-2 text-[13px] font-semibold text-warn">
            Demo mode: showing sample data. Set LEDGERLY_API_URL to connect to the API.
          </p>
        )}
        <Topbar />
        {children}
      </main>
    </div>
  );
}
