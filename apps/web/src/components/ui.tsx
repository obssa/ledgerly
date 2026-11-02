import type { ReactNode } from "react";

export function Card({ children, className = "", label }: { children: ReactNode; className?: string; label?: string }) {
  return (
    <section aria-label={label} className={`rounded-[14px] border border-line bg-surface p-5 ${className}`}>
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

export function Pill({ tone = "neutral", children }: { tone?: keyof typeof pillTones; children: ReactNode }) {
  return <span className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-bold ${pillTones[tone]}`}>{children}</span>;
}

export function PageTitle({ eyebrow, title, actions }: { eyebrow?: ReactNode; title: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-5 mt-7 flex flex-wrap items-end justify-between gap-3">
      <div>
        {eyebrow && <div className="text-[13px] text-muted">{eyebrow}</div>}
        <h1 className="mt-1.5 text-[28px] font-extrabold tracking-tight">{title}</h1>
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  );
}
