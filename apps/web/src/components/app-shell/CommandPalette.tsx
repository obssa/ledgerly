"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { SEARCH_INDEX, SECTIONS, searchEntries, type SearchEntry } from "@/lib/navigation";
import { Icon } from "./Icon";

/** Searches that open a list already filtered, for what is typed (each list page reads ?q= or ?search=). */
const FINDERS: { label: string; href: (q: string) => string }[] = [
  { label: "Find customers", href: (q) => `/customers?q=${encodeURIComponent(q)}` },
  { label: "Find suppliers", href: (q) => `/suppliers?q=${encodeURIComponent(q)}` },
  { label: "Find products", href: (q) => `/products?q=${encodeURIComponent(q)}` },
  { label: "Search the journal", href: (q) => `/journal?search=${encodeURIComponent(q)}` },
];

const SUGGESTED = ["/invoices/new", "/bills/new", "/payments/new", "/banking", "/reports", "/reports/trial-balance"];

type Row = { key: string; href: string; label: string; context: string; kind: SearchEntry["kind"] | "find" };

/**
 * Ctrl+K (or ⌘K) from anywhere: jump to any page, start any form, or open a list filtered by a name. Keyboard first:
 * arrows to move, Enter to open, Esc to close.
 */
export function CommandPalette() {
  const router = useRouter();
  const dialog = useRef<HTMLDialogElement>(null);
  const input = useRef<HTMLInputElement>(null);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);

  const rows: Row[] = useMemo(() => {
    const q = query.trim();
    if (!q) {
      const all = searchEntriesForHrefs(SUGGESTED);
      return all.map((e) => ({ key: e.href, href: e.href, label: e.label, context: e.context, kind: e.kind }));
    }
    const found = searchEntries(q, 10).map((e) => ({ key: `${e.kind}:${e.href}`, href: e.href, label: e.label, context: e.context, kind: e.kind }));
    const finders = FINDERS.map((f) => ({ key: f.label, href: f.href(q), label: `${f.label} matching “${q}”`, context: "Search", kind: "find" as const }));
    return [...found, ...finders];
  }, [query]);

  const open = () => {
    setQuery("");
    setActive(0);
    dialog.current?.showModal();
    requestAnimationFrame(() => input.current?.focus());
  };
  const close = () => dialog.current?.close();
  const go = (row: Row | undefined) => {
    if (!row) return;
    close();
    router.push(row.href);
  };

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        if (dialog.current?.open) close();
        else open();
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  const onInputKey = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "ArrowDown") { e.preventDefault(); setActive((a) => Math.min(a + 1, rows.length - 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); }
    else if (e.key === "Enter") { e.preventDefault(); go(rows[active]); }
  };

  return (
    <>
      <button type="button" onClick={open} aria-keyshortcuts="Control+K Meta+K"
        className="flex h-10 w-full items-center gap-2.5 rounded-[10px] border border-line bg-surface px-3 text-left text-sm text-muted shadow-[0_1px_2px_rgba(14,26,23,0.04)] hover:border-[#c5cec9]">
        <Icon name="search" className="size-4" />
        <span className="flex-1 truncate">Search or jump to…</span>
        <kbd className="hidden rounded-md border border-line bg-canvas px-1.5 py-0.5 font-sans text-[11px] font-semibold text-muted sm:inline">Ctrl K</kbd>
      </button>

      <dialog ref={dialog} aria-label="Search Evenbooks" onClick={(e) => e.target === dialog.current && close()}
        className="m-auto mt-[12vh] w-[min(640px,calc(100vw-32px))] overflow-hidden rounded-2xl border border-line bg-surface p-0 text-ink shadow-[0_24px_64px_rgba(12,36,32,0.28)] backdrop:bg-[#0c2420]/40 backdrop:backdrop-blur-[2px]">
        <div className="flex items-center gap-3 border-b border-line px-4">
          <Icon name="search" className="size-[18px] text-muted" />
          <input ref={input} value={query} onChange={(e) => { setQuery(e.target.value); setActive(0); }} onKeyDown={onInputKey}
            role="combobox" aria-expanded="true" aria-controls="palette-results" aria-activedescendant={rows[active] ? `palette-${active}` : undefined}
            placeholder="Pages, forms, a customer's name…" className="h-14 flex-1 bg-transparent text-[15px] font-medium outline-none placeholder:text-muted focus-visible:outline-none" />
          <kbd className="rounded-md border border-line bg-canvas px-1.5 py-0.5 text-[11px] font-semibold text-muted">Esc</kbd>
        </div>
        <ul id="palette-results" role="listbox" aria-label="Results" className="max-h-[52vh] overflow-y-auto p-2">
          {!query.trim() && <li role="presentation" className="px-3 pb-1 pt-2 text-[11px] font-bold uppercase tracking-[0.08em] text-muted">Suggested</li>}
          {rows.map((row, i) => (
            <li key={row.key} id={`palette-${i}`} role="option" aria-selected={i === active}
              onMouseMove={() => setActive(i)} onClick={() => go(row)}
              className={`flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5 text-sm ${i === active ? "bg-brand-tint" : ""}`}>
              <span className={`grid size-8 place-items-center rounded-lg ${row.kind === "create" ? "bg-brand text-white" : "bg-canvas text-muted"}`}>
                <Icon name={row.kind === "create" ? "plus" : row.kind === "find" ? "search" : iconFor(row.context)} className="size-4" />
              </span>
              <span className="flex-1 font-semibold">{row.label}</span>
              <span className="text-xs text-muted">{row.context}</span>
              {i === active && <Icon name="arrowRight" className="size-4 text-brand" />}
            </li>
          ))}
        </ul>
        <div className="flex gap-4 border-t border-line bg-canvas px-4 py-2.5 text-[12px] text-muted">
          <span><kbd className="font-sans font-semibold">↑ ↓</kbd> to move</span>
          <span><kbd className="font-sans font-semibold">Enter</kbd> to open</span>
          <span className="ml-auto">Ctrl K from anywhere</span>
        </div>
      </dialog>
    </>
  );
}

function iconFor(sectionLabel: string) {
  return SECTIONS.find((s) => s.label === sectionLabel)?.icon ?? "settings";
}

function searchEntriesForHrefs(hrefs: string[]): SearchEntry[] {
  return hrefs.map((href) => SEARCH_INDEX.find((e) => e.href === href)).filter((e): e is SearchEntry => Boolean(e));
}
