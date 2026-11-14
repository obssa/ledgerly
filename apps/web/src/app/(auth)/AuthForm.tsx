"use client";

import { useActionState } from "react";
import type { FormState } from "@/lib/forms";

interface Field {
  name: string;
  label: string;
  type: string;
  autoComplete: string;
  hint?: string;
}

/**
 * One form for the account screens (sign in, sign up, code, passwords): the fields, the Server Function, and
 * the API's messages. `hidden` carries values the person does not type, such as the token from an emailed link.
 */
export function AuthForm({
  action,
  fields,
  submit,
  hidden,
}: {
  action: (state: FormState | undefined, form: FormData) => Promise<FormState>;
  fields: Field[];
  submit: string;
  hidden?: Record<string, string>;
}) {
  const [state, formAction, pending] = useActionState(action, undefined);

  return (
    <form action={formAction} className="mt-5 grid gap-4" noValidate>
      {state?.error && (
        <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">
          {state.error}
        </p>
      )}
      {state?.notice && (
        <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">
          {state.notice}
        </p>
      )}
      {Object.entries(hidden ?? {}).map(([name, value]) => <input key={name} type="hidden" name={name} value={value} />)}
      {fields.map((f) => {
        const invalid = Boolean(state?.fields?.[f.name]);
        return (
          <div key={f.name}>
            <label className="field-label" htmlFor={f.name}>{f.label}</label>
            <input
              className={`field ${invalid ? "!border-bad" : ""}`}
              id={f.name}
              name={f.name}
              type={f.type}
              autoComplete={f.autoComplete}
              required
              aria-invalid={invalid || undefined}
              aria-describedby={f.hint ? `${f.name}_hint` : undefined}
            />
            {f.hint && <p id={`${f.name}_hint`} className="mt-1.5 text-[13px] text-muted">{f.hint}</p>}
          </div>
        );
      })}
      <button type="submit" className="btn btn-primary mt-1 justify-center" disabled={pending}>
        {pending ? "Please wait…" : submit}
      </button>
    </form>
  );
}
