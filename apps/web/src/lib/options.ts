import type { Customer, Item, Supplier } from "@/lib/api";
import type { InvoiceOptions } from "@/lib/data";

/*
 * What a document form needs of a customer, a supplier or a product or service. The server builds the form's lists
 * with these, and QuickBooks' "+ Add new" (quick-add.ts) builds the one it has just added the same way, so a contact
 * or product added from the form behaves exactly as one that was already in the list.
 */

export type ContactOption = InvoiceOptions["customers"][number];
export type ItemOption = NonNullable<InvoiceOptions["items"]>[number];

export const customerOption = (c: Customer): ContactOption => ({
  id: c.id, name: c.name, tax_id: c.tax_id, currency: c.currency, payment_terms_days: c.payment_terms_days, payment_terms_id: c.payment_terms_id ?? null,
  price_list_id: c.price_list_id ?? null, email: c.email, address: c.address ?? null, delivery_address: c.delivery_address ?? null,
});

export const supplierOption = (s: Supplier): ContactOption => ({
  id: s.id, name: s.name, tax_id: s.tax_id, currency: s.currency, payment_terms_days: s.payment_terms_days, payment_terms_id: s.payment_terms_id ?? null,
  default_account_id: s.default_expense_account_id, email: s.email, address: s.address ?? null,
});

/** On a sales form: its sale price and income account. */
export const saleItemOption = (i: Item): ItemOption => ({
  id: i.id, name: i.name, type: i.type, unit: i.unit, price: i.sale_price, account_id: i.income_account_id, units: i.units ?? [], barcode: i.barcode ?? null, sku: i.sku ?? null,
});

/** On a purchase form: its purchase cost and expense account, and how its stock is tracked. */
export const purchaseItemOption = (i: Item): ItemOption => ({
  id: i.id, name: i.name, type: i.type, unit: i.unit, price: i.purchase_price, account_id: i.expense_account_id, units: i.units ?? [], tracking: i.tracking ?? "none", sku: i.sku ?? null,
});
