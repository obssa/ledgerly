"use server";

import { refresh } from "next/cache";
import { redirect } from "next/navigation";
import { ApiError, ledgerly } from "@/lib/api";
import { formStateFromError, type FormState } from "@/lib/forms";
import { importKind, type ImportResult } from "@/lib/imports";
import { getSession } from "@/lib/session";
import { statementFrom } from "@/lib/statements";
import { spreadsheetFrom } from "@/lib/xlsxRead";

export type ImportState = FormState & { result?: ImportResult };

/** Module 1: checks a CSV file, or imports it (all rows or none); the API applies the same rules as adding by hand. */
export async function runImport(_: ImportState | undefined, form: FormData): Promise<ImportState> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/company/resume");
  // An Excel workbook is read here (Module 6); a CSV file or pasted lines as before.
  const file = (await spreadsheetFrom(form)) ?? (await statementFrom(form));
  if ("error" in file) {
    return { error: file.error.replace("statement", "file").replace("your bank or wallet exported", "a spreadsheet saved as") };
  }
  try {
    const result = await ledgerly.importRows(importKind(form.get("kind")), file.csv, form.get("mode") !== "import", { token, companyId });
    if (result.added > 0) refresh();
    return { result };
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/sign-in");
    if (error instanceof ApiError && error.status === 403) return { error: "Your role cannot add these." };
    return formStateFromError(error);
  }
}
