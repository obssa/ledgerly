"use client";

import { useActionState, useId } from "react";
import type { Supplier } from "@/lib/api";
import { CURRENCIES } from "@/lib/decimal";
import type { FormState } from "@/lib/forms";
import { WITHHOLDING_SHOWN } from "@/lib/suppliers";
import { createSupplier, saveSupplier, setSupplierActive } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-4 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-4 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

/** New supplier (no `supplier`) or edit. Read-only when the role cannot edit; the API checks again. */
export function SupplierForm({ supplier, accounts, readOnly = false }: {
  supplier?: Supplier; accounts: { id: string; code: string; name: string }[]; readOnly?: boolean;
}) {
  const [state, action, pending] = useActionState(supplier ? saveSupplier : createSupplier, undefined);
  const bad = (name: string) => Boolean(state?.fields && Object.keys(state.fields).some((k) => k === name || k.startsWith(`${name}.`)));
  const v = (name: keyof Supplier) => (supplier?.[name] as string | null | undefined) ?? "";
  const payout = supplier?.payout ?? {};

  return (
    <form action={action} noValidate>
      <Message state={state} />
      {supplier && <input type="hidden" name="id" value={supplier.id} />}
      <fieldset disabled={readOnly} className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-[18px]">
        <legend className="sr-only">Supplier details</legend>
        <Field name="name" label="Name" defaultValue={v("name")} required invalid={bad("name")} />
        <Field name="tax_id" label="Tax ID (TIN)" defaultValue={v("tax_id")} invalid={bad("tax_id")} />
        <Field name="email" label="Email" type="email" defaultValue={v("email")} invalid={bad("email")} />
        <Field name="phone" label="Phone" type="tel" defaultValue={v("phone")} invalid={bad("phone")} />
        <Field name="payment_terms_days" label="Payment terms (days)" type="number" defaultValue={String(supplier?.payment_terms_days ?? 30)} invalid={bad("payment_terms_days")} />
        <div>
          <label className="field-label" htmlFor="currency">Bill currency</label>
          <select className="field" id="currency" name="currency" defaultValue={supplier?.currency ?? ""}>
            <option value="">The company&apos;s currency</option>
            {CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
        <div className="sm:col-span-2">
          <label className="field-label" htmlFor="default_expense_account_id">Usual expense account</label>
          <select className={`field ${bad("default_expense_account_id") ? "!border-bad" : ""}`} id="default_expense_account_id" name="default_expense_account_id"
            defaultValue={supplier?.default_expense_account_id ?? ""}>
            <option value="">None: choose on each bill</option>
            {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
          </select>
        </div>
      </fieldset>

      <h3 className="mb-3 mt-6 text-[15px] font-bold">Where to pay them</h3>
      <fieldset disabled={readOnly} className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-[18px]">
        <legend className="sr-only">Payout details</legend>
        <Field name="payout.bank_name" label="Bank" defaultValue={payout.bank_name ?? ""} invalid={bad("payout.bank_name")} />
        <Field name="payout.account_name" label="Account name" defaultValue={payout.account_name ?? ""} invalid={bad("payout.account_name")} />
        <Field name="payout.account_number" label="Account number" defaultValue={payout.account_number ?? ""} invalid={bad("payout.account_number")} />
        <Field name="payout.mobile_money_provider" label="Mobile money" defaultValue={payout.mobile_money_provider ?? ""} hint="For example telebirr or M-PESA" />
        <Field name="payout.mobile_money_number" label="Wallet number" type="tel" defaultValue={payout.mobile_money_number ?? ""} invalid={bad("payout.mobile_money_number")} />
      </fieldset>
      <p className="mt-2 text-[13px] text-muted">A change here is recorded in the audit log with the old and new details.</p>

      <h3 className="mb-3 mt-6 text-[15px] font-bold">Address</h3>
      <fieldset disabled={readOnly} className="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-[18px]">
        <legend className="sr-only">Address</legend>
        <Field name="address.line1" label="Address" defaultValue={supplier?.address?.line1 ?? ""} />
        <Field name="address.city" label="City" defaultValue={supplier?.address?.city ?? ""} />
        <Field name="address.country" label="Country code" defaultValue={supplier?.address?.country ?? ""} hint="Two letters, e.g. ET" invalid={bad("address")} />
      </fieldset>

      <fieldset disabled={readOnly} className="mt-6">
        <legend className="sr-only">Withholding tax</legend>
        <label className="flex items-center gap-2.5 text-sm font-semibold">
          <input type="hidden" name={WITHHOLDING_SHOWN} value="1" />
          <input type="checkbox" name="withholding_exempt" defaultChecked={supplier?.withholding_exempt ?? false} className="size-4" /> Exempt from withholding tax
        </label>
        <div className="mt-3 max-w-[520px]">
          <Field name="withholding_exempt_reason" label="Exemption certificate" defaultValue={supplier?.withholding_exempt_reason ?? ""}
            hint="Without one, withholding is deducted when the bill is paid" invalid={bad("withholding_exempt_reason")} />
        </div>
      </fieldset>

      {!readOnly && (
        <button type="submit" className="btn btn-primary mt-7 min-w-[180px] justify-center" disabled={pending}>
          {pending ? "Saving…" : supplier ? "Save supplier" : "Add supplier"}
        </button>
      )}
    </form>
  );
}

/** Archive or restore: an archived supplier leaves the lists, and keeps every bill and record. */
export function ArchiveSupplier({ supplier }: { supplier: Supplier }) {
  const [state, action, pending] = useActionState(setSupplierActive, undefined);
  return (
    <form action={action}>
      <Message state={state} />
      <input type="hidden" name="id" value={supplier.id} />
      <input type="hidden" name="is_active" value={supplier.is_active ? "false" : "true"} />
      <button type="submit" className="btn" disabled={pending}>{supplier.is_active ? "Archive supplier" : "Restore supplier"}</button>
    </form>
  );
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
