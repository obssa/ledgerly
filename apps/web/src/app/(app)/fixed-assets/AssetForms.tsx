"use client";

import { useActionState, useState } from "react";
import type { Account, Dimension, FixedAsset } from "@/lib/api";
import { todayIn } from "@/lib/dates";
import type { FormState } from "@/lib/forms";
import { runDepreciation, saveAsset } from "./actions";

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
        {input("opening_depreciation", "Depreciated before Ledgerly", asset?.opening_depreciation, { hint: "For an asset brought in with the opening balances", fixed: true })}
      </div>
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
