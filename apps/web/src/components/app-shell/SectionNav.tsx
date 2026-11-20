"use client";

import { Suspense, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { CREATE, locate } from "@/lib/navigation";
import { Icon } from "./Icon";

/** Where this page sits: Sales › Invoices. */
export function Breadcrumb() {
  return (
    <Suspense fallback={<span className="h-5" />}>
      <CurrentBreadcrumb />
    </Suspense>
  );
}

function CurrentBreadcrumb() {
  const pathname = usePathname();
  const here = locate(pathname);
  if (!here || here.section.id === "home") return <span className="text-[13px] font-semibold text-muted">Overview</span>;
  const deeper = pathname !== here.item.href;
  return (
    <nav aria-label="Breadcrumb" className="flex min-w-0 items-center gap-1.5 text-[13px] font-semibold text-muted">
      <span className="truncate">{here.section.label}</span>
      <Icon name="chevron" className="size-3.5" />
      {deeper ? <Link href={here.item.href} className="truncate text-muted no-underline hover:text-ink">{here.item.label}</Link> : <span className="truncate text-ink" aria-current="page">{here.item.label}</span>}
    </nav>
  );
}

/**
 * The pages of the section being worked in, as tabs across the top: from Invoices to Payments received to Credit
 * notes without going back to the menu.
 */
export function SectionTabs() {
  return (
    <Suspense fallback={null}>
      <CurrentTabs />
    </Suspense>
  );
}

function CurrentTabs() {
  const pathname = usePathname();
  const here = locate(pathname);
  const tabs = here?.section.items.filter((i) => !i.soon && !i.tabHidden) ?? [];
  const activeRef = useRef<HTMLAnchorElement>(null);
  useEffect(() => activeRef.current?.scrollIntoView({ block: "nearest", inline: "nearest" }), [pathname]);
  if (!here || tabs.length < 2) return null;
  return (
    <nav aria-label={`${here.section.label} pages`} className="tab-scroll -mx-1 mt-4 overflow-x-auto print:hidden">
      <ul className="flex min-w-max gap-1 border-b border-line px-1">
        {tabs.map((t) => {
          const active = here.item.href === t.href;
          return (
            <li key={t.href}>
              <Link ref={active ? activeRef : undefined} href={t.href} aria-current={active ? "page" : undefined}
                className={`relative block whitespace-nowrap px-3 pb-2.5 pt-1.5 text-[13.5px] font-semibold no-underline transition-colors ${
                  active ? "text-brand after:absolute after:inset-x-2 after:-bottom-px after:h-[2px] after:rounded-full after:bg-brand" : "text-muted hover:text-ink"
                }`}>
                {t.label}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

const GROUPS = [...new Set(CREATE.map((c) => c.group))];

/** "+ New": every form in one menu, grouped as the sidebar is. */
export function NewMenu() {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const outside = (e: MouseEvent) => box.current && !box.current.contains(e.target as Node) && setOpen(false);
    const escape = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", outside);
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("mousedown", outside);
      document.removeEventListener("keydown", escape);
    };
  }, [open]);

  return (
    <div ref={box} className="relative">
      <button type="button" onClick={() => setOpen((o) => !o)} aria-expanded={open} aria-haspopup="menu" className="btn btn-primary">
        <Icon name="plus" className="size-4" /> New
      </button>
      {open && (
        <div role="menu" className="absolute right-0 top-[calc(100%+6px)] z-40 grid w-[min(520px,calc(100vw-32px))] grid-cols-1 gap-x-2 rounded-2xl border border-line bg-surface p-3 shadow-[0_16px_48px_rgba(12,36,32,0.18)] sm:grid-cols-3">
          {GROUPS.map((g) => (
            <div key={g} className="py-1">
              <div className="px-2.5 pb-1.5 text-[11px] font-bold uppercase tracking-[0.08em] text-muted">{g}</div>
              {CREATE.filter((c) => c.group === g).map((c) => (
                <Link key={c.href} role="menuitem" href={c.href} onClick={() => setOpen(false)}
                  className="block rounded-lg px-2.5 py-2 text-sm font-semibold text-ink no-underline hover:bg-brand-tint hover:text-brand-dark">
                  {c.label}
                </Link>
              ))}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
