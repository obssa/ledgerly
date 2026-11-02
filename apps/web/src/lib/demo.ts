import type { Invoice, ProfitAndLoss } from "./api";

/** Sample data for demo mode (no LEDGERLY_API_URL). Fictional company and customers. */

export const demoCompany = {
  id: "demo",
  legal_name: "Abyssinia Coffee Traders PLC",
  trading_name: "Abyssinia Coffee",
  country_code: "ET",
  base_currency: "ETB",
  city: "Addis Ababa",
};

const c = (id: string, name: string, tin: string | null = null) => ({ id, name, tax_id: tin });

export const demoInvoices: Invoice[] = [
  { id: "i419", number: "INV-2019-0419", status: "draft", contact: c("c1", "Sheger Grand Hotel", "0012345678"), issue_date: "2026-11-02", due_date: "2026-12-02", currency: "ETB", subtotal: "436300.0000", tax_total: "65445.0000", total: "501745.0000", amount_due: "501745.0000" },
  { id: "i412", number: "INV-2019-0412", status: "posted", contact: c("c5", "Lalibela Lodge Group"), issue_date: "2026-10-28", due_date: "2026-11-27", currency: "ETB", subtotal: "72000.0000", tax_total: "10800.0000", total: "82800.0000", amount_due: "82800.0000", einvoice_submissions: [{ authority: "ET_MOR", status: "rejected", clearance_number: null, qr_payload: null, last_error: "Buyer TIN not found" }] },
  { id: "i406", number: "INV-2019-0406", status: "posted", contact: c("c4", "Harar Hills Café"), issue_date: "2026-09-24", due_date: "2026-10-24", currency: "ETB", subtotal: "36347.8300", tax_total: "5452.1700", total: "41800.0000", amount_due: "41800.0000" },
  { id: "i401", number: "INV-2019-0401", status: "posted", contact: c("c3", "Rift Valley Roasters Ltd"), issue_date: "2026-09-15", due_date: "2026-10-15", currency: "USD", subtotal: "1240.0000", tax_total: "0.0000", total: "1240.0000", amount_due: "1240.0000" },
  { id: "i395", number: "INV-2019-0395", status: "posted", contact: c("c2", "Bole Brew Café"), issue_date: "2026-09-02", due_date: "2026-10-02", currency: "ETB", subtotal: "50652.1700", tax_total: "7597.8300", total: "58250.0000", amount_due: "58250.0000" },
  { id: "i388", number: "INV-2019-0388", status: "posted", contact: c("c1", "Sheger Grand Hotel", "0012345678"), issue_date: "2026-08-17", due_date: "2026-09-16", currency: "ETB", subtotal: "83826.0900", tax_total: "12573.9100", total: "96400.0000", amount_due: "96400.0000" },
];

export const demoKpis = {
  cash: "2486320.0000",
  receivable: "914500.0000",
  overdue: "286900.0000",
  overdueCount: 12,
  payable: "388140.0000",
  billsDueThisWeek: 6,
  netProfit: "412880.0000",
  vatDue: "142615.0000",
  vatDueDays: 28,
  einvoices: { cleared: 318, waiting: 2, rejected: 1 },
};

export const demoAccounts = [
  { name: "CBE current ··4417", note: "Synced 12 min ago · 8 to review", balance: "1604210.0000" },
  { name: "Awash savings ··0932", note: "Statement uploaded 1 Nov", balance: "512000.0000" },
  { name: "Telebirr merchant", note: "Live · 5 to review", balance: "327610.0000" },
  { name: "Petty cash · Bole shop", note: "Counted 31 Oct", balance: "42500.0000" },
];

const r = (code: string, name: string, current: string, previous: string) => ({ code, name, current, previous });

export const demoProfitAndLoss: ProfitAndLoss = {
  sections: {
    income: [
      r("4000", "Sales · roasted coffee", "1402600.0000", "1361330.0000"),
      r("4010", "Sales · green coffee export", "386200.0000", "355600.0000"),
      r("4100", "Delivery income", "21400.0000", "19800.0000"),
    ],
    cost_of_sales: [
      r("5000", "Green coffee purchases", "742300.0000", "701900.0000"),
      r("5010", "Roasting and packaging", "118500.0000", "112200.0000"),
    ],
    expenses: [
      r("6000", "Salaries and wages", "286300.0000", "286300.0000"),
      r("6010", "Pension · employer", "31500.0000", "31500.0000"),
      r("6100", "Rent", "120000.0000", "120000.0000"),
      r("6200", "Utilities", "18640.0000", "17920.0000"),
      r("6300", "Telephone and internet", "9850.0000", "9850.0000"),
      r("6400", "Vehicle and fuel", "22400.0000", "19600.0000"),
      r("6500", "Marketing", "15000.0000", "22000.0000"),
      r("6600", "Bank charges and fees", "6230.0000", "5980.0000"),
      r("6700", "Depreciation", "24600.0000", "24600.0000"),
      r("4950", "Foreign exchange (gain)", "-6000.0000", "-4000.0000"),
      r("7000", "Interest expense", "8000.0000", "8000.0000"),
    ],
  },
  totals: {
    income: { current: "1810200.0000", previous: "1736730.0000" },
    cost_of_sales: { current: "860800.0000", previous: "814100.0000" },
    gross_profit: { current: "949400.0000", previous: "922630.0000" },
    expenses: { current: "536520.0000", previous: "541750.0000" },
    net_profit: { current: "412880.0000", previous: "380880.0000" },
  },
};

export const demoTaxRates = [
  { id: "vat15", code: "VAT15", name: "VAT 15%", rate: "0.15" },
  { id: "vat0", code: "VAT0", name: "VAT zero-rated", rate: "0" },
  { id: "exempt", code: "EXEMPT", name: "VAT exempt", rate: "0" },
];
