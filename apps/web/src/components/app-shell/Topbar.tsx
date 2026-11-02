import Link from "next/link";

export function Topbar() {
  return (
    <header className="flex flex-wrap items-center justify-between gap-3">
      <div className="max-w-[520px] flex-[1_1_320px]">
        <label htmlFor="q" className="sr-only">Search</label>
        <input
          id="q"
          type="search"
          placeholder="Search invoices, customers, amounts…"
          className="h-[42px] w-full rounded-[10px] border border-[#d5dcd8] bg-surface px-3.5 text-sm font-medium outline-none focus:border-brand"
        />
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <button className="btn" aria-label="Switch calendar">GC · EC</button>
        <button className="btn" aria-label="Language">EN</button>
        <Link href="/invoices/new" className="btn btn-primary">+ New invoice</Link>
        <div aria-label="Hana Bekele, owner" className="grid size-10 place-items-center rounded-full bg-brand text-sm font-bold text-white">HB</div>
      </div>
    </header>
  );
}
