"use client";

import { Suspense, useEffect, useRef, useState } from "react";
import Link from "@/components/Link";
import { usePathname, useSearchParams } from "next/navigation";
import { CREATE, activeHref, locate } from "@/lib/navigation";
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
  const search = useSearchParams().toString();
  const here = locate(pathname);
  const tabs = here?.section.items.filter((i) => !i.soon && !i.tabHidden) ?? [];
  const open = activeHref(tabs, pathname, search);
  const scroller = useRef<HTMLDivElement>(null);
  const activeRef = useRef<HTMLAnchorElement>(null);
  const [edges, setEdges] = useState({ left: false, right: false });

  // Arrows show only while the row is wider than the screen and there is more to see that way.
  useEffect(() => {
    const el = scroller.current;
    if (!el) return;
    const measure = () => setEdges({ left: el.scrollLeft > 4, right: el.scrollLeft + el.clientWidth < el.scrollWidth - 4 });
    const frame = requestAnimationFrame(measure);
    el.addEventListener("scroll", measure, { passive: true });
    window.addEventListener("resize", measure);
    return () => {
      cancelAnimationFrame(frame);
      el.removeEventListener("scroll", measure);
      window.removeEventListener("resize", measure);
    };
  }, [pathname, tabs.length]);

  // Braces: newer browsers return a promise from scrollIntoView, and an effect's return value is its cleanup.
  useEffect(() => {
    activeRef.current?.scrollIntoView({ block: "nearest", inline: "center" });
  }, [pathname, search]);

  if (!here || tabs.length < 2) return null;
  const nudge = (dir: 1 | -1) => scroller.current?.scrollBy({ left: dir * Math.max(200, (scroller.current?.clientWidth ?? 400) * 0.6), behavior: "smooth" });
  const arrow = (dir: 1 | -1) => (
    <button type="button" onClick={() => { nudge(dir); }} aria-label={dir < 0 ? "Earlier pages" : "More pages"} tabIndex={-1}
      className={`absolute inset-y-0 z-10 flex w-12 items-center ${dir < 0 ? "left-0 justify-start bg-gradient-to-r" : "right-0 justify-end bg-gradient-to-l"} from-canvas via-canvas/85 to-transparent`}>
      <span className="grid size-7 place-items-center rounded-full border border-line bg-surface text-ink shadow-card hover:border-brand/40 hover:text-brand">
        <Icon name="chevron" className={`size-4 ${dir < 0 ? "rotate-180" : ""}`} />
      </span>
    </button>
  );
  return (
    <nav aria-label={`${here.section.label} pages`} className="relative mt-3 print:hidden">
      {edges.left && arrow(-1)}
      <div ref={scroller} className="tab-scroll overflow-x-auto">
        <ul className="seg min-w-max">
          {tabs.map((t) => {
            const active = open === t.href;
            return (
              <li key={t.href}>
                <Link ref={active ? activeRef : undefined} href={t.href} aria-current={active ? "page" : undefined}
                  className={`seg-item ${active ? "seg-item-active" : ""}`}>
                  {t.label}
                </Link>
              </li>
            );
          })}
        </ul>
      </div>
      {edges.right && arrow(1)}
    </nav>
  );
}

/** The forms people start most, as large tiles at the top of "+ New". */
const FEATURED: { href: string; label: string; note: string; icon: "sales" | "receivable" | "purchases" | "wallet" }[] = [
  { href: "/invoices/new", label: "Invoice", note: "Bill a customer", icon: "sales" },
  { href: "/payments/new", label: "Payment received", note: "Money in from a customer", icon: "receivable" },
  { href: "/bills/new", label: "Bill", note: "A supplier's invoice", icon: "purchases" },
  { href: "/expenses/new", label: "Expense", note: "Spending paid on the spot", icon: "wallet" },
];
const FEATURED_HREFS = new Set(FEATURED.map((f) => f.href));
const GROUPS = [...new Set(CREATE.map((c) => c.group))];
const GROUP_ICON = { Sales: "sales", Purchases: "purchases", Accounting: "books" } as const;

/** Typing in a field must never open the menu. */
const typing = (t: EventTarget | null) => t instanceof HTMLElement && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));

/**
 * "+ New": a shortcut to every form. The four most used are tiles; the rest are grouped as in the menu. N opens it
 * from anywhere (outside a field), arrows move through it, Esc closes it.
 */
export function NewMenu() {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  const button = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key.toLowerCase() !== "n" || e.ctrlKey || e.metaKey || e.altKey || typing(e.target) || document.querySelector("dialog[open]")) return;
      e.preventDefault();
      setOpen((o) => !o);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  useEffect(() => {
    if (!open) return;
    box.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
    const outside = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) setOpen(false);
    };
    const keys = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        setOpen(false);
        button.current?.focus();
        return;
      }
      if (!["ArrowDown", "ArrowUp", "ArrowRight", "ArrowLeft", "Home", "End"].includes(e.key)) return;
      const items = [...(box.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])];
      if (items.length === 0) return;
      const at = items.indexOf(document.activeElement as HTMLElement);
      const next = e.key === "Home" ? 0 : e.key === "End" ? items.length - 1
        : e.key === "ArrowDown" || e.key === "ArrowRight" ? (at + 1) % items.length : (at - 1 + items.length) % items.length;
      e.preventDefault();
      items[next]?.focus();
    };
    document.addEventListener("mousedown", outside);
    document.addEventListener("keydown", keys);
    return () => {
      document.removeEventListener("mousedown", outside);
      document.removeEventListener("keydown", keys);
    };
  }, [open]);

  return (
    <div ref={box} className="relative">
      <button ref={button} type="button" onClick={() => setOpen((o) => !o)} aria-expanded={open} aria-haspopup="menu" aria-keyshortcuts="N"
        title="Create something new (N)"
        className="btn btn-primary !gap-2 !pl-1.5 !pr-2.5 shadow-[0_6px_18px_-6px_rgba(15,118,110,0.7)] transition-transform [animation:halo_1.6s_ease-out_0.8s_3] hover:-translate-y-px active:translate-y-0">
        <span className="grid size-7 place-items-center rounded-[7px] bg-white/15">
          <Icon name="plus" className={`size-4 transition-transform duration-300 ${open ? "rotate-45" : ""}`} />
        </span>
        <span>New</span>
        <kbd className="hidden rounded-[5px] bg-white/15 px-1.5 py-px font-sans text-[11px] font-semibold lg:inline">N</kbd>
      </button>
      {open && (
        <div role="menu" aria-label="Create new"
          className="absolute right-0 top-[calc(100%+8px)] z-40 w-[min(640px,calc(100vw-32px))] origin-top-right animate-[pop-in_160ms_ease-out] overflow-hidden rounded-2xl border border-line bg-surface shadow-[0_24px_64px_-12px_rgba(12,36,32,0.35)]">
          <div className="border-b border-line-soft bg-[linear-gradient(180deg,#f3faf8,transparent)] p-3">
            <div className="px-1.5 pb-2 text-[11px] font-bold uppercase tracking-[0.08em] text-muted">Most used</div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {FEATURED.map((f) => (
                <Link key={f.href} role="menuitem" href={f.href} onClick={() => setOpen(false)}
                  className="group rounded-xl border border-line bg-surface p-3 text-ink no-underline shadow-card outline-none transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-[0_8px_20px_-8px_rgba(15,118,110,0.35)] focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/25">
                  <span className="grid size-9 place-items-center rounded-[10px] bg-brand-tint text-brand transition-colors group-hover:bg-brand group-hover:text-white group-focus-visible:bg-brand group-focus-visible:text-white">
                    <Icon name={f.icon} />
                  </span>
                  <span className="mt-2 block text-[13.5px] font-semibold">{f.label}</span>
                  <span className="block text-[12px] leading-snug text-muted">{f.note}</span>
                </Link>
              ))}
            </div>
          </div>
          <div className="grid grid-cols-1 gap-x-2 p-3 sm:grid-cols-3">
            {GROUPS.map((g) => (
              <div key={g} className="py-1">
                <div className="flex items-center gap-1.5 px-2.5 pb-1.5 text-[11px] font-bold uppercase tracking-[0.08em] text-muted">
                  <Icon name={GROUP_ICON[g as keyof typeof GROUP_ICON] ?? "books"} className="size-3.5" />{g}
                </div>
                {CREATE.filter((c) => c.group === g && !FEATURED_HREFS.has(c.href)).map((c) => (
                  <Link key={c.href} role="menuitem" href={c.href} onClick={() => setOpen(false)}
                    className="group flex items-center justify-between rounded-lg px-2.5 py-2 text-sm font-medium text-ink no-underline outline-none hover:bg-brand-tint hover:text-brand-dark focus-visible:bg-brand-tint focus-visible:text-brand-dark focus-visible:outline-none">
                    {c.label}
                    <Icon name="plus" className="size-3.5 text-faint opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100" />
                  </Link>
                ))}
              </div>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-line-soft bg-canvas px-4 py-2.5 text-[12px] text-muted">
            <span><kbd className="rounded border border-line bg-surface px-1 font-sans font-semibold">N</kbd> opens this from anywhere</span>
            <span><kbd className="rounded border border-line bg-surface px-1 font-sans font-semibold">↑ ↓</kbd> to move</span>
            <span className="ml-auto"><kbd className="rounded border border-line bg-surface px-1 font-sans font-semibold">Ctrl K</kbd> to search everything</span>
          </div>
        </div>
      )}
    </div>
  );
}
