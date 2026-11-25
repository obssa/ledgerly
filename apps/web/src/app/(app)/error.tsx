"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { isStaleBuild, reloadOnceForStaleBuild, staleReloadAllowed } from "@/lib/stale";

/**
 * A page that failed, inside the app (the menu stays). A tab left open across a release reloads itself once to
 * pick up the new build; anything else says what happened and offers to try again, without losing the menu.
 */
export default function AppError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  // Decided once, when the error first shows: reload for a stale build unless that was just tried.
  const [reloading] = useState(() => typeof window !== "undefined" && isStaleBuild(error) && staleReloadAllowed(window.sessionStorage));
  useEffect(() => {
    if (reloading) reloadOnceForStaleBuild(window.sessionStorage, () => window.location.reload());
  }, [reloading]);

  if (reloading) {
    return <p role="status" className="mt-10 text-center text-sm text-muted">Evenbooks was updated. Loading the new version…</p>;
  }
  return (
    <section role="alert" className="mx-auto mt-10 max-w-[520px] rounded-2xl border border-line bg-surface p-7 text-center shadow-card">
      {/* The failed page never sent its own title: this one says what happened (React puts it in the head). */}
      <title>This page didn&apos;t load · Evenbooks</title>
      <h1 className="text-[20px] font-bold tracking-[-0.01em]">This page didn&apos;t load</h1>
      <p className="mt-2 text-sm text-muted">
        {isStaleBuild(error)
          ? "Evenbooks was updated while this tab was open. Reload to get the new version."
          : "Something went wrong getting this page. Nothing you entered was changed. Try again, or go back to the overview."}
      </p>
      <div className="mt-5 flex flex-wrap justify-center gap-2">
        <button type="button" className="btn btn-primary" onClick={() => (isStaleBuild(error) ? window.location.reload() : reset())}>Try again</button>
        <button type="button" className="btn" onClick={() => window.location.reload()}>Reload the page</button>
        <Link href="/" className="btn">Overview</Link>
      </div>
      {error.digest ? (
        <p className="num mt-4 text-[12px] text-faint">Reference {error.digest}</p>
      ) : (
        // An error in the browser (a server error carries a reference instead, its details kept on the server).
        <p className="mt-4 break-words text-left font-mono text-[11.5px] text-faint">Details: {`${error.name}: ${error.message}`.slice(0, 300)}</p>
      )}
    </section>
  );
}
