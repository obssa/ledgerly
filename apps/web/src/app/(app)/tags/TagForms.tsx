"use client";

import { useActionState } from "react";
import type { Tag, TagKind } from "@/lib/api";
import type { FormState } from "@/lib/forms";
import { addTag, changeTag, setTransactionTags } from "./actions";

function Message({ state }: { state: FormState | undefined }) {
  if (state?.error) return <p role="alert" className="mb-2 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>;
  if (state?.notice) return <p role="status" className="mb-2 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>;
  return null;
}

/** 094: name a new tag. */
export function AddTag() {
  const [state, action, pending] = useActionState(addTag, undefined);
  return (
    <form action={action} noValidate>
      <Message state={state} />
      <div className="flex flex-wrap items-end gap-2">
        <div><label className="field-label" htmlFor="tag-name">New tag</label><input className="field" id="tag-name" name="name" maxLength={40} placeholder="Trade fair 2027" /></div>
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Adding…" : "Add"}</button>
      </div>
    </form>
  );
}

/** 094: rename a tag, or turn it off (it stays on what it is on) and on again. */
export function TagRow({ tag }: { tag: Tag }) {
  const [state, rename, renaming] = useActionState(changeTag, undefined);
  const [toggleState, toggle, toggling] = useActionState(changeTag, undefined);
  return (
    <li className="border-t border-line py-2">
      <Message state={state ?? toggleState} />
      <div className="flex flex-wrap items-end gap-2">
        <form action={rename} noValidate className="flex flex-wrap items-end gap-2">
          <input type="hidden" name="id" value={tag.id} />
          <input className="field" name="name" maxLength={40} defaultValue={tag.name} aria-label={`Name of ${tag.name}`} />
          <button type="submit" className="btn" disabled={renaming}>Rename</button>
        </form>
        <form action={toggle}>
          <input type="hidden" name="id" value={tag.id} />
          <input type="hidden" name="is_active" value={tag.is_active ? "0" : "1"} />
          <button type="submit" className="btn" disabled={toggling}>{tag.is_active ? "Turn off" : "Turn on"}</button>
        </form>
        <span className="text-[13px] text-muted">{tag.used} transaction{tag.used === 1 ? "" : "s"}{tag.is_active ? "" : " · off"}</span>
      </div>
    </li>
  );
}

/** 094: the tags on a transaction, chosen from the ones that are on (a tag turned off but still on it stays ticked). */
export function TagPicker({ kind, id, chosen, tags }: { kind: TagKind; id: string; chosen: { id: string; name: string }[]; tags: { id: string; name: string }[] }) {
  const [state, action, pending] = useActionState(setTransactionTags, undefined);
  const on = new Set(chosen.map((t) => t.id));
  const choices = [...tags, ...chosen.filter((t) => !tags.some((x) => x.id === t.id))];
  return (
    <form action={action} noValidate className="grid gap-2">
      <Message state={state} />
      <input type="hidden" name="kind" value={kind} />
      <input type="hidden" name="id" value={id} />
      <div className="flex flex-wrap gap-x-4 gap-y-1.5 text-sm">
        {choices.map((t) => (
          <label key={t.id} className="flex items-center gap-1.5"><input type="checkbox" name="tag_ids" value={t.id} defaultChecked={on.has(t.id)} />{t.name}</label>
        ))}
      </div>
      <div><button type="submit" className="btn" disabled={pending}>{pending ? "Saving…" : "Save tags"}</button></div>
    </form>
  );
}
