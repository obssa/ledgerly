"use client";
import { DateInput } from "@/components/DateInput";

import Link from "@/components/Link";
import { startTransition, useActionState, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { addressText } from "@/lib/address";
import { Card } from "@/components/ui";
import type { FormField, InvoiceDetail, TaxMode } from "@/lib/api";
import type { InvoiceOptions } from "@/lib/data";
import { discountedAmounts, lineAmounts, parse } from "@/lib/decimal";
import { formatAmount, formatMoney } from "@/lib/format";
import { createBill } from "../../bills/actions";
import { createExpense } from "../../expenses/actions";
import { createSalesReceipt } from "../../sales-receipts/actions";
import { createInvoice } from "../actions";
import { saveQuote } from "../../quotes/actions";
import { savePurchaseOrder } from "../../purchase-orders/actions";
import { todayIn } from "@/lib/dates";
import { findBarcode } from "@/lib/barcodes";
import { describeTerm, dueDate as termDueDate } from "@/lib/terms";

/** The words and postings that differ between a sales invoice and a supplier's bill; the form is the same. */
const KIND = {
  invoice: {
    party: "Customer", who: "customer", parties: "customers", newParty: "/customers/new", account: "Income account", numberLabel: "Invoice no.",
    notes: "Notes for the customer (optional)", noun: "invoice", posting: [["Dr Accounts receivable", "total"], ["Cr Income", "subtotal"], ["Cr Tax payable", "tax"]],
  },
  bill: {
    party: "Supplier", who: "supplier", parties: "suppliers", newParty: "/suppliers/new", account: "Expense account", numberLabel: "Our bill no.",
    notes: "Notes (optional)", noun: "bill", posting: [["Dr Expense or asset", "subtotal"], ["Dr VAT receivable", "tax"], ["Cr Accounts payable", "total"]],
  },
  // A cash sale (070): paid at once, posted at once into the money account it went into.
  sales_receipt: {
    party: "Customer", who: "customer", parties: "customers", newParty: "/customers/new", account: "Income account", numberLabel: "Sales receipt no.",
    notes: "Notes for the customer (optional)", noun: "cash sale", posting: [["Dr Received into", "total"], ["Cr Income", "subtotal"], ["Cr Tax payable", "tax"]],
  },
  expense: {
    party: "Paid to", who: "supplier", parties: "suppliers", newParty: "/suppliers/new", account: "Expense account", numberLabel: "Expense no.",
    notes: "Notes (optional)", noun: "expense", posting: [["Dr Expense or asset", "subtotal"], ["Dr VAT receivable", "tax"], ["Cr Paid from", "total"]],
  },
  // Quotes and sales orders post nothing (056): no posting preview.
  quote: {
    party: "Customer", who: "customer", parties: "customers", newParty: "/customers/new", account: "Income account", numberLabel: "Quote no.",
    notes: "Notes for the customer (optional)", noun: "quote", posting: [],
  },
  sales_order: {
    party: "Customer", who: "customer", parties: "customers", newParty: "/customers/new", account: "Income account", numberLabel: "Order no.",
    notes: "Notes for the customer (optional)", noun: "sales order", posting: [],
  },
  // A purchase order posts nothing either (068): its bills do.
  purchase_order: {
    party: "Supplier", who: "supplier", parties: "suppliers", newParty: "/suppliers/new", account: "Expense account", numberLabel: "Order no.",
    notes: "Notes for the supplier (optional)", noun: "purchase order", posting: [],
  },
} as const;

/** What the form needs from a saved document to edit it: an invoice draft, or a quote or sales order. */
type Draft = Pick<InvoiceDetail, "id" | "contact" | "issue_date" | "due_date" | "payment_terms_id" | "tax_mode" | "class_id" | "location_id" | "custom_fields" | "billing_address" | "shipping_address" | "email_to" | "email_cc" | "email_bcc" | "ship_via" | "ship_date" | "tracking_number" | "message_on_statement" | "discount_type" | "discount_value" | "deposit_amount" | "deposit_account_id" | "currency" | "fx_rate" | "prices_include_tax" | "salesperson_id"
  | "warehouse_id" | "number" | "notes" | "lines" | "lock_version"> & { status: string; reference?: string | null };

const METHODS: Record<string, string> = {
  cash: "Cash", card: "Card", bank_transfer: "Bank transfer", mobile_money: "Mobile money (telebirr, M-PESA …)", cheque: "Cheque", other: "Other",
};

/** Kept for callers and tests that pass tax rates on their own. */
export interface TaxRateOption {
  id: string;
  code: string;
  name: string;
  rate: string;
}

interface Line {
  key: number;
  itemId: string;
  description: string;
  quantity: string;
  unitPrice: string;
  taxRateId: string;
  accountId: string;
  projectId?: string;
  /** 111: QuickBooks' Service date, and the line's class and location when tracked per line. */
  serviceDate?: string;
  classId?: string;
  locationId?: string;
  /** The sales order line this invoice line delivers (067): kept through edits. */
  sourceLineId?: string;
  /** 072: another unit of the item; empty is the item's own unit. */
  unitId?: string;
  /** 074: the batch a bill line brings in (an item tracked by batch). */
  batchNumber?: string;
  batchExpires?: string;
}

const isDecimal = (v: string) => /^\d+(\.\d+)?$/.test(v.replace(/,/g, ""));
const plain = (v: string) => v.replace(/,/g, "");
const addDays = (date: string, days: number) => {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
};

/**
 * A new invoice on the company's own customers, income accounts, tax rates and currencies. Totals are previewed with
 * the same exact-decimal rules as the API (src/lib/decimal.ts mirrors TaxCalculator); the API recalculates on save,
 * and its numbers are the ones that post.
 */
export function InvoiceForm({ options, kind = "invoice", draft }: { options: InvoiceOptions; kind?: keyof typeof KIND; draft?: Draft }) {
  const words = KIND[kind];
  const purchase = kind === "purchase_order";
  const quote = kind === "quote" || kind === "sales_order" || purchase;
  // A quote already sent, or an order already confirmed, is revised: its earlier version is kept, with what changed.
  const revision = quote && draft !== undefined && draft.status !== "draft";
  // A posted invoice or bill corrected in place: the API reverses its entry, posts the corrected one and keeps the old version.
  const correcting = (kind === "invoice" || kind === "bill") && draft !== undefined && draft.status === "posted";
  const [state, action, pending] = useActionState(kind === "bill" ? createBill : kind === "expense" ? createExpense : kind === "sales_receipt" ? createSalesReceipt
    : purchase ? savePurchaseOrder : quote ? saveQuote : createInvoice, undefined);
  const onTheSpot = kind === "expense" || kind === "sales_receipt";   // paid at once, from or into a money account
  const today = todayIn();
  const firstRate = options.taxRates[0]?.id ?? "";
  const firstAccount = options.accounts[0]?.id ?? "";

  const [customerId, setCustomerId] = useState(draft?.contact.id ?? "");
  const [issueDate, setIssueDate] = useState(draft?.issue_date ?? today);
  // QuickBooks' Terms (109), on invoices and bills: the term sets the due date from the date, in the company's calendar;
  // changing the customer brings their usual term, changing the date or the term sets it again; a date typed stands.
  const termed = kind === "invoice" || kind === "bill";
  const terms = options.terms ?? [];
  const calendar = options.calendar ?? "gregorian";
  const dueBy = (date: string, id: string, contactId: string): string => {
    const term = terms.find((t) => t.id === id);
    if (term) return termDueDate(date, term, calendar);
    const contact = options.customers.find((c) => c.id === contactId);
    return contact ? addDays(date, contact.payment_terms_days) : "";
  };
  const [termId, setTermId] = useState(draft ? draft.payment_terms_id ?? "" : "");
  const [due, setDue] = useState(draft?.due_date ?? "");
  const term = terms.find((t) => t.id === termId);
  // This kind's numbering (108): the next number shown, editable when custom numbers are allowed; a book is typed.
  const numbering = options.numbers?.find((n) => n.doc_type === kind);
  const manual = numbering ? numbering.mode === "manual" : options.manualNumbers;
  const custom = numbering?.allow_custom ?? true;
  const suggested = manual ? "" : numbering?.next_number ?? "";
  const [currency, setCurrency] = useState(draft?.currency ?? options.baseCurrency);
  // QuickBooks' Amounts are (110): the document's own, else the company's default. Out of scope of tax: no tax at all.
  const [mode, setMode] = useState<TaxMode>(draft ? draft.tax_mode ?? (draft.prices_include_tax ? "inclusive" : "exclusive") : options.amountsAre ?? "exclusive");
  const inclusive = mode === "inclusive";
  const untaxed = mode === "out_of_scope";
  const projects = options.projects ?? [];
  // QuickBooks' Customize (111): the sales or purchase form's fields, and how classes and locations are tracked.
  const purchase_ = kind === "bill" || kind === "expense" || kind === "purchase_order";
  const content = options.formContent;
  const show = (purchase_ ? content?.purchases : content?.sales) ?? ({} as Partial<Record<FormField, boolean>>);
  const classes = options.classes ?? [];
  const locations = options.locations ?? [];
  const classesBy = classes.length > 0 ? content?.track_classes ?? "off" : "off";
  const locationsBy = locations.length > 0 ? content?.track_locations ?? "off" : "off";
  const customFields = (content?.custom_fields ?? []).filter((f) => (purchase_ ? f.purchases : f.sales));
  // 112: who it goes to and where, filled from the customer (or supplier) and changed per document, as QuickBooks does.
  const sale = !purchase_;
  const [emailTo, setEmailTo] = useState(draft ? draft.email_to ?? "" : "");
  const [billTo, setBillTo] = useState(draft ? draft.billing_address ?? "" : "");
  const [shipTo, setShipTo] = useState(draft ? draft.shipping_address ?? "" : "");
  // Tags and files chosen while creating (112): tags go with the save; files go up from here once it is saved.
  const attachable = !draft && (kind === "invoice" || kind === "bill" || kind === "expense");
  const tags = attachable && show.tags !== false ? options.tags ?? [] : [];
  const files = useRef<File[]>([]);
  const router = useRouter();
  const [sending, setSending] = useState(false);
  // 113: QuickBooks' discount before tax (sales forms) and deposit (invoices), when on in Customize.
  const discountable = sale && Boolean(show.discount);
  const [discountType, setDiscountType] = useState<"percent" | "amount">(draft?.discount_type ?? "percent");
  const [discountValue, setDiscountValue] = useState(draft?.discount_value ? String(Number(draft.discount_value)) : "");
  const depositable = kind === "invoice" && Boolean(show.deposit);
  const [deposit, setDeposit] = useState(draft?.deposit_amount && Number(draft.deposit_amount) > 0 ? draft.deposit_amount : "");
  const [lines, setLines] = useState<Line[]>(draft && draft.lines.length > 0
    ? draft.lines.map((l, i) => ({ key: i + 1, itemId: l.item_id ?? "", description: l.description, quantity: l.quantity, unitPrice: l.unit_price,
        taxRateId: l.tax_rate_id ?? "", accountId: l.account_id, projectId: l.project_id ?? "", serviceDate: l.service_date ?? "", classId: l.class_id ?? "", locationId: l.location_id ?? "", sourceLineId: l.source_line_id ?? undefined, unitId: l.unit_id ?? "",
        batchNumber: l.batch_number ?? "", batchExpires: l.batch_expires_on ?? "" }))
    : [{ key: 1, itemId: "", description: "", quantity: "1", unitPrice: "", taxRateId: firstRate, accountId: firstAccount }]);
  const items = options.items ?? [];
  const withUnits = kind !== "expense" && items.some((i) => (i.units ?? []).length > 0);   // 072: the Unit column only when an item has units
  // A bundle's stock parts leave too (073): it needs a warehouse as a stock item does.
  const stockLine = lines.some((l) => ["inventory", "bundle"].includes(items.find((i) => i.id === l.itemId)?.type ?? ""));
  /** 080: the customer's price list price for the item in this currency, else the item's own price. */
  const priceOf = (item: { id: string; price: string | null }) => {
    const listId = options.customers.find((c) => c.id === customerId)?.price_list_id;
    const list = listId ? options.priceLists?.find((p) => p.id === listId && p.currency === currency) : undefined;
    return list?.prices[item.id] ?? item.price;
  };
  /** Picking an item fills the line from it; the person can still change what was filled. */
  const pickItem = (key: number, itemId: string) => {
    const item = items.find((i) => i.id === itemId);
    setLines((ls) => ls.map((l) => (l.key !== key ? l : {
      ...l, itemId, unitId: "",
      ...(item ? { description: item.name, unitPrice: priceOf(item) ?? l.unitPrice, accountId: item.account_id && options.accounts.some((a) => a.id === item.account_id) ? item.account_id : l.accountId } : {}),
    })));
  };
  /** 072: another unit of the item; the price follows from the item's own price times the unit's size. */
  const pickUnit = (key: number, unitId: string) => {
    setLines((ls) => ls.map((l) => {
      if (l.key !== key) return l;
      const item = items.find((i) => i.id === l.itemId);
      const unit = item?.units?.find((u) => u.id === unitId);
      const factor = unit ? Number(unit.factor) : 1;
      const price = item ? priceOf(item) : null;
      return { ...l, unitId, ...(price ? { unitPrice: (Number(price) * factor).toFixed(2) } : {}) };
    }));
  };

  const customer = options.customers.find((c) => c.id === customerId);
  const rateOf = (id: string) => (untaxed ? "0" : options.taxRates.find((t) => t.id === id)?.rate ?? "0");
  const valid = lines.filter((l) => isDecimal(l.quantity) && isDecimal(l.unitPrice));
  const totals = useMemo(
    () => discountedAmounts(valid.map((l) => ({ quantity: plain(l.quantity), unitPrice: plain(l.unitPrice), taxRate: rateOf(l.taxRateId) })), currency, inclusive,
      discountable && discountValue.trim() !== "" ? { type: discountType, value: plain(discountValue).replace(/%/g, "") } : null),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [lines, mode, currency, discountable, discountType, discountValue],
  );

  const next = state?.next;
  useEffect(() => {
    if (!next) return;
    let gone = false;
    (async () => {
      for (const file of files.current) {
        const body = new FormData();
        body.append("kind", next.kind);
        body.append("document_id", next.id);
        body.append("file", file, file.name);
        // The upload route answers with a redirect to the document; the next file follows all the same.
        await fetch("/attachments/upload", { method: "POST", body, redirect: "manual" }).catch(() => undefined);
      }
      files.current = [];
      if (!gone) router.push(next.href);
    })();
    return () => { gone = true; };
  }, [next, router]);
  if (next && !sending) setSending(true);

  const update = (key: number, patch: Partial<Line>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const addLine = () => setLines((ls) => [...ls, { key: Math.max(0, ...ls.map((l) => l.key)) + 1, itemId: "", description: "", quantity: "1", unitPrice: "", taxRateId: firstRate, accountId: firstAccount }]);
  const removeLine = (key: number) => setLines((ls) => (ls.length > 1 ? ls.filter((l) => l.key !== key) : ls));
  /** 085: a barcode scanned or typed adds its item in that unit (one more if it is already on a line). */
  const [scanned, setScanned] = useState<string | null>(null);
  const scan = (code: string) => {
    const hit = findBarcode(items, code);
    if (!hit) {
      setScanned(code.trim() === "" ? null : `No item has barcode ${code.trim()}.`);
      return;
    }
    setScanned(null);
    const item = items.find((i) => i.id === hit.itemId)!;
    const unit = hit.unitId ? item.units?.find((u) => u.id === hit.unitId) : undefined;
    const base = priceOf(item);
    const price = base ? (Number(base) * (unit ? Number(unit.factor) : 1)).toFixed(2) : "";
    const account = item.account_id && options.accounts.some((a) => a.id === item.account_id) ? item.account_id : firstAccount;
    setLines((ls) => {
      const same = ls.find((l) => l.itemId === hit.itemId && (l.unitId || "") === (hit.unitId ?? ""));
      if (same) return ls.map((l) => (l.key === same.key ? { ...l, quantity: String(Number(plain(l.quantity) || "0") + 1) } : l));
      const filled = { itemId: item.id, unitId: hit.unitId ?? "", description: item.name, quantity: "1", unitPrice: price, accountId: account };
      const last = ls[ls.length - 1];
      if (last && !last.itemId && last.description.trim() === "" && last.unitPrice === "") return ls.map((l) => (l.key === last.key ? { ...l, ...filled } : l));
      return [...ls, { key: Math.max(0, ...ls.map((l) => l.key)) + 1, taxRateId: firstRate, ...filled }];
    });
  };
  const linesJson = JSON.stringify(lines.map((l) => ({ description: l.description, quantity: l.quantity, unit_price: l.unitPrice, account_id: l.accountId, tax_rate_id: untaxed ? null : l.taxRateId || null, item_id: l.itemId || null, project_id: l.projectId || null, unit_id: l.unitId || null, service_date: show.service_date ? l.serviceDate || null : null,
    class_id: classesBy === "line" ? l.classId || null : null, location_id: locationsBy === "line" ? l.locationId || null : null, ...(l.sourceLineId ? { source_line_id: l.sourceLineId } : {}),
    ...(l.batchNumber ? { batch_number: l.batchNumber, batch_expires_on: l.batchExpires || null } : {}) })));
  const lineError = (i: number) => Boolean(state?.fields && Object.keys(state.fields).some((k) => k.startsWith(`lines.${i}.`)));
  const bad = (name: string) => Boolean(state?.fields?.[name]);

  if (options.customers.length === 0) {
    return (
      <Card label={`No ${words.parties} yet`}>
        <p className="text-sm">Add a {words.who} first. <Link href={words.newParty} className="font-bold text-brand">New {words.who}</Link></p>
      </Card>
    );
  }

  return (
    // Submitted by hand, not as a form action: React resets a form after its action, which cleared the chosen
    // customer and the typed fields whenever a save was refused, so the corrected retry was refused again.
    <form onSubmit={(e: FormEvent<HTMLFormElement>) => {
      e.preventDefault();
      const data = new FormData(e.currentTarget, (e.nativeEvent as SubmitEvent).submitter);
      // Files are not sent with the save: they go to the upload route once the document exists (112).
      files.current = data.getAll("new_files").filter((f): f is File => f instanceof File && f.size > 0);
      data.delete("new_files");
      if (files.current.length > 0) data.set("attach_pending", "1");
      startTransition(() => action(data));
    }} noValidate className="flex flex-wrap items-start gap-4">
      <input type="hidden" name="lines" value={linesJson} />
      {draft && <input type="hidden" name="id" value={draft.id} />}
      {draft?.lock_version && <input type="hidden" name="lock_version" value={String(draft.lock_version)} />}
      {quote && <input type="hidden" name="doc_kind" value={kind} />}
      <div className="flex min-w-0 flex-[2_1_560px] flex-col gap-4">
        {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
        <div className="flex justify-end">
          {/* QuickBooks' Customize: which fields the form shows, in Settings. */}
          <Link href="/settings#form-content" className="text-[13px] font-semibold text-primary hover:underline">Customize this form</Link>
        </div>
        <Card label={`${words.party} and terms`}>
          <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">
            <div className="sm:col-span-2">
              <label className="field-label" htmlFor="contact_id">{words.party}</label>
              <select className={`field ${bad("contact_id") ? "!border-bad" : ""}`} id="contact_id" name="contact_id" value={customerId}
                onChange={(e) => {
                  setCustomerId(e.target.value);
                  const c = options.customers.find((x) => x.id === e.target.value);
                  setEmailTo(c?.email ?? "");
                  setBillTo(addressText(c?.address));
                  setShipTo(addressText(c?.delivery_address ?? c?.address));
                  if (termed) {
                    const usual = c?.payment_terms_id && terms.some((t) => t.id === c.payment_terms_id) ? c.payment_terms_id : "";
                    setTermId(usual);
                    setDue(c ? dueBy(issueDate, usual, c.id) : "");
                  }
                  if (c?.currency && options.currencies.includes(c.currency)) setCurrency(c.currency);
                  // A supplier's usual expense account goes on the lines that still have the first account.
                  const usual = c?.default_account_id;
                  if (usual && options.accounts.some((a) => a.id === usual)) {
                    setLines((ls) => ls.map((l) => (l.accountId === firstAccount ? { ...l, accountId: usual } : l)));
                  }
                }}>
                <option value="">Choose a {words.who}</option>
                {options.customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
              {customer && <p className="mt-1.5 text-[13px] text-muted">{customer.tax_id ? <>TIN <span className="num">{customer.tax_id}</span> · </> : "No TIN · "}Usual terms: {terms.find((t) => t.id === customer.payment_terms_id)?.name ?? `${customer.payment_terms_days} days`}</p>}
            </div>
            {sale && kind !== "sales_receipt" && (
              <div>
                <label className="field-label" htmlFor="email_to">Customer email</label>
                <input className={`field ${bad("email_to") ? "!border-bad" : ""}`} id="email_to" name="email_to" type="text" inputMode="email" value={emailTo}
                  onChange={(e) => setEmailTo(e.target.value)} placeholder="Separate addresses with a comma" />
              </div>
            )}
            {sale && kind !== "sales_receipt" && show.email_cc_bcc && (
              <>
                <Field name="email_cc" label="Cc" defaultValue={draft?.email_cc ?? ""} invalid={bad("email_cc")} />
                <Field name="email_bcc" label="Bcc" defaultValue={draft?.email_bcc ?? ""} invalid={bad("email_bcc")} />
              </>
            )}
            {kind !== "expense" && <div>
              <label className="field-label" htmlFor="billing_address">{sale ? "Billing address" : "Mailing address"}</label>
              <textarea className={`field min-h-[72px] ${bad("billing_address") ? "!border-bad" : ""}`} id="billing_address" name="billing_address" maxLength={500}
                value={billTo} onChange={(e) => setBillTo(e.target.value)} />
            </div>}
            {sale && show.shipping && (
              <>
                <div>
                  <label className="field-label" htmlFor="shipping_address">Ship to</label>
                  <textarea className={`field min-h-[72px] ${bad("shipping_address") ? "!border-bad" : ""}`} id="shipping_address" name="shipping_address" maxLength={500}
                    value={shipTo} onChange={(e) => setShipTo(e.target.value)} />
                </div>
                <Field name="ship_via" label="Ship via" defaultValue={draft?.ship_via ?? ""} invalid={bad("ship_via")} />
                <Field name="ship_date" label="Shipping date" type="date" defaultValue={draft?.ship_date ?? ""} invalid={bad("ship_date")} />
                <Field name="tracking_number" label="Tracking no." defaultValue={draft?.tracking_number ?? ""} invalid={bad("tracking_number")} />
              </>
            )}
            {kind === "bill" && <Field name="reference" label="Supplier's invoice number" hint="As printed on their invoice" defaultValue={draft?.reference ?? ""} invalid={bad("reference")} />}
            {onTheSpot && (
              <>
                {kind === "expense"
                  ? <Field name="reference" label="Receipt number" hint="As printed on the receipt" invalid={bad("reference")} />
                  : <Field name="reference" label="Till slip or transaction number" hint="Optional: the card or wallet transaction" invalid={bad("reference")} />}
                <div>
                  <label className="field-label" htmlFor="paid_from_account_id">{kind === "expense" ? "Paid from" : "Received into"}</label>
                  <select className={`field ${bad("paid_from_account_id") ? "!border-bad" : ""}`} id="paid_from_account_id" name="paid_from_account_id">
                    {(options.moneyAccounts ?? []).filter((a) => [currency, options.baseCurrency].includes(a.currency ?? options.baseCurrency)).map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                  </select>
                </div>
                <div>
                  <label className="field-label" htmlFor="payment_method">{kind === "expense" ? "Paid by" : "Paid by the customer"}</label>
                  <select className="field" id="payment_method" name="payment_method" defaultValue="cash">
                    {Object.entries(METHODS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                  </select>
                </div>
              </>
            )}
            {!revision && !correcting && (
              <div>
                <label className="field-label" htmlFor="number">{words.numberLabel}</label>
                {/* The next number in the sequence, or the person's own (QuickBooks' Custom transaction numbers); unique. */}
                <input className={`field num ${bad("number") ? "!border-bad" : ""}`} id="number" name="number" defaultValue={draft?.number ?? suggested}
                  readOnly={!manual && !custom} aria-describedby="number-hint" />
                {!draft?.number && suggested && <input type="hidden" name="number_suggested" value={suggested} />}
                <p id="number-hint" className="mt-1.5 text-[12.5px] text-muted">
                  {manual ? "From your book: each number once." : custom ? "Next in the sequence; type your own if you like (each number once)." : "Next in the sequence."}
                </p>
              </div>
            )}
            <div>
              <label className="field-label" htmlFor="issue_date">Issue date</label>
              <DateInput className={`field ${bad("issue_date") ? "!border-bad" : ""}`} id="issue_date" name="issue_date" value={issueDate} onValue={(v) => {
                setIssueDate(v);
                if (termed && customerId && /^\d{4}-\d{2}-\d{2}$/.test(v)) setDue(dueBy(v, termId, customerId));
              }} />
            </div>
            {termed && (
              <div>
                <label className="field-label" htmlFor="payment_terms_id">Terms</label>
                <select className={`field ${bad("payment_terms_id") ? "!border-bad" : ""}`} id="payment_terms_id" name="payment_terms_id" value={termId}
                  aria-describedby="payment_terms_hint"
                  onChange={(e) => {
                    setTermId(e.target.value);
                    if (customerId) setDue(dueBy(issueDate, e.target.value, customerId));
                  }}>
                  <option value="">{customer ? `None (${customer.payment_terms_days} days)` : "None"}</option>
                  {terms.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
                <p id="payment_terms_hint" className="mt-1.5 text-[13px] text-muted">
                  {term ? describeTerm(term) : "Sets the due date"} · <Link href="/settings#terms" className="font-semibold text-primary hover:underline">Terms list</Link>
                </p>
              </div>
            )}
            {termed && (
              <div>
                <label className="field-label" htmlFor="due_date">Due date</label>
                <DateInput className={`field ${bad("due_date") ? "!border-bad" : ""}`} id="due_date" name="due_date" value={due} onValue={(v) => setDue(v)}
                  aria-invalid={bad("due_date") || undefined} />
              </div>
            )}
            {!onTheSpot && !termed && (
              <Field key={`${customerId}-${issueDate}`} name="due_date" label={kind === "quote" ? "Valid until" : kind === "sales_order" ? "Deliver by" : purchase ? "Expected by" : "Due date"} type="date"
                defaultValue={draft?.due_date && customerId === draft.contact.id && issueDate === draft.issue_date ? draft.due_date
                  : kind === "quote" ? addDays(issueDate, 30) : kind === "sales_order" || purchase ? "" : customer ? addDays(issueDate, customer.payment_terms_days) : ""} invalid={bad("due_date")} />
            )}
            <div>
              <label className="field-label" htmlFor="currency">Currency</label>
              <select className="field" id="currency" name="currency" value={currency} onChange={(e) => setCurrency(e.target.value)}>
                {options.currencies.map((c) => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
            {(kind === "invoice" || kind === "sales_receipt" || (quote && !purchase)) && (options.people ?? []).length > 0 && (
              <div>
                <label className="field-label" htmlFor="salesperson_id">Salesperson</label>
                <select className={`field ${bad("salesperson_id") ? "!border-bad" : ""}`} id="salesperson_id" name="salesperson_id" defaultValue={draft?.salesperson_id ?? ""}>
                  <option value="">None</option>
                  {(options.people ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              </div>
            )}
            {stockLine && (
              <div>
                <label className="field-label" htmlFor="warehouse_id">{kind === "bill" || purchase ? "Goods arrive in" : "Goods leave from"}</label>
                <select className={`field ${bad("warehouse_id") ? "!border-bad" : ""}`} id="warehouse_id" name="warehouse_id" defaultValue={draft?.warehouse_id ?? undefined}>
                  {(options.warehouses ?? []).map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                </select>
              </div>
            )}
            {currency !== options.baseCurrency && (
              <Field name="fx_rate" defaultValue={draft && currency === draft.currency && draft.currency !== options.baseCurrency ? draft.fx_rate : ""} label={`Rate (${options.baseCurrency} for 1 ${currency})`} hint="Empty: the company's rate for the issue date" invalid={bad("fx_rate")} />
            )}
            <div>
              <label className="field-label" htmlFor="tax_mode">Amounts are</label>
              <select className={`field ${bad("tax_mode") ? "!border-bad" : ""}`} id="tax_mode" name="tax_mode" value={mode} onChange={(e) => setMode(e.target.value as TaxMode)}>
                <option value="exclusive">Exclusive of tax</option>
                <option value="inclusive">Inclusive of tax</option>
                <option value="out_of_scope">Out of scope of tax</option>
              </select>
              {untaxed && <p className="mt-1.5 text-[13px] text-muted">No tax on any line, and left off the VAT return. Zero-rated or exempt sales take their tax rate instead.</p>}
            </div>
            {locationsBy === "transaction" && (
              <div>
                <label className="field-label" htmlFor="location_id">Location</label>
                <select className={`field ${bad("location_id") ? "!border-bad" : ""}`} id="location_id" name="location_id" defaultValue={draft?.location_id ?? ""}>
                  <option value="">—</option>
                  {locations.map((d) => <option key={d.id} value={d.id}>{d.code} {d.name}</option>)}
                </select>
              </div>
            )}
            {classesBy === "transaction" && (
              <div>
                <label className="field-label" htmlFor="class_id">Class</label>
                <select className={`field ${bad("class_id") ? "!border-bad" : ""}`} id="class_id" name="class_id" defaultValue={draft?.class_id ?? ""}>
                  <option value="">—</option>
                  {classes.map((d) => <option key={d.id} value={d.id}>{d.code} {d.name}</option>)}
                </select>
              </div>
            )}
            {customFields.map((f, i) => (
              <div key={f.name}>
                <label className="field-label" htmlFor={`custom_field_${i}`}>{f.name}</label>
                <input className="field" id={`custom_field_${i}`} name={`custom_fields.${f.name}`} maxLength={100} defaultValue={draft?.custom_fields?.[f.name] ?? ""} />
              </div>
            ))}
          </div>
        </Card>

        <Card className="!p-2" label="Line items">
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr>{show.service_date && <th scope="col">Service date</th>}{items.length > 0 && kind !== "expense" && <th scope="col">Item</th>}{show.sku && items.length > 0 && kind !== "expense" && <th scope="col">SKU</th>}<th scope="col">Description</th><th scope="col">Qty</th>{withUnits && <th scope="col">Unit</th>}<th scope="col">Unit price</th>{!untaxed && <th scope="col">Tax</th>}<th scope="col">{words.account}</th>{projects.length > 0 && <th scope="col">Project</th>}{classesBy === "line" && <th scope="col">Class</th>}{locationsBy === "line" && <th scope="col">Location</th>}<th scope="col" className="!text-right">Amount</th><th scope="col"><span className="sr-only">Remove</span></th></tr>
              </thead>
              <tbody>
                {lines.map((l, i) => {
                  const ok = isDecimal(l.quantity) && isDecimal(l.unitPrice);
                  const amt = ok ? lineAmounts({ quantity: plain(l.quantity), unitPrice: plain(l.unitPrice), taxRate: rateOf(l.taxRateId) }, currency, inclusive) : null;
                  return (
                    <tr key={l.key} className={lineError(i) ? "bg-bad-tint" : ""}>
                      {show.service_date && (
                        <td><DateInput className="field w-[140px]" aria-label="Service date" value={l.serviceDate ?? ""} onValue={(v) => update(l.key, { serviceDate: v })} /></td>
                      )}
                      {items.length > 0 && kind !== "expense" && (
                        <td>
                          <select aria-label="Item" className="field w-[150px]" value={l.itemId} onChange={(e) => pickItem(l.key, e.target.value)}>
                            <option value="">—</option>
                            {items.map((it) => <option key={it.id} value={it.id}>{it.name}{it.type === "inventory" ? " (stock)" : ""}</option>)}
                          </select>
                        </td>
                      )}
                      {show.sku && items.length > 0 && kind !== "expense" && <td className="num text-[13px] text-muted">{items.find((it) => it.id === l.itemId)?.sku ?? "—"}</td>}
                      <td>
                        <input aria-label="Description" className="field min-w-[180px]" value={l.description} onChange={(e) => update(l.key, { description: e.target.value })} />
                        {items.find((it) => it.id === l.itemId)?.tracking === "serial" && (
                          // 096: a serialised item lists the serial number of each unit (going out, empty takes the oldest).
                          <input aria-label="Serial numbers" className="field mt-1 text-[13px]" placeholder="Serial numbers, one per unit, by commas"
                            value={l.batchNumber ?? ""} onChange={(e) => update(l.key, { batchNumber: e.target.value })} />
                        )}
                        {kind === "bill" && items.find((it) => it.id === l.itemId)?.tracking === "batch" && (
                          // 074: a tracked item comes in with its batch, and its expiry when it has one.
                          <div className="mt-1 flex gap-1">
                            <input aria-label="Batch" className="field !w-[110px] text-[13px]" placeholder="Batch" value={l.batchNumber ?? ""} onChange={(e) => update(l.key, { batchNumber: e.target.value })} />
                            <DateInput aria-label="Expires on" className="field !w-[150px] text-[13px]" value={l.batchExpires ?? ""} onValue={(v) => update(l.key, { batchExpires: v })} />
                          </div>
                        )}
                      </td>
                      <td><input aria-label="Quantity" inputMode="decimal" className="field num w-[72px] text-right" value={l.quantity} onChange={(e) => update(l.key, { quantity: e.target.value })} /></td>
                      {withUnits && (() => {
                        const item = items.find((it) => it.id === l.itemId);
                        const units = item?.units ?? [];
                        // A line made from an order line stays in that line's unit (072): no choice then.
                        return (
                          <td>
                            {item && units.length > 0 && !l.sourceLineId ? (
                              <select aria-label="Unit" className="field w-[120px]" value={l.unitId ?? ""} onChange={(e) => pickUnit(l.key, e.target.value)}>
                                <option value="">{item.unit}</option>
                                {units.map((u) => <option key={u.id} value={u.id}>{u.name} ({Number(u.factor)} {item.unit})</option>)}
                              </select>
                            ) : <span className="text-[13px] text-muted">{(l.unitId && units.find((u) => u.id === l.unitId)?.name) || draft?.lines.find((d) => d.unit_id === l.unitId && l.unitId)?.unit_name || item?.unit || ""}</span>}
                          </td>
                        );
                      })()}
                      <td><input aria-label="Unit price" inputMode="decimal" className="field num w-[110px] text-right" value={l.unitPrice} onChange={(e) => update(l.key, { unitPrice: e.target.value })} /></td>
                      {!untaxed && (
                        <td>
                          <select aria-label="Tax" className="field w-[130px]" value={l.taxRateId} onChange={(e) => update(l.key, { taxRateId: e.target.value })}>
                            <option value="">No tax</option>
                            {options.taxRates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                          </select>
                        </td>
                      )}
                      <td>
                        <select aria-label={words.account} className="field w-[160px]" value={l.accountId} onChange={(e) => update(l.key, { accountId: e.target.value })}>
                          {options.accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                        </select>
                      </td>
                      {projects.length > 0 && (
                        <td>
                          <select aria-label="Project" className="field w-[150px]" value={l.projectId ?? ""} onChange={(e) => update(l.key, { projectId: e.target.value })}>
                            <option value="">—</option>
                            {projects.map((p) => <option key={p.id} value={p.id}>{p.code} {p.name}</option>)}
                          </select>
                        </td>
                      )}
                      {classesBy === "line" && (
                        <td>
                          <select aria-label="Class" className="field w-[140px]" value={l.classId ?? ""} onChange={(e) => update(l.key, { classId: e.target.value })}>
                            <option value="">—</option>
                            {classes.map((d) => <option key={d.id} value={d.id}>{d.code} {d.name}</option>)}
                          </select>
                        </td>
                      )}
                      {locationsBy === "line" && (
                        <td>
                          <select aria-label="Location" className="field w-[140px]" value={l.locationId ?? ""} onChange={(e) => update(l.key, { locationId: e.target.value })}>
                            <option value="">—</option>
                            {locations.map((d) => <option key={d.id} value={d.id}>{d.code} {d.name}</option>)}
                          </select>
                        </td>
                      )}
                      <td className="num text-right font-semibold">{amt ? formatAmount(amt.subtotal, currency) : <span className="text-muted">—</span>}</td>
                      <td className="!pl-0"><button type="button" className="btn !px-3" aria-label={`Remove line ${i + 1}`} onClick={() => removeLine(l.key)}>×</button></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div className="flex flex-wrap justify-between gap-4 px-3 pb-2 pt-4">
            <div className="flex flex-col gap-2 self-start">
              <button type="button" className="btn self-start" onClick={addLine}>+ Add line</button>
              {items.some((i) => i.barcode || (i.units ?? []).some((u) => u.barcode)) && (
                <div>
                  <label className="field-label" htmlFor="scan">Scan or type a barcode</label>
                  <input className="field num w-[220px]" id="scan" inputMode="numeric" autoComplete="off"
                    onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); scan(e.currentTarget.value); e.currentTarget.value = ""; } }} />
                  {scanned && <p role="alert" className="mt-1 text-[13px] text-bad">{scanned}</p>}
                </div>
              )}
            </div>
            <dl className="flex-[0_1_320px] text-sm">
              <div className="flex justify-between py-1.5"><dt>Subtotal</dt><dd className="num">{formatAmount(totals.subtotal, currency)}</dd></div>
              {discountable && (
                <div className="flex items-center justify-between gap-2 py-1.5">
                  <dt className="flex items-center gap-2">
                    <label htmlFor="discount_type" className="sr-only">Discount as</label>
                    <select className="field !w-auto !py-1 text-[13px]" id="discount_type" name="discount_type" value={discountType} onChange={(e) => setDiscountType(e.target.value as "percent" | "amount")}>
                      <option value="percent">Discount percent</option>
                      <option value="amount">Discount value</option>
                    </select>
                    <label htmlFor="discount_value" className="sr-only">Discount</label>
                    <input className={`field num !w-[90px] !py-1 text-right ${bad("discount_value") ? "!border-bad" : ""}`} id="discount_value" name="discount_value" inputMode="decimal"
                      value={discountValue} onChange={(e) => setDiscountValue(e.target.value)} placeholder={discountType === "percent" ? "%" : "0.00"} />
                  </dt>
                  <dd className="num">{totals.discount > 0n ? `−${formatAmount(totals.discount, currency)}` : "—"}</dd>
                </div>
              )}
              {totals.discount > 0n && <div className="flex justify-between py-1.5"><dt>Taxable subtotal</dt><dd className="num">{formatAmount(totals.taxable, currency)}</dd></div>}
              {untaxed
                ? <div className="flex justify-between py-1.5 text-muted"><dt>Out of scope of tax</dt><dd>No tax</dd></div>
                : <div className="flex justify-between py-1.5"><dt>Tax</dt><dd className="num">{formatAmount(totals.tax, currency)}</dd></div>}
              <div className="mt-1.5 flex justify-between border-t-2 border-ink pt-3 text-lg font-extrabold"><dt>Total</dt><dd className="num font-semibold">{formatMoney(totals.total, currency)}</dd></div>
              {depositable && (
                <>
                  <div className="mt-2 flex items-center justify-between gap-2 py-1.5">
                    <dt><label htmlFor="deposit_amount">Deposit</label></dt>
                    <dd><input className={`field num !w-[120px] !py-1 text-right ${bad("deposit_amount") ? "!border-bad" : ""}`} id="deposit_amount" name="deposit_amount" inputMode="decimal"
                      value={deposit} onChange={(e) => setDeposit(e.target.value)} placeholder="0.00" /></dd>
                  </div>
                  {deposit.trim() !== "" && Number(plain(deposit)) > 0 && (
                    <div className="py-1.5">
                      <label className="field-label" htmlFor="deposit_account_id">Deposit received into</label>
                      <select className={`field ${bad("deposit_account_id") ? "!border-bad" : ""}`} id="deposit_account_id" name="deposit_account_id" defaultValue={draft?.deposit_account_id ?? ""}>
                        <option value="">Choose an account</option>
                        {(options.moneyAccounts ?? []).filter((a) => (a.currency ?? options.baseCurrency) === currency).map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
                      </select>
                    </div>
                  )}
                  <div className="flex justify-between py-1.5 font-semibold">
                    <dt>Balance due</dt>
                    <dd className="num">{formatMoney(isDecimal(deposit) ? totals.total - parse(plain(deposit)) : totals.total, currency)}</dd>
                  </div>
                </>
              )}
            </dl>
          </div>
        </Card>
        <Card label="Notes">
          <label className="field-label" htmlFor="notes">{sale && kind !== "sales_receipt" ? `Message on ${words.noun}` : words.notes}</label>
          <textarea className="field min-h-[72px]" id="notes" name="notes" maxLength={2000} defaultValue={draft?.notes ?? ""} />
          {sale && show.message_on_statement && (
            <>
              <label className="field-label mt-3" htmlFor="message_on_statement">Message on statement</label>
              <textarea className="field min-h-[56px]" id="message_on_statement" name="message_on_statement" maxLength={500} defaultValue={draft?.message_on_statement ?? ""}
                aria-describedby="message_on_statement_hint" />
              <p id="message_on_statement_hint" className="mt-1.5 text-[13px] text-muted">What the customer&apos;s statement says for this {words.noun}.</p>
            </>
          )}
          {tags.length > 0 && (
            <fieldset className="mt-3">
              <legend className="field-label">Tags</legend>
              <div className="flex flex-wrap gap-x-4 gap-y-2">
                {tags.map((t) => <label key={t.id} className="flex items-center gap-2 text-sm"><input type="checkbox" name="tag_ids" value={t.id} className="size-4" />{t.name}</label>)}
              </div>
            </fieldset>
          )}
          {attachable && show.attachments !== false && (
            <div className="mt-3">
              <label className="field-label" htmlFor="new_files">Attachments</label>
              <input className="field" id="new_files" name="new_files" type="file" multiple accept="application/pdf,image/png,image/jpeg,image/webp" aria-describedby="new_files_hint" />
              <p id="new_files_hint" className="mt-1.5 text-[13px] text-muted">PDF, PNG, JPEG or WebP, up to 10 MB each. Sent once the {words.noun} is saved.</p>
            </div>
          )}
          {sending && <p role="status" className="mt-3 text-[13px] font-semibold text-muted">Saved. Sending the files…</p>}
        </Card>
      </div>

      <aside className="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
        {quote ? (
          <Card label="What happens next">
            <h2 className="text-[17px] font-bold">{kind === "quote" ? "A quote posts nothing" : "An order posts nothing"}</h2>
            <p className="mt-1.5 text-[13px] text-muted">
              {kind === "quote"
                ? "Send it to the customer (it is numbered then). When they accept, turn it into a sales order or an invoice. Unanswered after its date, it expires."
                : purchase
                  ? "Issue it to the supplier (it is numbered then), and turn it into a bill as their invoices come in, in as many bills as it takes."
                  : "Confirm it (it is numbered then), and turn it into an invoice when the goods or the work are delivered."}
            </p>
            {revision && (
              <div className="mt-3">
                <label className="field-label" htmlFor="reason">What changed (kept with the earlier version)</label>
                <input className="field" id="reason" name="reason" maxLength={500} placeholder={purchase ? "Supplier changed the price" : "Customer asked for 12 bags"} />
              </div>
            )}
          </Card>
        ) : correcting ? (
          <Card label="Editing a posted document" className="border-brand/40 bg-brand-tint/40">
            <h2 className="text-[17px] font-bold">Editing a posted {words.noun}</h2>
            <p className="mt-1.5 text-[13px] text-muted">
              Change what is wrong and save: the books and every report show the new figures. The {words.noun} keeps its number, and its
              history keeps the version before this change with your name, the time and the reason.
            </p>
            <dl className="num mt-3 text-[13px]">
              {words.posting.map(([label, amount]) => (
                <div key={label} className="flex justify-between py-1"><dt>{label}</dt><dd>{formatAmount(totals[amount], currency)}</dd></div>
              ))}
            </dl>
            <div className="mt-3">
              <label className="field-label" htmlFor="reason">Reason for the change</label>
              <textarea className={`field min-h-[64px] ${bad("reason") ? "!border-bad" : ""}`} id="reason" name="reason" required minLength={3} maxLength={500}
                placeholder={kind === "bill" ? "The supplier corrected the price on their invoice" : "Customer returned 40 kg before delivery"} aria-invalid={bad("reason") || undefined} />
            </div>
          </Card>
        ) : (
        <Card label="Posting preview">
          <h2 className="text-[17px] font-bold">On approval this posts</h2>
          <dl className="num mt-2 text-[13px]">
            {words.posting.map(([label, amount]) => (
              <div key={label} className="flex justify-between py-1"><dt>{label}</dt><dd>{formatAmount(totals[amount], currency)}</dd></div>
            ))}
          </dl>
          <p className="mt-3 text-[13px] text-muted">
            {manual ? "The number is the one you type." : `The ${words.noun} is numbered when it is saved: the next in the sequence, or your own.`}{" "}
            {kind === "invoice"
              ? "Where e-invoicing applies, posting also sends it to the tax authority."
              : kind === "sales_receipt"
                ? "Recorded and posted at once: the money is already in. Stock items leave at their cost."
                : "VAT is reclaimable only when the company is VAT-registered; otherwise it is part of the cost."}
          </p>
        </Card>
        )}
        {options.demo ? (
          <p className="text-sm text-muted">Saving is not available in the demo.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {onTheSpot ? (
              <button type="submit" className="btn btn-primary" disabled={pending || (options.moneyAccounts ?? []).length === 0}>
                {pending ? "Recording…" : kind === "expense" ? "Record expense" : "Record cash sale"}
              </button>
            ) : revision ? (
              <button type="submit" name="intent" value="draft" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : "Save the revision"}</button>
            ) : correcting ? (
              <button type="submit" name="intent" value="correct" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : "Save changes"}</button>
            ) : quote ? (
              <>
                <button type="submit" name="intent" value="draft" className="btn" disabled={pending}>Save draft</button>
                <button type="submit" name="intent" value={kind === "quote" ? "send" : "accept"} className="btn btn-primary" disabled={pending}>
                  {pending ? "Saving…" : kind === "quote" ? "Save and mark as sent" : purchase ? "Save and issue" : "Save and confirm"}
                </button>
              </>
            ) : (
              <>
                <button type="submit" name="intent" value="draft" className="btn" disabled={pending}>Save draft</button>
                <button type="submit" name="intent" value="approve" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : "Save and approve"}</button>
              </>
            )}
          </div>
        )}
      </aside>
    </form>
  );
}

function Field({ name, label, type = "text", defaultValue = "", invalid, hint }: { name: string; label: string; type?: string; defaultValue?: string; invalid?: boolean; hint?: string }) {
  return (
    <div>
      <label className="field-label" htmlFor={name}>{label}</label>
      {type === "date" ? <DateInput className={`field ${invalid ? "!border-bad" : ""}`} id={name} name={name} defaultValue={defaultValue} aria-invalid={invalid || undefined} aria-describedby={hint ? `${name}-hint` : undefined} /> : (
        <input className={`field ${invalid ? "!border-bad" : ""}`} id={name} name={name} type={type} defaultValue={defaultValue} aria-invalid={invalid || undefined}
        aria-describedby={hint ? `${name}-hint` : undefined} />)}
      {hint && <p id={`${name}-hint`} className="mt-1.5 text-[13px] text-muted">{hint}</p>}
    </div>
  );
}
