/* Module 6, the chart of accounts: how the screen groups accounts and shows their balances. */

export type AccountType = "asset" | "liability" | "equity" | "income" | "expense";

export const TYPE_LABELS: Record<AccountType, string> = {
  asset: "Assets", liability: "Liabilities", equity: "Equity", income: "Income", expense: "Expenses",
};

export const KIND_LABELS: Record<string, string> = {
  fixed_asset: "Fixed asset", contra_asset: "Contra asset", prepayment: "Prepayment", deposit: "Deposit", tax_receivable: "Tax receivable",
  accrual: "Accrual", loan: "Loan", deferred_income: "Deferred income", tax_payable: "Tax payable", payroll: "Payroll",
  capital: "Capital", drawings: "Drawings", reserve: "Reserve", sales: "Sales", other_income: "Other income", contra_income: "Contra income",
  operating: "Operating", cogs: "Cost of sales", depreciation: "Depreciation", finance_cost: "Finance cost", income_tax: "Income tax",
  bank: "Bank", cash: "Cash", mobile_money: "Mobile money", receivable: "Receivable", payable: "Payable", inventory: "Inventory",
  retained_earnings: "Retained earnings", opening_balance: "Opening balance", fx: "Exchange differences",
};

/**
 * A balance as people read it: debit-normal accounts (assets, expenses) as they are, credit-normal ones (liabilities,
 * equity, income) with the sign turned, so a loan of 5,000 reads 5,000 and not -5,000. The ledger keeps debit minus credit.
 */
export function naturalBalance(type: AccountType, debitMinusCredit: string): string {
  const v = debitMinusCredit.trim();
  if (type === "asset" || type === "expense" || /^-?0+(\.0+)?$/.test(v)) return /^-?0+(\.0+)?$/.test(v) ? "0.00" : v;
  return v.startsWith("-") ? v.slice(1) : `-${v}`;
}

/** Accounts grouped by type, in the order of the balance sheet then the income statement, keeping the API's order inside. */
export function byType<T extends { type: AccountType }>(accounts: T[]): { type: AccountType; label: string; accounts: T[] }[] {
  return (Object.keys(TYPE_LABELS) as AccountType[])
    .map((type) => ({ type, label: TYPE_LABELS[type], accounts: accounts.filter((a) => a.type === type) }))
    .filter((g) => g.accounts.length > 0);
}

/**
 * QuickBooks' "+ Add new" in an account list: the kinds of account a form may add, each with the code range it takes
 * its next free code from (the IFRS for SMEs chart's: income 4xxx, cost of sales 5xxx, expenses 6xxx, assets 1xxx).
 */
export const NEW_ACCOUNT_CHOICES = {
  sales: { type: "income", kind: "sales", label: "Income", from: 4000, to: 4899 },
  other_income: { type: "income", kind: "other_income", label: "Other income", from: 4900, to: 4999 },
  operating: { type: "expense", kind: "operating", label: "Expense", from: 6000, to: 6999 },
  cogs: { type: "expense", kind: "cogs", label: "Cost of sales", from: 5000, to: 5999 },
  fixed_asset: { type: "asset", kind: "fixed_asset", label: "Fixed asset", from: 1500, to: 1599 },
  prepayment: { type: "asset", kind: "prepayment", label: "Prepayment", from: 1400, to: 1499 },
} as const satisfies Record<string, { type: AccountType; kind: string; label: string; from: number; to: number }>;

export type NewAccountChoice = keyof typeof NEW_ACCOUNT_CHOICES;

/** What a sales form's account list may add, and what a purchase form's may. */
export const SALE_ACCOUNT_CHOICES: NewAccountChoice[] = ["sales", "other_income"];
export const COST_ACCOUNT_CHOICES: NewAccountChoice[] = ["operating", "cogs", "fixed_asset", "prepayment"];

/**
 * The next free code in a range: the next ten after the highest code used in it (6900 → 6910), or the first free
 * number when the tens are full; null when the range is full. Codes outside it (or not numbers) are left alone.
 */
export function nextAccountCode(codes: string[], from: number, to: number): string | null {
  const taken = new Set(codes.filter((c) => /^\d+$/.test(c)).map(Number).filter((n) => n >= from && n <= to));
  const top = Math.max(from - 10, ...taken);
  for (let n = Math.floor(top / 10) * 10 + 10; n <= to; n += 10) if (!taken.has(n)) return String(n);
  for (let n = from; n <= to; n++) if (!taken.has(n)) return String(n);
  return null;
}
