"use client";

import { useActionState, useId } from "react";
import type { Customer } from "@/lib/api";
import { EXEMPT_SHOWN } from "@/lib/customers";
import { CURRENCIES } from "@/lib/decimal";
import type { FormState } from "@/lib/forms";
import { createCustomer, saveCustomer, setCustomerActive } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-4 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-4 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

/** New customer (no `customer`) or edit. Read-only when the role cannot edit; the API checks again. */
export function CustomerForm({ customer, readOnly = false }: { customer?: Customer; readOnly?: boolean }) {
  const [state, action, pending] = useActionState(customer ? saveCustomer : createCustomer, undefined);
  const bad = (name: string) => Boolean(state?.fields && Object.keys(state.fields).some((k) => k === name || k.startsWith(`${name}.`)));
  const v = (name: keyof Customer) => (customer?.[name] as string | null | undefined) ?? "";

  return (
    <form action={action} noValidate>
      <Message state={state} />
      {customer && <input type="hidden" name="id" value={customer.id} />}
      <fieldset disabled={readOnly} className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-[18px]">
        <legend className="sr-only">Customer details</legend>
        <Field name="name" label="Name" defaultValue={v("name")} required invalid={bad("name")} />
        <Field name="tax_id" label="Tax ID (TIN)" defaultValue={v("tax_id")} invalid={bad("tax_id")} />
        <Field name="email" label="Email" type="email" defaultValue={v("email")} invalid={bad("email")} />
        <Field name="phone" label="Phone" type="tel" defaultValue={v("phone")} invalid={bad("phone")} />
        <Field name="payment_terms_days" label="Payment terms (days)" type="number" defaultValue={String(customer?.payment_terms_days ?? 30)} invalid={bad("payment_terms_days")} />
        <Field name="credit_limit" label="Credit limit" defaultValue={customer?.credit_limit ?? ""} hint="Empty means no limit. Going over it warns, it does not block." invalid={bad("credit_limit")} />
        <div>
          <SelectLabel id="currency" label="Invoice currency" />
          <select className="field" id="currency" name="currency" defaultValue={customer?.currency ?? ""}>
            <option value="">The company&apos;s currency</option>
            {CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
      </fieldset>

      <h3 className="mb-3 mt-6 text-[15px] font-bold">Billing address</h3>
      <fieldset disabled={readOnly} className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-[18px]">
        <legend className="sr-only">Billing address</legend>
        <Field name="address.line1" label="Address" defaultValue={customer?.address?.line1 ?? ""} />
        <Field name="address.city" label="City" defaultValue={customer?.address?.city ?? ""} />
        <Field name="address.region" label="Region" defaultValue={customer?.address?.region ?? ""} />
        <Field name="address.country" label="Country code" defaultValue={customer?.address?.country ?? ""} hint="Two letters, e.g. ET" invalid={bad("address")} />
      </fieldset>

      <fieldset disabled={readOnly} className="mt-6">
        <legend className="sr-only">Tax exemption</legend>
        <label className="flex items-center gap-2.5 text-sm font-semibold">
          <input type="hidden" name={EXEMPT_SHOWN} value="1" />
          <input type="checkbox" name="tax_exempt" defaultChecked={customer?.tax_exempt ?? false} className="size-4" /> Exempt from VAT
        </label>
        <div className="mt-3 max-w-[520px]">
          <Field name="tax_exempt_reason" label="Reason for the exemption" defaultValue={customer?.tax_exempt_reason ?? ""} hint="For example the certificate number" invalid={bad("tax_exempt_reason")} />
        </div>
      </fieldset>

      {!readOnly && (
        <button type="submit" className="btn btn-primary mt-7 min-w-[180px] justify-center" disabled={pending}>
          {pending ? "Saving…" : customer ? "Save customer" : "Add customer"}
        </button>
      )}
    </form>
  );
}

/** Archive or restore: an archived customer leaves the lists, and keeps every invoice and record. */
export function ArchiveCustomer({ customer }: { customer: Customer }) {
  const [state, action, pending] = useActionState(setCustomerActive, undefined);
  return (
    <form action={action}>
      <Message state={state} />
      <input type="hidden" name="id" value={customer.id} />
      <input type="hidden" name="is_active" value={customer.is_active ? "false" : "true"} />
      <button type="submit" className="btn" disabled={pending}>{customer.is_active ? "Archive customer" : "Restore customer"}</button>
    </form>
  );
}

function SelectLabel({ id, label }: { id: string; label: string }) {
  return <label className="field-label" htmlFor={id}>{label}</label>;
}

function Field({ name, label, type = "text", defaultValue = "", required, invalid, hint }: {
  name: string; label: string; type?: string; defaultValue?: string; required?: boolean; invalid?: boolean; hint?: string;
}) {
  const id = useId();
  return (
    <div>
      <label className="field-label" htmlFor={id}>{label}</label>
      <input className={`field ${invalid ? "!border-bad" : ""}`} id={id} name={name} type={type} defaultValue={defaultValue} required={required}
        aria-invalid={invalid || undefined} aria-describedby={hint ? `${id}-hint` : undefined} />
      {hint && <p id={`${id}-hint`} className="mt-1.5 text-[13px] text-muted">{hint}</p>}
    </div>
  );
}
