"use client";

import Link from "@/components/Link";
import { useActionState } from "react";
import { beginAuthenticator, confirmAuthenticator } from "@/app/(auth)/actions";

/**
 * Three steps on one screen: start, scan the QR code and type the first code, then the recovery codes, which
 * are shown exactly once.
 */
export function AuthenticatorSetup() {
  const [setup, begin, starting] = useActionState(beginAuthenticator, undefined);
  const [result, confirm, confirming] = useActionState(confirmAuthenticator, undefined);

  if (result?.recoveryCodes) {
    return (
      <div>
        <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">
          Your authenticator app is set up. From now on, signing in asks for a code.
        </p>
        <h2 className="mt-5 text-[17px] font-bold">Save your recovery codes</h2>
        <p className="mt-1.5 text-sm text-muted">
          Each code signs you in once if you lose your phone. They are shown only now: print them or keep them in a
          password manager.
        </p>
        <ul className="num mt-4 grid max-w-[360px] grid-cols-2 gap-x-8 gap-y-1.5 rounded-xl border border-line bg-[#f7faf8] p-4 text-[15px] font-semibold">
          {result.recoveryCodes.map((code) => <li key={code}>{code}</li>)}
        </ul>
        <Link href="/" className="btn btn-primary mt-5">I have saved them</Link>
      </div>
    );
  }

  if (!setup?.qr) {
    return (
      <form action={begin}>
        {setup?.error && <p role="alert" className="mb-4 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{setup.error}</p>}
        <p className="text-sm text-muted">
          You need an authenticator app on your phone, such as Google Authenticator, Microsoft Authenticator or
          Authy.
        </p>
        <button type="submit" className="btn btn-primary mt-4" disabled={starting}>
          {starting ? "Please wait…" : "Set up authenticator app"}
        </button>
      </form>
    );
  }

  return (
    <div className="flex flex-wrap items-start gap-8">
      <div>
        <h2 className="text-[17px] font-bold">1. Scan this code</h2>
        <p className="mt-1.5 max-w-[300px] text-sm text-muted">In your authenticator app, add an account and scan the code.</p>
        {/* eslint-disable-next-line @next/next/no-img-element -- a data: address made on our server, nothing to optimise */}
        <img src={setup.qr} alt="QR code for your authenticator app" width={200} height={200} className="mt-3 rounded-xl border border-line bg-white p-2" />
        <p className="mt-3 max-w-[300px] text-[13px] text-muted">
          Cannot scan? Type this key into the app instead:
          <span className="num mt-1 block break-all font-semibold text-ink">{setup.secret?.match(/.{1,4}/g)?.join(" ")}</span>
        </p>
      </div>
      <form action={confirm} className="min-w-[260px] flex-1" noValidate>
        <h2 className="text-[17px] font-bold">2. Type the code it shows</h2>
        {result?.error && <p role="alert" className="mt-3 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{result.error}</p>}
        <label className="field-label mt-3" htmlFor="code">Six-digit code</label>
        <input className="field num max-w-[200px]" id="code" name="code" type="text" inputMode="numeric" autoComplete="one-time-code" required aria-invalid={Boolean(result?.error) || undefined} />
        <div>
          <button type="submit" className="btn btn-primary mt-4" disabled={confirming}>
            {confirming ? "Checking…" : "Turn on"}
          </button>
        </div>
      </form>
    </div>
  );
}
