"use client";

import { Suspense, useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import { ADMIN, SECTIONS, activeHref, firstPage, locate, type NavSection } from "@/lib/navigation";
import { Icon } from "./Icon";

/** Opens and closes the menu on a phone; the button is in the top bar (MenuButton). */
export const MENU_EVENT = "ledgerly:menu";

/**
 * The main menu: one row per section of the cycle, each opening to its pages. The section of the page being worked
 * on is open; the others stay folded so the whole menu fits on a screen. On a phone it slides in from the side.
 * `company` is rendered on the server (CompanyBadge) and passed in, since this component runs in the browser.
 */
export function Sidebar({ company }: { company: ReactNode }) {
  const [drawer, setDrawer] = useState(false);
  useEffect(() => {
    const toggle = () => setDrawer((d) => !d);
    const escape = (e: KeyboardEvent) => e.key === "Escape" && setDrawer(false);
    window.addEventListener(MENU_EVENT, toggle);
    window.addEventListener("keydown", escape);
    return () => {
      window.removeEventListener(MENU_EVENT, toggle);
      window.removeEventListener("keydown", escape);
    };
  }, []);

  return (
    <>
      {drawer && <div aria-hidden="true" onClick={() => setDrawer(false)} className="fixed inset-0 z-40 bg-[#0c2420]/50 backdrop-blur-[2px] lg:hidden" />}
      <nav
        aria-label="Main"
        id="main-menu"
        className={`fixed inset-y-0 left-0 z-50 flex w-[272px] max-w-[86vw] shrink-0 flex-col bg-side text-side-text transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 print:hidden ${
          drawer ? "translate-x-0 shadow-2xl" : "-translate-x-full"
        }`}
      >
        <div className="flex items-center justify-between gap-2.5 px-5 pb-4 pt-5">
          <Link href="/" className="flex items-center gap-2.5 no-underline">
            <svg width="30" height="30" viewBox="0 0 28 28" aria-hidden="true">
              <rect width="28" height="28" rx="8" fill="#34D399" />
              <path d="M8 19V9M8 19h12M13 15l3-4 4 3" stroke="#0C2420" strokeWidth="2.2" fill="none" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
            <span className="text-[19px] font-extrabold tracking-tight text-white">Evenbooks</span>
          </Link>
          <button type="button" onClick={() => setDrawer(false)} className="grid size-9 place-items-center rounded-lg text-side-label hover:bg-side-hover hover:text-white lg:hidden" aria-label="Close the menu">
            <Icon name="close" />
          </button>
        </div>

        <div className="px-3.5">{company}</div>

        {/* On pages like /customers/[id] the path is known only per request: the menu shows without the highlight
            until then, so the page itself can still be prerendered. */}
        <div className="side-scroll mt-3 min-h-0 flex-1 overflow-y-auto px-3 pb-4">
          <Suspense fallback={<Sections pathname={null} onNavigate={() => setDrawer(false)} />}>
            <CurrentSections onNavigate={() => setDrawer(false)} />
          </Suspense>
        </div>
      </nav>
    </>
  );
}

function CurrentSections({ onNavigate }: { onNavigate: () => void }) {
  const pathname = usePathname();
  const search = useSearchParams().toString();
  useEffect(() => {
    onNavigate(); // close the drawer when a page opens
  }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps -- only on a new page
  return <Sections pathname={pathname} search={search} onNavigate={onNavigate} />;
}

function Sections({ pathname, search = "", onNavigate }: { pathname: string | null; search?: string; onNavigate: () => void }) {
  const here = pathname === null ? null : locate(pathname);
  const [toggled, setToggled] = useState<Record<string, boolean>>({});
  const isOpen = (s: NavSection) => toggled[s.id] ?? here?.section.id === s.id;
  const flip = (s: NavSection) => setToggled((t) => ({ ...t, [s.id]: !isOpen(s) }));

  const section = (s: NavSection) => {
    const current = here?.section.id === s.id;
    if (s.items.length === 1) {
      const item = s.items[0];
      return (
        <Link
          key={s.id}
          href={item.soon ? "#" : item.href}
          onClick={onNavigate}
          aria-current={current ? "page" : undefined}
          aria-disabled={item.soon || undefined}
          className={`side-row ${current ? "side-row-active" : ""} ${item.soon ? "pointer-events-none opacity-60" : ""}`}
        >
          <Icon name={s.icon} />
          <span className="flex-1">{s.label}</span>
          {item.soon && <span className="rounded-full bg-side-hover px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-side-label">Soon</span>}
        </Link>
      );
    }
    const open = isOpen(s);
    const first = firstPage(s);
    return (
      <div key={s.id}>
        {/* The name opens the section's first page; the arrow beside it only folds the list in or out. */}
        <div className={`group/row flex items-center rounded-lg transition-colors hover:bg-side-hover ${current ? "bg-side-hover/70" : ""}`}>
          <Link
            href={first.href}
            onClick={() => {
              setToggled({ [s.id]: true }); // the section opened; the others fold back unless they hold the page
              onNavigate();
            }}
            className={`flex min-w-0 flex-1 items-center gap-3 rounded-lg py-2 pl-3 text-sm no-underline hover:text-white ${current ? "font-semibold text-white" : "font-medium text-side-text"}`}
          >
            <Icon name={s.icon} className={`size-[18px] ${current ? "text-mint" : ""}`} />
            <span className="truncate">{s.label}</span>
          </Link>
          <button
            type="button"
            onClick={() => flip(s)}
            aria-expanded={open}
            aria-controls={`menu-${s.id}`}
            aria-label={`${open ? "Hide" : "Show"} the ${s.label} pages`}
            title={`${open ? "Hide" : "Show"} the ${s.label} pages`}
            className={`mr-1.5 grid size-7 shrink-0 place-items-center rounded-md transition-all duration-200 ${
              open ? "bg-white/10 text-white" : "bg-white/[0.04] text-side-label group-hover/row:bg-white/10 group-hover/row:text-white"
            } hover:!bg-mint/20 hover:!text-mint`}
          >
            <Icon name="chevron" className={`size-4 transition-transform duration-300 ease-out ${open ? "rotate-90" : "group-hover/row:animate-[nudge_0.9s_ease-in-out_infinite]"}`} />
          </button>
        </div>
        <div className={`grid transition-[grid-template-rows] duration-300 ease-out ${open ? "grid-rows-[1fr]" : "grid-rows-[0fr]"}`} inert={!open}>
          <ul id={`menu-${s.id}`} className="ml-[21px] min-h-0 overflow-hidden border-l border-[#23493f] pl-2.5">
            {s.items.map((item, i) => {
              const active = pathname !== null && here?.section.id === s.id && activeHref(s.items, pathname, search) === item.href;
              return (
                <li key={item.href} style={{ transitionDelay: open ? `${Math.min(i, 8) * 25}ms` : "0ms" }}
                  className={`transition-[opacity,transform] duration-300 ease-out first:pt-0.5 last:pb-1.5 ${open ? "translate-x-0 opacity-100" : "-translate-x-1 opacity-0"}`}>
                  <Link
                    href={item.soon ? "#" : item.href}
                    onClick={onNavigate}
                    aria-current={active ? "page" : undefined}
                    aria-disabled={item.soon || undefined}
                    className={`relative flex items-center justify-between rounded-md px-2.5 py-[7px] text-[13.5px] no-underline transition-colors hover:bg-side-hover hover:text-white ${
                      active ? "bg-side-hover font-semibold text-white before:absolute before:-left-[11px] before:top-1.5 before:bottom-1.5 before:w-[2px] before:rounded-full before:bg-mint" : ""
                    } ${item.soon ? "pointer-events-none opacity-60" : ""}`}
                  >
                    {item.label}
                    {item.soon && <span className="text-[10px] font-bold uppercase tracking-wide text-side-label">Soon</span>}
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      </div>
    );
  };

  return (
    <>
      <div className="grid gap-0.5">{SECTIONS.map(section)}</div>
      <div className="mt-3 border-t border-[#23493f] pt-3">{section(ADMIN)}</div>
    </>
  );
}

/** The menu button on a phone (the menu is always shown from a laptop up). */
export function MenuButton() {
  return (
    <button type="button" onClick={() => window.dispatchEvent(new Event(MENU_EVENT))} aria-controls="main-menu" aria-label="Open the menu"
      className="grid size-10 place-items-center rounded-[10px] border border-line bg-surface text-ink hover:border-muted lg:hidden">
      <Icon name="menu" />
    </button>
  );
}
