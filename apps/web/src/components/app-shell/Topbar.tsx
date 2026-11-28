import type { ReactNode } from "react";
import { CommandPalette } from "./CommandPalette";
import { MenuButton } from "./Sidebar";
import { NewMenu, SectionTabs } from "./SectionNav";

/**
 * The bar over every page: the search that opens anything (Ctrl+K), the "+ New" menu and who is signed in; under it,
 * the tabs of the section being worked in. Where you are is the one breadcrumb under the tabs (PageTitle, PageCrumbs).
 */
export function Topbar({ user }: { user: ReactNode }) {
  return (
    <div className="print:hidden">
      <header className="flex flex-wrap items-center gap-3">
        <MenuButton />
        <div className="hidden min-w-0 flex-1 md:block" />
        <div className="order-last w-full md:order-none md:w-[min(420px,40vw)]">
          <CommandPalette />
        </div>
        <div className="ml-auto flex items-center gap-2 md:ml-0">
          <NewMenu />
          {user}
        </div>
      </header>
      <SectionTabs />
    </div>
  );
}
