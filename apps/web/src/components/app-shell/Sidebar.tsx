"use client";

import { Suspense, type ReactNode } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";

type NavItem = { href: string; label: string; soon?: boolean };

const groups: { label?: string; items: NavItem[] }[] = [
  { items: [{ href: "/", label: "Home" }] },
  {
    label: "Money in",
    items: [
      { href: "/invoices", label: "Sales and invoices" },
      { href: "/customers", label: "Customers" },
      { href: "/payments", label: "Payments received" },
      { href: "/products", label: "Products and services" },
    ],
  },
  {
    label: "Money out",
    items: [
      { href: "/bills", label: "Bills and purchases" },
      { href: "/suppliers", label: "Suppliers" },
      { href: "/payments-made", label: "Payments made" },
      { href: "/expenses", label: "Expenses" },
    ],
  },
  {
    label: "Operations",
    items: [
      { href: "/banking", label: "Banking and cash" },
      { href: "/inventory", label: "Inventory and bin cards" },
      { href: "/payroll", label: "Payroll", soon: true },
    ],
  },
  { label: "Compliance", items: [{ href: "/tax", label: "Tax centre", soon: true }, { href: "/einvoicing", label: "E-invoicing", soon: true }] },
  {
    label: "Books",
    items: [
      { href: "/accounts", label: "Chart of accounts" },
      { href: "/journal", label: "Journal entries" },
      { href: "/revaluation", label: "Currency revaluation" },
      { href: "/close", label: "Close the books" },
      { href: "/opening-balances", label: "Opening balances" },
      { href: "/reports", label: "Reports" },
    ],
  },
];

const footer: NavItem[] = [
  { href: "/settings", label: "Company settings" },
  { href: "/security", label: "Sign-in security" },
];

/** `company` is rendered on the server (CompanyBadge) and passed in, since this component runs in the browser. */
export function Sidebar({ company }: { company: ReactNode }) {
  return (
    <nav aria-label="Main" className="flex-[1_1_240px] max-w-full bg-side px-3.5 py-5 text-side-text lg:max-w-[260px]">
      <div className="flex items-center gap-2.5 px-3 pb-5">
        <svg width="28" height="28" viewBox="0 0 28 28" aria-hidden="true">
          <rect width="28" height="28" rx="8" fill="#34D399" />
          <path d="M8 19V9M8 19h12M13 15l3-4 4 3" stroke="#0C2420" strokeWidth="2.2" fill="none" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
        <span className="text-[19px] font-extrabold tracking-tight text-white">Ledgerly</span>
      </div>

      {company}

      {/* On pages like /customers/[id] the path is known only per request: the menu shows without the highlight
          until then, so the page itself can still be prerendered. */}
      <Suspense fallback={<Links pathname={null} />}>
        <CurrentLinks />
      </Suspense>
    </nav>
  );
}

function CurrentLinks() {
  return <Links pathname={usePathname()} />;
}

function Links({ pathname }: { pathname: string | null }) {
  const isActive = (href: string) => pathname !== null && (href === "/" ? pathname === "/" : pathname.startsWith(href));
  const cls = (href: string) =>
    `flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm font-medium hover:bg-side-hover hover:text-white ${
      isActive(href) ? "bg-side-hover font-bold text-white" : ""
    }`;

  return (
    <>
      {groups.map((group, i) => (
        <div key={i} className={i === 0 ? "mt-3" : ""}>
          {group.label && <div className="px-3 pb-1.5 pt-[18px] text-[11px] font-bold uppercase tracking-[0.08em] text-side-label">{group.label}</div>}
          {group.items.map((item) => (
            <Link
              key={item.href}
              href={item.soon ? "#" : item.href}
              aria-current={isActive(item.href) ? "page" : undefined}
              aria-disabled={item.soon || undefined}
              className={cls(item.href)}
            >
              {item.label}
              {item.soon && <span className="text-[10px] font-semibold uppercase tracking-wide text-side-label">soon</span>}
            </Link>
          ))}
        </div>
      ))}

      <div className="mt-[18px] border-t border-[#23493f] pt-3">
        {footer.map((item) => (
          <Link key={item.href} href={item.href} aria-current={isActive(item.href) ? "page" : undefined} className={cls(item.href)}>
            {item.label}
          </Link>
        ))}
      </div>
    </>
  );
}
