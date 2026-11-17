/*
 * CSV for exported reports. A text cell a spreadsheet would read as a formula (=, +, -, @, tab, CR at the start) gets an
 * apostrophe in front, so opening the file never runs anything; numbers are written as they are. Cells are quoted when
 * they hold a comma, a quote or a line break; lines end with CRLF, as spreadsheets expect.
 */

export type Cell = string | number | null | undefined;

export function safeCell(value: Cell): string {
  if (value === null || value === undefined) return "";
  const text = String(value);
  if (/^-?\d+(\.\d+)?$/.test(text)) return text;
  return /^[=+\-@\t\r]/.test(text) ? `'${text}` : text;
}

const quote = (text: string) => (/[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text);

export function toCsv(rows: Cell[][]): string {
  return rows.map((row) => row.map((c) => quote(safeCell(c))).join(",")).join("\r\n") + "\r\n";
}
