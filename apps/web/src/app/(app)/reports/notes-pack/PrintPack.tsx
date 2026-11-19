"use client";

/** Prints the pack (or saves it as a PDF from the browser's print dialog). */
export function PrintPack() {
  return <button type="button" className="btn btn-primary print:hidden" onClick={() => window.print()}>Print or save as PDF</button>;
}
