"use client";

import { useActionState, useState } from "react";
import type { Account, Dimension, FixedAsset } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import type { FormState } from "@/lib/forms";
import { disposeAsset, impairAsset, revalueAsset, runDepreciation, saveAsset } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

type Options = { assetAccounts: Account[]; depreciationAccounts: Account[]; expenseAccounts: Account[]; locations: Dimension[] };

/** Register an asset, or edit one. Once depreciated, only its name, category, location and keeper change. */
export function AssetForm({ asset, options, locked }: { asset?: FixedAsset; options: Options; locked?: boolean }) {
  const [state, action, pending] = useActionState(saveAsset, undefined);
  const [method, setMethod] = useState(asset?.method ?? "straight_line");
  const [taxMethod, setTaxMethod] = useState<string>(asset?.tax?.method ?? "");
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  const pick = (list: Account[], code: string) => list.find((a) => a.code === code)?.id ?? list[0]?.id ?? "";
  const input = (name: string, label: string, value: string | number | null | undefined, opts: { type?: string; hint?: string; fixed?: boolean } = {}) => (
    <div className="min-w-0">
      <label className="field-label" htmlFor={name}>{label}</label>
      <input className={`field ${bad(name) ? "!border-bad" : ""}`} id={name} name={name} type={opts.type ?? "text"} defaultValue={value ?? ""} readOnly={opts.fixed && locked}
        inputMode={opts.type === undefined && /cost|value|depreciation|rate|months/.test(name) ? "decimal" : undefined} />
      {opts.hint && <p className="mt-1 text-[12px] text-muted">{opts.hint}</p>}
    </div>
  );
  const select = (name: string, label: string, list: Account[], value: string) => (
    <div className="min-w-0">
      <label className="field-label" htmlFor={name}>{label}</label>
      <select className={`field ${bad(name) ? "!border-bad" : ""}`} id={name} name={name} defaultValue={value} disabled={locked}>
        {list.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
      </select>
      {locked && <input type="hidden" name={name} value={value} />}
    </div>
  );
  return (
    <form action={action} noValidate className="grid gap-4">
      <Message state={state} />
      {asset && <input type="hidden" name="id" value={asset.id} />}
      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3">
        {input("code", "Code (tag number)", asset?.code)}
        {input("name", "Name", asset?.name)}
        {input("category", "Category", asset?.category, { hint: "Such as vehicles, IT, furniture" })}
        <div>
          <label className="field-label" htmlFor="location_id">Location</label>
          <select className="field" id="location_id" name="location_id" defaultValue={asset?.location?.id ?? ""}>
            <option value="">—</option>
            {options.locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
          </select>
        </div>
        {input("custodian", "Kept by", asset?.custodian)}
      </div>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3">
        {input("acquired_on", "Acquired on", asset?.acquired_on ?? todayIn(), { type: "date", fixed: true })}
        {input("cost", "Cost", asset?.cost, { hint: "As in the ledger, from its bill or the opening balances", fixed: true })}
        {input("residual_value", "Residual value", asset?.residual_value, { fixed: true })}
        <div>
          <label className="field-label" htmlFor="method">Depreciation</label>
          <select className="field" id="method" name="method" value={method} onChange={(e) => setMethod(e.target.value as typeof method)} disabled={locked}>
            <option value="straight_line">Straight line</option>
            <option value="reducing_balance">Reducing balance</option>
          </select>
          {locked && <input type="hidden" name="method" value={method} />}
        </div>
        {method === "straight_line"
          ? input("useful_life_months", "Useful life (months)", asset?.useful_life_months, { fixed: true })
          : input("annual_rate", "Annual rate (%)", asset?.annual_rate ? String(Number(asset.annual_rate) * 100) : "", { fixed: true })}
        {input("depreciation_starts_on", "Depreciated from", asset?.depreciation_starts_on, { type: "date", hint: "Empty: the month after it is acquired", fixed: true })}
        {input("opening_depreciation", "Depreciated before Evenbooks", asset?.opening_depreciation, { hint: "For an asset brought in with the opening balances", fixed: true })}
      </div>
      <fieldset className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3 rounded-xl border border-line p-3">
        <legend className="px-1 text-[13px] font-semibold">For tax (a schedule beside the books: nothing posts)</legend>
        <div>
          <label className="field-label" htmlFor="tax_method">Tax depreciation</label>
          <select className="field" id="tax_method" name="tax_method" value={taxMethod} onChange={(e) => setTaxMethod(e.target.value)}>
            <option value="">As in the books</option>
            <option value="straight_line">Straight line</option>
            <option value="reducing_balance">Reducing balance</option>
          </select>
        </div>
        {taxMethod === "straight_line" && input("tax_useful_life_months", "Over (months)", asset?.tax?.useful_life_months)}
        {taxMethod === "reducing_balance" && input("tax_annual_rate", "Tax rate (%)", asset?.tax?.annual_rate ? String(Number(asset.tax.annual_rate) * 100) : "",
          { hint: "As your tax adviser gives it" })}
        {taxMethod !== "" && input("tax_opening_depreciation", "Allowed for tax before Evenbooks", asset?.tax?.opening_depreciation)}
      </fieldset>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3">
        {select("asset_account_id", "Kept on", options.assetAccounts, asset?.asset_account.id ?? pick(options.assetAccounts, "1500"))}
        {select("depreciation_account_id", "Accumulated depreciation", options.depreciationAccounts, asset?.depreciation_account_id ?? pick(options.depreciationAccounts, "1590"))}
        {select("expense_account_id", "Depreciation expense", options.expenseAccounts, asset?.expense_account_id ?? pick(options.expenseAccounts, "6700"))}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Saving…" : asset ? "Save changes" : "Register asset"}</button>
        {locked && <span className="text-[13px] text-muted">It has been depreciated: how it wears out stays as it was.</span>}
      </div>
    </form>
  );
}

/** 092: revalue an asset to its fair value (a valuer's report, a market price); the surplus is kept on an equity account. */
export function RevalueAsset({ id, bookValue, accounts, kept }: { id: string; bookValue: string; accounts: { id: string; code: string; name: string }[]; kept?: string }) {
  const [state, action, pending] = useActionState(revalueAsset, undefined);
  const today = todayIn();
  const usual = kept ?? accounts.find((a) => /revaluation/i.test(a.name))?.id;
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="id" value={id} />
      <p className="text-[13px] text-muted">
        Carried at {bookValue}. A rise goes to the revaluation surplus (other comprehensive income), except where it reverses an earlier fall charged to profit or loss;
        a fall takes this asset&rsquo;s surplus first, then profit or loss.
      </p>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3">
        <div><label className="field-label" htmlFor="rev-date">Date</label><input className="field num" id="rev-date" name="date" type="date" max={today} defaultValue={today} /></div>
        <div><label className="field-label" htmlFor="rev-fair">Fair value</label><input className="field num" id="rev-fair" name="fair_value" inputMode="decimal" /></div>
        <div>
          <label className="field-label" htmlFor="rev-surplus">Surplus kept on</label>
          <select className="field" id="rev-surplus" name="surplus_account_id" defaultValue={usual ?? ""} disabled={Boolean(kept)}>
            <option value="" disabled>Choose an equity account</option>
            {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}
          </select>
          {kept && <input type="hidden" name="surplus_account_id" value={kept} />}
        </div>
      </div>
      <div><label className="field-label" htmlFor="rev-reason">How the fair value was found</label><input className="field" id="rev-reason" name="reason" maxLength={500} placeholder="Valuer's report, 30 June" /></div>
      {accounts.length === 0 && <p className="text-[13px] text-muted">Add an equity account for the surplus first (for example 3400 Revaluation surplus) in the chart of accounts.</p>}
      <button type="submit" className="btn self-start" disabled={pending || accounts.length === 0}>{pending ? "Posting…" : "Revalue"}</button>
    </form>
  );
}

/** 091: write an asset down when it is worth less than its carrying amount (damaged, idle, obsolete). */
export function ImpairAsset({ id, bookValue }: { id: string; bookValue: string }) {
  const [state, action, pending] = useActionState(impairAsset, undefined);
  const today = todayIn();
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="id" value={id} />
      <p className="text-[13px] text-muted">Carried at {bookValue}. If it is worth less (damaged, idle, obsolete), write it down by the difference.</p>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-3">
        <div><label className="field-label" htmlFor="imp-date">Date</label><input className="field num" id="imp-date" name="date" type="date" max={today} defaultValue={today} /></div>
        <div><label className="field-label" htmlFor="imp-amount">Write down by</label><input className="field num" id="imp-amount" name="amount" inputMode="decimal" /></div>
      </div>
      <div><label className="field-label" htmlFor="imp-reason">Why it is worth less</label><input className="field" id="imp-reason" name="reason" maxLength={500} placeholder="Dropped; board replaced" /></div>
      <button type="submit" className="btn self-start" disabled={pending}>{pending ? "Posting…" : "Write it down"}</button>
    </form>
  );
}

/** Sell or scrap an asset: the date, what it was sold for (nothing if scrapped) and where the money went. */
export function DisposeAsset({ id, bookValue, banks }: { id: string; bookValue: string; banks: { id: string; code: string; name: string }[] }) {
  const [state, action, pending] = useActionState(disposeAsset, undefined);
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  return (
    <form action={action} noValidate className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="id" value={id} />
      <div className="flex flex-wrap items-end gap-2.5">
        <div><label className="field-label" htmlFor="dispose_date">Date it went</label><input className={`field ${bad("date") ? "!border-bad" : ""}`} id="dispose_date" name="date" type="date" max={todayIn()} defaultValue={todayIn()} /></div>
        <div><label className="field-label" htmlFor="proceeds">Sold for</label><input className={`field num w-[140px] ${bad("proceeds") ? "!border-bad" : ""}`} id="proceeds" name="proceeds" inputMode="decimal" placeholder="0.00 if scrapped" /></div>
        <div className="min-w-[200px]">
          <label className="field-label" htmlFor="bank_account_id">Paid into</label>
          <select className={`field ${bad("bank_account_id") ? "!border-bad" : ""}`} id="bank_account_id" name="bank_account_id" defaultValue="">
            <option value="">— (scrapped)</option>
            {banks.map((b) => <option key={b.id} value={b.id}>{b.code} {b.name}</option>)}
          </select>
        </div>
        <div className="min-w-[200px] flex-1"><label className="field-label" htmlFor="reason">Why (optional)</label><input className="field" id="reason" name="reason" maxLength={500} /></div>
        <button type="submit" className="btn" disabled={pending}>{pending ? "Posting…" : "Dispose of it"}</button>
      </div>
      <p className="text-[13px] text-muted">Book value now {bookValue}. Post this month&apos;s depreciation first if it is due; the gain or loss against the book value posts to Gain or loss on disposal of assets.</p>
    </form>
  );
}

/** Post the depreciation for a month end. */
export function RunDepreciation({ periodEnd, total }: { periodEnd: string; total: string }) {
  const [state, action, pending] = useActionState(runDepreciation, undefined);
  return (
    <form action={action} className="grid gap-3">
      <Message state={state} />
      <input type="hidden" name="period_end" value={periodEnd} />
      <div><button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Posting…" : `Post depreciation of ${total} for the month to ${periodEnd}`}</button></div>
    </form>
  );
}
