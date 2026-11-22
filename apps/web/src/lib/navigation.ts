/**
 * Where everything is: the app's sections, in the order an accountant works through the cycle (sell, buy, move cash,
 * keep stock, keep the books, file tax, report). The sidebar, the tabs at the top of each page, the breadcrumb, the
 * quick-create menu and the Ctrl+K search all read this one list, so a page added here appears in all of them.
 * Every href is a page that exists (navigation.test.ts checks).
 */

export type IconName =
  | "home" | "sales" | "purchases" | "bank" | "inventory" | "projects" | "books" | "tax" | "reports" | "settings" | "payroll";

export type NavItem = {
  href: string;
  label: string;
  /** Other words people search for. */
  keywords?: string;
  soon?: boolean;
  /** Shown in the search and the sidebar, not as a tab at the top of the section. */
  tabHidden?: boolean;
};

export type NavSection = { id: string; label: string; icon: IconName; hint: string; items: NavItem[] };

export const SECTIONS: NavSection[] = [
  {
    id: "home", label: "Overview", icon: "home", hint: "Today's position",
    items: [{ href: "/", label: "Overview", keywords: "home dashboard kpi" }],
  },
  {
    id: "sales", label: "Sales", icon: "sales", hint: "Quote to cash",
    items: [
      { href: "/customers", label: "Customers", keywords: "clients debtors receivable statement" },
      { href: "/quotes", label: "Quotes", keywords: "estimates proforma" },
      { href: "/sales-orders", label: "Sales orders", keywords: "orders back orders" },
      { href: "/delivery-notes", label: "Delivery notes", keywords: "dispatch shipped goods out" },
      { href: "/invoices", label: "Invoices", keywords: "sales bills to customers ar" },
      { href: "/invoices/recurring", label: "Recurring invoices", keywords: "subscription repeat schedule" },
      { href: "/sales-receipts", label: "Cash sales", keywords: "sales receipts pos till" },
      { href: "/payments", label: "Payments received", keywords: "receipts collections receive money" },
      { href: "/credit-notes", label: "Credit notes", keywords: "returns credit memo" },
      { href: "/refunds", label: "Refunds", keywords: "money back customer" },
    ],
  },
  {
    id: "purchases", label: "Purchases", icon: "purchases", hint: "Order to pay",
    items: [
      { href: "/suppliers", label: "Suppliers", keywords: "vendors creditors payable" },
      { href: "/purchase-requisitions", label: "Requisitions", keywords: "purchase requests" },
      { href: "/purchase-orders", label: "Purchase orders", keywords: "po lpo" },
      { href: "/goods-received", label: "Goods received", keywords: "grn receiving" },
      { href: "/bills", label: "Bills", keywords: "supplier invoices ap purchases" },
      { href: "/bills/credits", label: "Supplier credits", keywords: "debit notes returns" },
      { href: "/payments-made", label: "Payments made", keywords: "pay suppliers disbursements" },
      { href: "/payments-made/batch", label: "Pay many bills", keywords: "batch payment run" },
      { href: "/payments-made/bank-file", label: "Bank payment file", keywords: "csv transfer upload", tabHidden: true },
      { href: "/expenses", label: "Expenses", keywords: "spend direct costs receipts" },
      { href: "/expense-claims", label: "Expense claims", keywords: "staff reimbursements" },
    ],
  },
  {
    id: "banking", label: "Banking", icon: "bank", hint: "Cash, wallets, reconciliation",
    items: [
      { href: "/banking", label: "Bank and cash", keywords: "accounts wallets telebirr mpesa petty cash reconcile statements transfers" },
      { href: "/banking/rules", label: "Bank rules", keywords: "categorise automatic" },
      { href: "/banking/post-dated", label: "Post-dated cheques", keywords: "cheque pdc" },
    ],
  },
  {
    id: "inventory", label: "Inventory", icon: "inventory", hint: "Products and stock",
    items: [
      { href: "/products", label: "Products and services", keywords: "items sku catalogue" },
      { href: "/products/price-lists", label: "Price lists", keywords: "pricing tiers" },
      { href: "/inventory", label: "Stock on hand", keywords: "warehouses quantities reorder" },
      { href: "/inventory/bins", label: "Bins", keywords: "shelves locations" },
      { href: "/inventory/count", label: "Stock count", keywords: "stocktake physical count" },
      { href: "/inventory/batches", label: "Batches and expiry", keywords: "lots serial expiry" },
      { href: "/inventory/bin-card", label: "Bin card", keywords: "stock card movements" },
    ],
  },
  {
    id: "projects", label: "Projects", icon: "projects", hint: "Jobs, grants and time",
    items: [
      { href: "/projects", label: "Projects", keywords: "jobs grants donors" },
      { href: "/time", label: "Timesheets", keywords: "hours billable time" },
    ],
  },
  {
    id: "books", label: "Accounting", icon: "books", hint: "Ledger, assets, close",
    items: [
      { href: "/accounts", label: "Chart of accounts", keywords: "coa gl codes" },
      { href: "/journal", label: "Journal entries", keywords: "manual journals gl" },
      { href: "/journal/recurring", label: "Recurring journals", keywords: "accruals prepayments schedule" },
      { href: "/journal/review", label: "Review queue", keywords: "approval check review" },
      { href: "/fixed-assets", label: "Fixed assets", keywords: "depreciation register ppe" },
      { href: "/leases", label: "Leases", keywords: "right of use ifrs" },
      { href: "/revaluation", label: "Revaluation", keywords: "fx foreign exchange currency" },
      { href: "/opening-balances", label: "Opening balances", keywords: "conversion start" },
      { href: "/close", label: "Close the books", keywords: "period end year end lock checklist" },
      { href: "/tags", label: "Tags", keywords: "labels analysis" },
    ],
  },
  {
    id: "tax", label: "Tax", icon: "tax", hint: "VAT, withholding, filing",
    items: [
      { href: "/tax", label: "Tax centre", keywords: "vat return calendar erca mor" },
      { href: "/tax?kind=withholding", label: "Withholding", keywords: "wht withholding tax register" },
      { href: "/fixed-assets/tax", label: "Tax depreciation", keywords: "capital allowances" },
      { href: "/einvoicing", label: "E-invoicing", keywords: "einvoice mor", soon: true },
    ],
  },
  {
    id: "reports", label: "Reports", icon: "reports", hint: "Statements and analysis",
    items: [
      { href: "/reports", label: "Profit and loss", keywords: "income statement p&l pnl" },
      { href: "/reports/balance-sheet", label: "Balance sheet", keywords: "financial position" },
      { href: "/reports/cash-flow", label: "Cash flow", keywords: "cash flow statement" },
      { href: "/reports/trial-balance", label: "Trial balance", keywords: "tb" },
      { href: "/reports/general-ledger", label: "General ledger", keywords: "gl detail" },
      { href: "/reports/ar-ageing", label: "AR ageing", keywords: "receivables aged debtors" },
      { href: "/reports/ap-ageing", label: "AP ageing", keywords: "payables aged creditors" },
      { href: "/reports/cash-forecast", label: "Cash forecast", keywords: "13 week cash outlook" },
      { href: "/reports/financial-health", label: "Financial health", keywords: "ratios kpi" },
      { href: "/reports/changes", label: "Changes", keywords: "edits who changed what history corrections audit" },
      { href: "/reports/sales", label: "Sales analysis", keywords: "by customer by product", tabHidden: true },
      { href: "/reports/purchases", label: "Purchases analysis", keywords: "by supplier", tabHidden: true },
      { href: "/reports/changes-in-equity", label: "Changes in equity", keywords: "equity statement", tabHidden: true },
      { href: "/reports/notes-pack", label: "Statements and notes", keywords: "notes financial statements print", tabHidden: true },
      { href: "/reports/inventory-valuation", label: "Inventory valuation", keywords: "stock value", tabHidden: true },
      { href: "/reports/stock-movement", label: "Stock movement", keywords: "stock in out", tabHidden: true },
      { href: "/reports/stock-ageing", label: "Stock ageing", keywords: "slow moving", tabHidden: true },
      { href: "/reports/profit-by-dimension", label: "Profit by location or project", keywords: "class branch dimension", tabHidden: true },
      { href: "/reports/by-tag", label: "Profit by tag", keywords: "tags", tabHidden: true },
      { href: "/reports/custom", label: "Custom report", keywords: "build own", tabHidden: true },
      { href: "/reports/saved", label: "Saved reports", keywords: "scheduled email" },
    ],
  },
  {
    id: "payroll", label: "Payroll", icon: "payroll", hint: "Coming later",
    items: [{ href: "/payroll", label: "Payroll", keywords: "salaries pension", soon: true }],
  },
];

/** Company administration, at the foot of the sidebar. */
export const ADMIN: NavSection = {
  id: "admin", label: "Settings", icon: "settings", hint: "Company and team",
  items: [
    { href: "/settings", label: "Company settings", keywords: "profile logo currencies numbering period lock template" },
    { href: "/settings/team", label: "Team", keywords: "users roles invite permissions" },
    { href: "/import", label: "Import", keywords: "quickbooks sage tally csv migrate" },
    { href: "/audit", label: "Audit log", keywords: "history who changed" },
    { href: "/security", label: "Sign-in security", keywords: "authenticator 2fa mfa sessions password" },
  ],
};

export type CreateAction = { href: string; label: string; group: string; keywords?: string };

/** The quick-create menu and the "Create" results in the search. Each is a form page that exists. */
export const CREATE: CreateAction[] = [
  { group: "Sales", href: "/invoices/new", label: "Invoice", keywords: "bill customer" },
  { group: "Sales", href: "/quotes/new", label: "Quote", keywords: "estimate" },
  { group: "Sales", href: "/sales-orders/new", label: "Sales order" },
  { group: "Sales", href: "/sales-receipts/new", label: "Cash sale", keywords: "sales receipt" },
  { group: "Sales", href: "/payments/new", label: "Payment received", keywords: "receive money" },
  { group: "Sales", href: "/customers/new", label: "Customer", keywords: "client" },
  { group: "Purchases", href: "/bills/new", label: "Bill", keywords: "supplier invoice" },
  { group: "Purchases", href: "/expenses/new", label: "Expense", keywords: "spend receipt" },
  { group: "Purchases", href: "/payments-made/new", label: "Payment made", keywords: "pay supplier" },
  { group: "Purchases", href: "/purchase-orders/new", label: "Purchase order", keywords: "po" },
  { group: "Purchases", href: "/expense-claims/new", label: "Expense claim", keywords: "reimbursement" },
  { group: "Purchases", href: "/suppliers/new", label: "Supplier", keywords: "vendor" },
  { group: "Accounting", href: "/journal/new", label: "Journal entry", keywords: "manual journal" },
  { group: "Accounting", href: "/products/new", label: "Product or service", keywords: "item" },
  { group: "Accounting", href: "/fixed-assets/new", label: "Fixed asset" },
  { group: "Accounting", href: "/projects/new", label: "Project" },
];

const ALL: { section: NavSection; item: NavItem }[] = [...SECTIONS, ADMIN].flatMap((section) => section.items.map((item) => ({ section, item })));

/** Where a section's name in the menu leads: its first page that exists yet. */
export const firstPage = (section: NavSection): NavItem => section.items.find((i) => !i.soon) ?? section.items[0];

/** The path part of an href, without its query. */
export const pathOf = (href: string): string => href.split("?")[0];

/**
 * The section and page a path belongs to: the longest menu entry that the path starts with, so /invoices/123 is
 * under Invoices and /invoices/recurring is Recurring invoices. Entries with a query (Withholding) never claim a
 * path on their own. Pages outside the menu (/no-access) have no section.
 */
export function locate(pathname: string): { section: NavSection; item: NavItem } | null {
  let best: { section: NavSection; item: NavItem } | null = null;
  for (const entry of ALL) {
    const { href } = entry.item;
    if (href.includes("?") || entry.item.soon) continue;
    const match = href === "/" ? pathname === "/" : pathname === href || pathname.startsWith(`${href}/`);
    if (match && (!best || href.length > best.item.href.length)) best = entry;
  }
  return best;
}

/**
 * The entry that is open among `items`: one whose query matches the address exactly wins (Withholding on
 * /tax?kind=withholding); otherwise the page's place in the menu (locate).
 */
export function activeHref(items: NavItem[], pathname: string, search: string): string | null {
  const params = new URLSearchParams(search);
  const exact = items.find((i) => i.href.includes("?") && pathOf(i.href) === pathname
    && [...new URLSearchParams(i.href.split("?")[1])].every(([k, v]) => params.get(k) === v));
  return exact?.href ?? locate(pathname)?.item.href ?? null;
}

/** Everything the Ctrl+K search can open: every page, and every quick-create form. */
export type SearchEntry = { href: string; label: string; context: string; terms: string; kind: "page" | "create" };

export const SEARCH_INDEX: SearchEntry[] = [
  ...ALL.filter(({ item }) => !item.soon).map(({ section, item }) => ({
    href: item.href, label: item.label, context: section.label, kind: "page" as const,
    terms: `${item.label} ${section.label} ${item.keywords ?? ""}`.toLowerCase(),
  })),
  ...CREATE.map((c) => ({
    href: c.href, label: `New ${c.label.toLowerCase()}`, context: c.group, kind: "create" as const,
    terms: `new create add ${c.label} ${c.group} ${c.keywords ?? ""}`.toLowerCase(),
  })),
];

/**
 * The entries that match what was typed, best first: every word has to appear; a label that starts with the
 * query ranks above one that only mentions it.
 */
export function searchEntries(query: string, limit = 12): SearchEntry[] {
  const q = query.trim().toLowerCase();
  if (!q) return [];
  const words = q.split(/\s+/);
  return SEARCH_INDEX
    .filter((e) => words.every((w) => e.terms.includes(w)))
    .map((e) => {
      const label = e.label.toLowerCase();
      const score = (label.startsWith(q) ? 0 : label.includes(q) ? 1 : 2) + (e.kind === "create" && !words.some((w) => ["new", "create", "add"].includes(w)) ? 0.5 : 0);
      return { e, score };
    })
    .sort((a, b) => a.score - b.score || a.e.label.length - b.e.label.length)
    .slice(0, limit)
    .map(({ e }) => e);
}
