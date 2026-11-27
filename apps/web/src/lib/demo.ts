import type { CompanySettings, Currencies, Customer, Dimension, Invoice, Numbering, ProfitAndLoss } from "./api";

/** Sample data for demo mode (no LEDGERLY_API_URL). Fictional company and customers. */

export const demoCompany = {
  id: "demo",
  legal_name: "Abyssinia Coffee Traders PLC",
  trading_name: "Abyssinia Coffee",
  country_code: "ET",
  base_currency: "ETB",
  city: "Addis Ababa",
};

/** Company settings as GET /company returns them, for the demo company. */
export const demoCompanySettings: CompanySettings = {
  ...demoCompany,
  version: "demo",
  framework: "ifrs_sme",
  status: "live",
  legal_form: "private_limited",
  industry: "retail_wholesale",
  tin: "0012345678",
  vat_registered: true,
  vat_number: "1234567",
  phone: "+251 11 555 0100",
  email: "accounts@example.com",
  website: null,
  address_line1: "Bole Road",
  address_line2: null,
  region: "Addis Ababa",
  postal_code: null,
  timezone: "Africa/Addis_Ababa",
  accounting_basis: "accrual",
  calendar: "gregorian",
  date_format: "DD/MM/YYYY",
  fiscal_year_start_month: 7,
  conversion_date: "2026-07-07",
  books_closed_until: "2026-09-30",
  books_started: true,
  locked_fields: ["base_currency", "calendar", "fiscal_year_start_month", "conversion_date"],
  fiscal_years: [{ starts_on: "2026-07-08", ends_on: "2027-07-07", status: "open" }],
  can: { manage: false, close_periods: false, reopen_periods: false, enter_rates: false, manage_dimensions: false },
  options: { tax_id_label: "TIN", calendars: ["gregorian", "ethiopian"], legal_forms: ["sole_proprietor", "private_limited", "share_company"] },
};

export const demoCurrencies: Currencies = {
  base_currency: "ETB",
  data: [{ currency: "USD", is_enabled: true, latest_rate: "134.523333", latest_rate_date: "2026-11-02" }],
};

export const demoDimensions: Dimension[] = [
  { id: "d1", kind: "location", code: "HQ", name: "Head office, Bole", is_active: true },
  { id: "d2", kind: "location", code: "MRK", name: "Merkato shop", is_active: true },
  { id: "d3", kind: "class", code: "WHOLESALE", name: "Wholesale", is_active: true },
];

export const demoCustomers: Customer[] = [
  { id: "c1", name: "Sheger Grand Hotel", tax_id: "0012345678", email: "ap@sheger.example", phone: "+251 11 555 0101", currency: null, payment_terms_days: 30, credit_limit: "600000.00", is_active: true, open_balance: "598145.00" },
  { id: "c2", name: "Bole Brew Café", tax_id: null, email: null, phone: "+251 91 100 2000", currency: null, payment_terms_days: 15, credit_limit: null, is_active: true, open_balance: "58250.00" },
  { id: "c3", name: "Rift Valley Roasters Ltd", tax_id: null, email: "accounts@rvr.example", phone: null, currency: "USD", payment_terms_days: 45, credit_limit: null, is_active: true, open_balance: "166808.93" },
];

export const demoNumbering: Numbering[] = [
  { doc_type: "invoice", mode: "automatic", prefix: "INV-", include_year: true, padding: 4, next_number: "INV-2019-0420" },
  { doc_type: "credit_note", mode: "automatic", prefix: "CN-", include_year: true, padding: 4, next_number: "CN-2019-0012" },
];

const c = (id: string, name: string, tin: string | null = null) => ({ id, name, tax_id: tin });

const invoices: Invoice[] = [
  { id: "i419", number: null, status: "draft", contact: c("c1", "Sheger Grand Hotel", "0012345678"), issue_date: "2026-11-02", due_date: "2026-12-02", currency: "ETB", subtotal: "436300.00", tax_total: "65445.00", total: "501745.00", amount_due: "501745.00" },
  { id: "i412", number: "INV-2019-0412", status: "posted", contact: c("c5", "Lalibela Lodge Group"), issue_date: "2026-10-28", due_date: "2026-11-27", currency: "ETB", subtotal: "72000.00", tax_total: "10800.00", total: "82800.00", amount_due: "82800.00", einvoice_submissions: [{ authority: "ET_MOR", status: "rejected", clearance_number: null, qr_payload: null, last_error: "Buyer TIN not found" }] },
  { id: "i406", number: "INV-2019-0406", status: "posted", contact: c("c4", "Harar Hills Café"), issue_date: "2026-09-24", due_date: "2026-10-24", currency: "ETB", subtotal: "36347.83", tax_total: "5452.17", total: "41800.00", amount_due: "41800.00" },
  { id: "i401", number: "INV-2019-0401", status: "posted", contact: c("c3", "Rift Valley Roasters Ltd"), issue_date: "2026-09-15", due_date: "2026-10-15", currency: "USD", subtotal: "1240.00", tax_total: "0.00", total: "1240.00", amount_due: "1240.00" },
  { id: "i395", number: "INV-2019-0395", status: "posted", contact: c("c2", "Bole Brew Café"), issue_date: "2026-09-02", due_date: "2026-10-02", currency: "ETB", subtotal: "50652.17", tax_total: "7597.83", total: "58250.00", amount_due: "58250.00" },
  { id: "i388", number: "INV-2019-0388", status: "posted", contact: c("c1", "Sheger Grand Hotel", "0012345678"), issue_date: "2026-08-17", due_date: "2026-09-16", currency: "ETB", subtotal: "83826.09", tax_total: "12573.91", total: "96400.00", amount_due: "96400.00" },
];

/** The demo company is in Ethiopia, where every posted invoice is registered with the Ministry of Revenue. */
export const demoInvoices: Invoice[] = invoices.map((inv, n) =>
  inv.status === "draft" || inv.einvoice_submissions?.length
    ? inv
    : { ...inv, einvoice_submissions: [{ authority: "ET_MOR", status: "cleared", clearance_number: `IRN-2019-${String(4100 + n)}`, qr_payload: null, last_error: null }] },
);

export const demoKpis = {
  cash: "2486320.00",
  receivable: "914500.00",
  overdue: "286900.00",
  overdueCount: 12,
  payable: "388140.00",
  billsDueThisWeek: 6,
  netProfit: "412880.00",
  vatDue: "142615.00",
  vatDueDays: 28,
  einvoices: { cleared: 318, waiting: 2, rejected: 1 },
};

export const demoAccounts = [
  { name: "CBE current ··4417", note: "Synced 12 min ago · 8 to review", balance: "1604210.00" },
  { name: "Awash savings ··0932", note: "Statement uploaded 1 Nov", balance: "512000.00" },
  { name: "Telebirr merchant", note: "Live · 5 to review", balance: "327610.00" },
  { name: "Petty cash · Bole shop", note: "Counted 31 Oct", balance: "42500.00" },
];

const r = (code: string, name: string, current: string, previous: string) => ({ code, name, current, previous });

export const demoProfitAndLoss: ProfitAndLoss = {
  sections: {
    income: [
      r("4000", "Sales · roasted coffee", "1402600.00", "1361330.00"),
      r("4010", "Sales · green coffee export", "386200.00", "355600.00"),
      r("4100", "Delivery income", "21400.00", "19800.00"),
    ],
    cost_of_sales: [
      r("5000", "Green coffee purchases", "742300.00", "701900.00"),
      r("5010", "Roasting and packaging", "118500.00", "112200.00"),
    ],
    expenses: [
      r("6000", "Salaries and wages", "286300.00", "286300.00"),
      r("6010", "Pension · employer", "31500.00", "31500.00"),
      r("6100", "Rent", "120000.00", "120000.00"),
      r("6200", "Utilities", "18640.00", "17920.00"),
      r("6300", "Telephone and internet", "9850.00", "9850.00"),
      r("6400", "Vehicle and fuel", "22400.00", "19600.00"),
      r("6500", "Marketing", "15000.00", "22000.00"),
      r("6600", "Bank charges and fees", "6230.00", "5980.00"),
      r("6700", "Depreciation", "24600.00", "24600.00"),
      r("4950", "Foreign exchange (gain)", "-6000.00", "-4000.00"),
      r("7000", "Interest expense", "8000.00", "8000.00"),
    ],
  },
  totals: {
    income: { current: "1810200.00", previous: "1736730.00" },
    cost_of_sales: { current: "860800.00", previous: "814100.00" },
    gross_profit: { current: "949400.00", previous: "922630.00" },
    expenses: { current: "536520.00", previous: "541750.00" },
    net_profit: { current: "412880.00", previous: "380880.00" },
  },
};

export const demoTaxRates = [
  { id: "vat15", code: "VAT15", name: "VAT 15%", rate: "0.15" },
  { id: "vat0", code: "VAT0", name: "VAT zero-rated", rate: "0" },
  { id: "exempt", code: "EXEMPT", name: "VAT exempt", rate: "0" },
];
