"use client";

import { useActionState } from "react";
import { removeAttachment } from "./actions";

/** Take a file off the document: it is kept, marked removed, and no longer listed or opened. */
export function RemoveAttachment({ id }: { id: string }) {
  const [state, action, pending] = useActionState(removeAttachment, undefined);
  return (
    <form action={action} className="inline">
      <input type="hidden" name="id" value={id} />
      <button type="submit" className="text-[12px] font-semibold text-bad hover:underline" disabled={pending}>{pending ? "Removing…" : "Remove"}</button>
      {state?.error && <span role="alert" className="ml-2 text-[12px] text-bad">{state.error}</span>}
    </form>
  );
}
