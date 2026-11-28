"use client";

import Link from "@/components/Link";
import { useEffect, useId, useRef, useState, useTransition, type InputHTMLAttributes, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { quickAddContact, quickAddItem, type Added } from "@/app/(app)/quick-add-actions";
import type { PaymentTerm } from "@/lib/api";
import type { ContactOption, ItemOption } from "@/lib/options";

/** The value of the "+ Add new" choice at the top of a customer, supplier or product/service list. */
export const ADD_NEW = "__add_new__";

/**
 * QuickBooks' "+ Add new": a small dialog over the form that adds what is missing and hands it back, chosen.
 * It is rendered at the end of the page, not inside the document's form (a form cannot hold another form), and its
 * submit stops here so the document is not saved with it.
 */
function QuickDialog({ title, intro, submit, pending, state, onSave, onClose, more, children }: {
  title: string; intro: string; submit: string; pending: boolean; state: Added<unknown> | undefined;
  onSave: (form: FormData) => void; onClose: () => void; more?: ReactNode; children: ReactNode;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useId();
  useEffect(() => {
    const d = dialog.current;
    if (!d || d.open) return;
    d.showModal();
    // showModal focuses the first control, the close button; the name is where a person starts.
    d.querySelector<HTMLInputElement>("input[name=name]")?.focus();
  }, []);
  return createPortal(
    <dialog ref={dialog} aria-labelledby={heading} onClose={onClose}
      className="m-auto max-h-[calc(100dvh-32px)] w-[min(560px,calc(100vw-32px))] overflow-hidden rounded-2xl border border-line bg-surface p-0 text-ink shadow-[0_24px_64px_rgba(12,36,32,0.28)] backdrop:bg-[#0c2420]/40 backdrop:backdrop-blur-[2px]">
      <form noValidate className="flex max-h-[calc(100dvh-32px)] flex-col" onSubmit={(e) => {
        e.preventDefault();
        e.stopPropagation();   // a portal still passes React events to the document's form; this one is not its submit
        onSave(new FormData(e.currentTarget));
      }}>
        <div className="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
          <div>
            <h2 id={heading} className="text-[17px] font-bold">{title}</h2>
            <p className="mt-0.5 text-[13px] text-muted">{intro}</p>
          </div>
          <button type="button" className="rounded-md px-2 text-xl leading-none text-muted hover:text-ink" aria-label="Close" onClick={() => dialog.current?.close()}>×</button>
        </div>
        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-5 py-4">
          {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
          <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4">{children}</div>
        </div>
        <div className="flex flex-wrap items-center gap-3 border-t border-line bg-canvas px-5 py-3">
          {more}
          <span className="ml-auto" />
          <button type="button" className="btn" onClick={() => dialog.current?.close()}>Cancel</button>
          <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : submit}</button>
        </div>
      </form>
    </dialog>,
    document.body,
  );
}

function Text({ id, name, label, state, wide, ...rest }: { id: string; name: string; label: string; state: Added<unknown> | undefined; wide?: boolean } & InputHTMLAttributes<HTMLInputElement>) {
  const bad = Boolean(state?.fields?.[name]);
  return (
    <div className={wide ? "col-span-full" : undefined}>
      <label className="field-label" htmlFor={id}>{label}</label>
      <input className={`field ${bad ? "!border-bad" : ""}`} id={id} name={name} aria-invalid={bad || undefined} {...rest} />
      {bad && <p className="mt-1 text-[12px] text-bad">{state?.fields?.[name]?.[0]}</p>}
    </div>
  );
}

/** A new customer (sales forms) or supplier (purchase forms): the details a document needs; the rest on their page. */
export function AddContact({ side, terms, currencies, baseCurrency, onAdded, onClose }: {
  side: "customer" | "supplier"; terms: PaymentTerm[]; currencies: string[]; baseCurrency: string;
  onAdded: (contact: ContactOption) => void; onClose: () => void;
}) {
  const [state, setState] = useState<Added<ContactOption>>();
  const [pending, start] = useTransition();
  const id = useId();
  const active = terms.filter((t) => t.is_active);
  const usual = active.find((t) => t.kind === "days" && t.days === 30)?.id ?? "";
  const who = side === "customer" ? "customer" : "supplier";
  return (
    <QuickDialog title={`New ${who}`} intro={`Added to your ${who}s and chosen on this form.`} submit={`Save ${who}`} pending={pending} state={state} onClose={onClose}
      more={<Link href={`/${who}s/new`} target="_blank" className="text-[13px] font-semibold text-primary hover:underline">All details ↗</Link>}
      onSave={(form) => start(async () => {
        const result = await quickAddContact(side, form);
        if (result.added) onAdded(result.added);
        else setState(result);
      })}>
      <Text id={`${id}-name`} name="name" label={side === "customer" ? "Customer display name" : "Supplier display name"} state={state} wide required maxLength={200} />
      <Text id={`${id}-email`} name="email" label="Email" state={state} type="email" inputMode="email" maxLength={190} />
      <Text id={`${id}-phone`} name="phone" label="Phone" state={state} type="tel" maxLength={30} />
      <Text id={`${id}-tin`} name="tax_id" label="TIN" state={state} maxLength={30} />
      {active.length > 0 && (
        <div>
          <label className="field-label" htmlFor={`${id}-terms`}>Terms</label>
          <select className="field" id={`${id}-terms`} name="payment_terms_id" defaultValue={usual}>
            {!usual && <option value="">None (30 days)</option>}
            {active.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
        </div>
      )}
      <Text id={`${id}-line1`} name="address.line1" label={side === "customer" ? "Billing address" : "Address"} state={state} maxLength={200} />
      <Text id={`${id}-city`} name="address.city" label="City" state={state} maxLength={100} />
      {currencies.length > 1 && (
        <div>
          <label className="field-label" htmlFor={`${id}-currency`}>{side === "customer" ? "Invoice currency" : "Bill currency"}</label>
          <select className="field" id={`${id}-currency`} name="currency" defaultValue="">
            <option value="">The company&apos;s currency ({baseCurrency})</option>
            {currencies.filter((c) => c !== baseCurrency).map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
      )}
    </QuickDialog>
  );
}

const ITEM_TYPES: [string, string][] = [["service", "Service"], ["non_inventory", "Non-stock product"], ["inventory", "Stock product"]];

/** A new product or service: what it is, its price (or cost) and where it posts; units and stock on its own page. */
export function AddItem({ side, accounts, account, onAdded, onClose }: {
  side: "sale" | "purchase"; accounts: { id: string; code: string; name: string }[]; account: string;
  onAdded: (item: ItemOption) => void; onClose: () => void;
}) {
  const [state, setState] = useState<Added<ItemOption>>();
  const [pending, start] = useTransition();
  const [type, setType] = useState("service");
  const id = useId();
  return (
    <QuickDialog title="New product or service" intro="Added to Products and services and put on this line." submit="Save product or service" pending={pending} state={state} onClose={onClose}
      more={<Link href="/products/new" target="_blank" className="text-[13px] font-semibold text-primary hover:underline">All details ↗</Link>}
      onSave={(form) => start(async () => {
        const result = await quickAddItem(side, form);
        if (result.added) onAdded(result.added);
        else setState(result);
      })}>
      <Text id={`${id}-name`} name="name" label="Name" state={state} wide required maxLength={200} />
      <div>
        <label className="field-label" htmlFor={`${id}-type`}>Type</label>
        <select className="field" id={`${id}-type`} name="type" value={type} onChange={(e) => setType(e.target.value)}>
          {ITEM_TYPES.map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </div>
      <Text id={`${id}-sku`} name="sku" label="SKU (optional)" state={state} maxLength={60} />
      <Text id={`${id}-price`} name="price" label={side === "sale" ? "Sales price" : "Cost"} state={state} inputMode="decimal" placeholder="0.00" />
      {accounts.length > 0 && (
        <div>
          <label className="field-label" htmlFor={`${id}-account`}>{side === "sale" ? "Income account" : "Expense account"}</label>
          <select className={`field ${state?.fields?.account_id ? "!border-bad" : ""}`} id={`${id}-account`} name="account_id" defaultValue={account}>
            {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
          </select>
        </div>
      )}
      {type === "inventory" && (
        <p className="col-span-full text-[13px] text-muted">Its stock starts at zero. Add opening stock, units and reorder levels on its page under Products and services.</p>
      )}
    </QuickDialog>
  );
}
