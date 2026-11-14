/** Signed-out pages: sign in and create an account. */
export default function AuthLayout({ children }: LayoutProps<"/">) {
  return (
    <div className="grid min-h-screen place-items-center bg-[#f4f7f5] px-4 py-10">
      <div className="w-full max-w-[420px]">
        <div className="mb-6 flex items-center justify-center gap-2.5">
          <svg width="32" height="32" viewBox="0 0 28 28" aria-hidden="true">
            <rect width="28" height="28" rx="8" fill="#34D399" />
            <path d="M8 19V9M8 19h12M13 15l3-4 4 3" stroke="#0C2420" strokeWidth="2.2" fill="none" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
          <span className="text-[22px] font-extrabold tracking-tight">Ledgerly</span>
        </div>
        <main className="rounded-[14px] border border-line bg-surface p-7">{children}</main>
      </div>
    </div>
  );
}
