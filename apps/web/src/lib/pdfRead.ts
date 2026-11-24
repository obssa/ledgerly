import "server-only";
import { rowsToCsv } from "./xlsxRead";
import { MAX_STATEMENT_BYTES } from "./statements";

/*
 * Module 5: a bank statement saved as a PDF (the one internet banking offers for download), read into the CSV the API
 * reads by its column names. Only PDFs that carry their text can be read; a scan or a photo has none, and is refused
 * with what to do instead.
 *
 * A PDF has no table in it, only pieces of text, each at a place on the page. So the table is rebuilt: pieces on the
 * same line form a row; the row with the bank's column names (a date, and a debit, credit, amount or balance) is the
 * header; every later piece goes under the column name it lines up with. A row whose date column holds a date starts a
 * statement line; a row without one, just under it, is the rest of its description (banks wrap long narrations).
 * Everything above the header (the letterhead, the account number) and the page footers are left out. The API then
 * checks every line as it checks a CSV: the date, the amount, and that the line was not imported before.
 */

/** One piece of text and where it is: x from the left edge, y from the bottom, both in points; w its width. */
export interface PdfText { page: number; x: number; y: number; w: number; text: string }

export class PdfStatementError extends Error {}

/** At most this many pages are read: a statement of a few months. */
export const MAX_PDF_PAGES = 60;

const DATE = /^(\d{4}[-/.]\d{1,2}[-/.]\d{1,2}|\d{1,2}[-/.]\d{1,2}[-/.]\d{2,4}|\d{1,2}[ -]?[A-Za-z]{3,9}[ ,-]*\d{2,4}|[A-Za-z]{3,9} \d{1,2},? \d{4})\b/;
const DATE_HEAD = /\b(date|posted|posting)\b/i;
const MONEY_HEAD = /\b(debit|debits|credit|credits|withdrawals?|deposits?|amount|balance|money out|money in|paid out|paid in|dr|cr)\b/i;

const cellsOf = (items: PdfText[]): PdfText[] => {
  // Pieces close together on a line are one cell ("Transfer", "to", "Selam" set word by word).
  const cells: PdfText[] = [];
  for (const it of [...items].sort((a, b) => a.x - b.x)) {
    const last = cells.at(-1);
    if (last && it.x - (last.x + last.w) < 4) {
      last.text = `${last.text}${it.x - (last.x + last.w) > 0.8 ? " " : ""}${it.text}`;
      last.w = it.x + it.w - last.x;
    } else {
      cells.push({ ...it });
    }
  }
  return cells;
};

/** The lines of each page, top to bottom, each a list of cells left to right. */
export function rowsOf(items: PdfText[]): { page: number; y: number; cells: PdfText[] }[] {
  const pages = new Map<number, PdfText[]>();
  for (const it of items) {
    const list = pages.get(it.page) ?? [];
    // Some PDFs set a whole line as one piece, its columns padded with spaces: split it where two or more spaces
    // are, each part placed by its share of the characters.
    const perChar = it.text.length > 0 ? it.w / it.text.length : 0;
    for (const m of it.text.matchAll(/\S+(?: \S+)*/g)) {
      const text = m[0];
      list.push({ page: it.page, y: it.y, x: it.x + (m.index ?? 0) * perChar, w: text.length * perChar, text });
    }
    pages.set(it.page, list);
  }
  const rows: { page: number; y: number; cells: PdfText[] }[] = [];
  for (const page of [...pages.keys()].sort((a, b) => a - b)) {
    const lines: { y: number; items: PdfText[] }[] = [];
    for (const it of [...(pages.get(page) ?? [])].sort((a, b) => b.y - a.y)) {
      const line = lines.find((l) => Math.abs(l.y - it.y) <= 2.5);
      if (line) line.items.push(it);
      else lines.push({ y: it.y, items: [it] });
    }
    for (const l of lines) rows.push({ page, y: l.y, cells: cellsOf(l.items) });
  }
  return rows;
}

const DESCRIPTION_HEAD = /\b(description|narration|narrative|details|particulars|remarks?|memo|payee|purpose)\b/i;

/** Column names: a date and money, no values, and four columns or a description (not a summary box of balances). */
const isHeader = (cells: PdfText[]) => {
  const words = cells.map((c) => c.text);
  return cells.length >= 3 && words.some((w) => DATE_HEAD.test(w)) && words.some((w) => MONEY_HEAD.test(w)) && !words.some((w) => DATE.test(w))
    && (cells.length >= 4 || words.some((w) => DESCRIPTION_HEAD.test(w)));
};

/** Which header column a cell belongs to: the one it overlaps most, else the nearest. */
const columnOf = (cell: PdfText, header: PdfText[]): number => {
  let best = 0;
  let bestScore = -Infinity;
  header.forEach((h, i) => {
    const overlap = Math.min(cell.x + cell.w, h.x + h.w) - Math.max(cell.x, h.x);
    const score = overlap >= 0 ? overlap + 1000 : overlap;   // any overlap beats any gap
    if (score > bestScore) {
      bestScore = score;
      best = i;
    }
  });
  return best;
};

/**
 * The statement table in the PDF's pieces of text: its header (the bank's own column names) and one row per statement
 * line, in the header's columns.
 */
export function statementTable(items: PdfText[]): string[][] {
  const rows = rowsOf(items);
  // The table's header: the widest row of column names (the first, when the header repeats on every page).
  let at = -1;
  rows.forEach((r, i) => {
    if (isHeader(r.cells) && (at < 0 || r.cells.length > rows[at].cells.length)) at = i;
  });
  if (at < 0) {
    throw new PdfStatementError("No table of transactions was found in the PDF: it needs column headings such as Date, Description, Debit, Credit and Balance. Export the statement as CSV or Excel instead.");
  }
  const header = rows[at].cells;
  const names = header.map((h) => h.text);
  const dateColumn = header.findIndex((h) => DATE_HEAD.test(h.text));
  const descriptionColumn = header.findIndex((h) => DESCRIPTION_HEAD.test(h.text));
  // Where the header stands on each page that repeats it: what is above it there (letterhead, summary) is not the table.
  const headerText = names.join("|");
  const headerAt = new Map<number, number>();
  for (const r of rows) {
    if (!headerAt.has(r.page) && r.cells.map((c) => c.text).join("|") === headerText) headerAt.set(r.page, r.y);
  }
  const out: string[][] = [names];
  let last: { cells: string[]; page: number; y: number } | null = null;
  let gap = 14;   // the usual distance between two lines, learnt from the statement

  for (const row of rows.slice(at + 1)) {
    if (row.page !== rows[at].page && row.y > (headerAt.get(row.page) ?? Infinity)) {
      continue;   // above the repeated header
    }
    if (isHeader(row.cells)) {   // the header again, on the next page
      last = null;
      continue;
    }
    const cells = names.map(() => "");
    for (const c of row.cells) {
      const i = columnOf(c, header);
      cells[i] = cells[i] === "" ? c.text : `${cells[i]} ${c.text}`;
    }
    if (DATE.test(cells[dateColumn] ?? "")) {
      if (last && last.page === row.page && last.y - row.y > 0 && last.y - row.y < 40) gap = Math.min(gap, last.y - row.y);
      out.push(cells);
      last = { cells, page: row.page, y: row.y };
      continue;
    }
    // No date: the rest of the line above when it sits just under it and has text only; otherwise (a total, a page
    // footer, the closing balance on its own) it is not a statement line.
    const close = last !== null && last.page === row.page && last.y - row.y <= gap * 1.7;
    const textOnly = row.cells.every((c) => !/^[-(]?[\d,.\s]+\)?\s*(cr|dr)?$/i.test(c.text));
    if (last && close && textOnly) {
      const into = descriptionColumn >= 0 ? descriptionColumn : cells.findIndex((c) => c !== "");
      const more = cells.filter((c) => c !== "").join(" ");
      if (into >= 0 && more !== "") last.cells[into] = `${last.cells[into]} ${more}`.trim();
      last.y = row.y;
      continue;
    }
    last = null;
  }
  if (out.length === 1) {
    throw new PdfStatementError("The PDF has column headings but no dated lines under them. Export the statement as CSV or Excel instead.");
  }
  return out;
}

/** The pieces of text of a PDF, page by page. */
export async function pdfText(bytes: Uint8Array): Promise<PdfText[]> {
  const { getDocumentProxy } = await import("unpdf");
  let doc;
  try {
    // Only the text is wanted: no fonts loaded or fetched from elsewhere (the pdf.js build in unpdf has no eval or
    // Function() at all, so a crafted font cannot run code); errors only in the log.
    doc = await getDocumentProxy(bytes, { disableFontFace: true, useSystemFonts: false, stopAtErrors: false, verbosity: 0 });
  } catch (error) {
    const name = error instanceof Error ? error.name : "";
    if (name === "PasswordException") throw new PdfStatementError("The PDF is protected by a password. Save a copy without it (print to PDF), or export the statement as CSV or Excel.");
    throw new PdfStatementError("The PDF cannot be read. Export the statement as CSV or Excel instead.");
  }
  try {
    if (doc.numPages > MAX_PDF_PAGES) throw new PdfStatementError(`The PDF has ${doc.numPages} pages; at most ${MAX_PDF_PAGES} are read. Export one month at a time.`);
    const items: PdfText[] = [];
    for (let p = 1; p <= doc.numPages; p++) {
      const page = await doc.getPage(p);
      const content = await page.getTextContent();
      for (const it of content.items) {
        if (!("str" in it) || it.str.trim() === "") continue;
        items.push({ page: p, x: it.transform[4], y: it.transform[5], w: it.width, text: it.str });
      }
      page.cleanup();
    }
    return items;
  } finally {
    await doc.cleanup();
  }
}

/** The form's file, when it is a PDF: its statement as CSV; null when it is something else. */
export async function pdfStatementFrom(form: FormData): Promise<{ csv: string; fileName: string } | { error: string } | null> {
  const file = form.get("file");
  if (!(file instanceof File) || file.size === 0) return null;
  const head = new TextDecoder().decode(await file.slice(0, 1024).arrayBuffer());
  if (!head.includes("%PDF-")) return null;
  if (file.size > MAX_STATEMENT_BYTES) return { error: "The PDF is larger than 900 KB: download one month at a time." };
  try {
    const items = await pdfText(new Uint8Array(await file.arrayBuffer()));
    if (items.length === 0) {
      return { error: "The PDF has no text in it (a scan or a photo), so its lines cannot be read. Download the statement from internet banking as PDF, CSV or Excel." };
    }
    return { csv: rowsToCsv(statementTable(items)), fileName: file.name.slice(0, 200) };
  } catch (error) {
    if (error instanceof PdfStatementError) return { error: error.message };
    throw error;
  }
}
