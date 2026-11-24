"use client";

import { useEffect } from "react";
import { isStaleBuild, reloadOnceForStaleBuild } from "@/lib/stale";

/**
 * The last resort, when even the app's frame failed. A tab left open across a release reloads itself once;
 * otherwise a plain page with a way back (it has its own <html>, so no app styles can be assumed).
 */
export default function GlobalError({ error }: { error: Error & { digest?: string } }) {
  useEffect(() => {
    if (isStaleBuild(error)) reloadOnceForStaleBuild(sessionStorage, () => window.location.reload());
  }, [error]);

  return (
    <html lang="en">
      <body style={{ margin: 0, minHeight: "100vh", display: "grid", placeItems: "center", background: "#f5f7f6", color: "#0e1a17", fontFamily: "Inter, system-ui, sans-serif" }}>
        <main role="alert" style={{ maxWidth: 440, padding: 28, textAlign: "center", background: "#fff", border: "1px solid #e2e7e4", borderRadius: 16 }}>
          <h1 style={{ fontSize: 20, margin: 0 }}>This page didn&apos;t load</h1>
          <p style={{ fontSize: 14, color: "#56665f" }}>
            {isStaleBuild(error) ? "Ledgerly was updated while this tab was open. Reload to get the new version." : "Something went wrong. Nothing you entered was changed."}
          </p>
          <button type="button" onClick={() => window.location.reload()}
            style={{ marginTop: 8, padding: "10px 16px", borderRadius: 10, border: "1px solid #0f766e", background: "#0f766e", color: "#fff", fontWeight: 600, cursor: "pointer" }}>
            Reload the page
          </button>
          {error.digest ? (
            <p style={{ fontSize: 12, color: "#627069" }}>Reference {error.digest}</p>
          ) : (
            <p style={{ fontSize: 11.5, color: "#627069", fontFamily: "monospace", textAlign: "left", wordBreak: "break-word" }}>
              Details: {`${error.name}: ${error.message}`.slice(0, 300)} · {typeof window !== "undefined" ? window.location.pathname : ""}
            </p>
          )}
        </main>
      </body>
    </html>
  );
}
