/** The staff console (SECURITY.md S8): Ledgerly's own support staff, apart from customers' pages. */
export default function StaffLayout({ children }: LayoutProps<"/staff">) {
  return (
    <div className="min-h-screen bg-[#f4f5f7] px-4 py-8">
      <div className="mx-auto w-full max-w-[1000px]">
        <p className="mb-4 text-[13px] font-bold uppercase tracking-wide text-muted">Ledgerly staff console · read-only support</p>
        <main className="rounded-[14px] border border-line bg-surface p-6">{children}</main>
      </div>
    </div>
  );
}
