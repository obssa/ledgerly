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
