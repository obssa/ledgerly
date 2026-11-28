"use client";

import { Fragment, Suspense, type ReactNode } from "react";
import Link from "@/components/Link";
import { usePathname } from "next/navigation";
import { locate } from "@/lib/navigation";

/** The same mark as between a page's own steps (›), so the trail reads as one line. */
const Sep = () => <span aria-hidden="true" className="shrink-0">›</span>;

/**
 * The page's one breadcrumb, under the section's tabs and over its title: where it sits in the menu (Sales › Invoices,
 * the page a link when this is deeper), then the page's own last steps (a document's number, Edit). Not printed.
 */
export function PageCrumbs({ trail }: { trail?: ReactNode | ReactNode[] }) {
  return (
    <Suspense fallback={<div className="h-5" />}>
      <Crumbs trail={trail} />
    </Suspense>
  );
}

function Crumbs({ trail }: { trail?: ReactNode | ReactNode[] }) {
  const pathname = usePathname();
  const here = locate(pathname);
  const steps = (Array.isArray(trail) ? trail : trail === undefined || trail === null || trail === false ? [] : [trail]).filter((s) => s !== null && s !== undefined && s !== false && s !== "");
  const onMenu = here && here.section.id !== "home";
  if (!onMenu && steps.length === 0) return null;
  const deeper = onMenu && (pathname !== here.item.href || steps.length > 0);
  return (
    <nav aria-label="Breadcrumb" className="flex min-w-0 flex-wrap items-center gap-1.5 text-[13px] font-medium text-muted print:hidden">
      {onMenu && (
        <>
          <span className="truncate">{here.section.label}</span>
          <Sep />
          {deeper
            ? <Link href={here.item.href} className="truncate text-muted no-underline hover:text-ink hover:underline">{here.item.label}</Link>
            : <span className="truncate text-ink" aria-current="page">{here.item.label}</span>}
        </>
      )}
      {steps.map((s, i) => (
        <Fragment key={i}>
          {(onMenu || i > 0) && <Sep />}
          <span className={`min-w-0 ${i === steps.length - 1 ? "text-ink" : ""}`} aria-current={i === steps.length - 1 ? "page" : undefined}>{s}</span>
        </Fragment>
      ))}
    </nav>
  );
}
