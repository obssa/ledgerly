import type { ReactNode } from "react";
import Link from "next/link";

export function Card({ children, className = "", label }: { children: ReactNode; className?: string; label?: string }) {
  return (
    <section aria-label={label} className={`rounded-[14px] border border-line bg-surface p-5 shadow-card ${className}`}>
      {children}
    </section>
  );
}

const pillTones = {
  neutral: "bg-line-soft text-[#33433d]",
  good: "bg-good-tint text-good",
  warn: "bg-warn-tint text-warn",
  bad: "bg-bad-tint text-bad",
  brand: "bg-brand-tint text-[#0f5f58]",
} as const;

const pillDots = { neutral: "bg-[#8a9a93]", good: "bg-good", warn: "bg-[#d97706]", bad: "bg-bad", brand: "bg-brand" } as const;

/** A status. The dot repeats the tone so it never rests on colour alone: the words say it. */
export function Pill({ tone = "neutral", children }: { tone?: keyof typeof pillTones; children: ReactNode }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ${pillTones[tone]}`}>
      <span aria-hidden="true" className={`size-1.5 rounded-full ${pillDots[tone]}`} />
      {children}
    </span>
  );
}

export type SegmentedItem = { href: string; label: ReactNode; active: boolean; count?: ReactNode; key?: string };

/**
 * Segmented tabs: views of the same page, or pages of one place, as a tray of button-like pills. The open one is
 * raised (and marked aria-current). Plain links, so they work without JavaScript and keep the address shareable.
 */
export function Segmented({ items, label, size = "md", className = "" }: { items: SegmentedItem[]; label: string; size?: "sm" | "md"; className?: string }) {
  return (
    <nav aria-label={label} className={`mb-4 max-w-full overflow-x-auto print:hidden ${className}`}>
      <div className={`seg ${size === "sm" ? "seg-sm" : ""}`}>
        {items.map((i) => (
          <Link key={i.key ?? i.href} href={i.href} aria-current={i.active ? "page" : undefined} className={`seg-item ${i.active ? "seg-item-active" : ""}`}>
            {i.label}
            {i.count !== undefined && <span className="seg-count">{i.count}</span>}
          </Link>
        ))}
      </div>
    </nav>
  );
}

export function PageTitle({ eyebrow, title, actions, description }: { eyebrow?: ReactNode; title: ReactNode; actions?: ReactNode; description?: ReactNode }) {
  return (
    <div className="mb-5 mt-2 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
      <div className="min-w-0">
        {eyebrow && <div className="text-[13px] font-medium text-muted">{eyebrow}</div>}
        <h1 className="mt-1 text-[26px] font-bold leading-tight tracking-[-0.02em]">{title}</h1>
        {description && <p className="mt-1.5 max-w-[68ch] text-sm text-muted">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  );
}

/** A key figure: what it is, the amount, and how it moved, with the direction in words as well as colour. */
export function Stat({ label, value, note, tone, icon, href }: { label: string; value: string; note?: ReactNode; tone?: "good" | "bad"; icon?: ReactNode; href?: string }) {
  const body = (
    <>
      <div className="flex items-start justify-between gap-3">
        <div className="text-[13px] font-medium text-muted">{label}</div>
        {icon && <span aria-hidden="true" className="grid size-9 place-items-center rounded-[10px] bg-brand-tint text-brand">{icon}</span>}
      </div>
      <div className="figure mt-1 text-[26px] font-bold leading-tight text-ink">{value}</div>
      {note && <div className={`mt-2 text-[13px] font-medium ${tone === "good" ? "text-good" : tone === "bad" ? "text-bad" : "text-muted"}`}>{note}</div>}
    </>
  );
  const cls = "block rounded-[14px] border border-line bg-surface p-5 text-ink no-underline shadow-card";
  return href ? <Link href={href} className={`${cls} transition-colors hover:border-[#c5cec9]`}>{body}</Link> : <section aria-label={label} className={cls}>{body}</section>;
}
