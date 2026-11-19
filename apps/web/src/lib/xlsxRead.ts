import "server-only";
import { inflateRawSync } from "node:zlib";

/*
 * Module 6: the first sheet of an Excel workbook (.xlsx) as rows of text, so a spreadsheet can be imported as it is
 * (journals, opening balances, lists) without saving it as CSV first. Read here, with no library: only the zip's
 * central directory, the first worksheet, the shared strings and the cell styles are opened; every part is size-capped
 * before and after inflating (no zip bomb), XML entities are the five named ones and numeric references only (no
 * external entities exist in this reader), and formulas are never run: a formula cell gives the value Excel saved.
 * Date cells (by their number format) come out as YYYY-MM-DD; other numbers as Excel saved them.
 */

const MAX_FILE = 5 * 1024 * 1024;
const MAX_PART = 20 * 1024 * 1024;
const MAX_ENTRIES = 2000;
const MAX_ROWS = 5000;
const MAX_COLUMNS = 50;

export class XlsxError extends Error {}

interface Entry { method: number; compressed: number; size: number; offset: number }

function entries(buf: Buffer): Map<string, Entry> {
  // The end of central directory record: within the last 64 KB + 22 bytes.
  let eocd = -1;
  for (let i = buf.length - 22; i >= Math.max(0, buf.length - 65557); i--) {
    if (buf.readUInt32LE(i) === 0x06054b50) { eocd = i; break; }
  }
  if (eocd < 0) throw new XlsxError("This is not an Excel workbook (.xlsx).");
  const count = buf.readUInt16LE(eocd + 10);
  let p = buf.readUInt32LE(eocd + 16);
  if (count > MAX_ENTRIES) throw new XlsxError("The workbook has too many parts.");
  const out = new Map<string, Entry>();
  for (let n = 0; n < count; n++) {
    if (p + 46 > buf.length || buf.readUInt32LE(p) !== 0x02014b50) throw new XlsxError("The workbook is damaged.");
    const method = buf.readUInt16LE(p + 10);
    const compressed = buf.readUInt32LE(p + 20);
    const size = buf.readUInt32LE(p + 24);
    const nameLength = buf.readUInt16LE(p + 28);
    const extra = buf.readUInt16LE(p + 30);
    const comment = buf.readUInt16LE(p + 32);
    const offset = buf.readUInt32LE(p + 42);
    const name = buf.subarray(p + 46, p + 46 + nameLength).toString("utf8");
    out.set(name, { method, compressed, size, offset });
    p += 46 + nameLength + extra + comment;
  }
  return out;
}

function part(buf: Buffer, all: Map<string, Entry>, name: string): string | null {
  const e = all.get(name);
  if (!e) return null;
  if (e.size > MAX_PART || e.compressed > MAX_FILE) throw new XlsxError("The workbook is too large to read.");
  if (e.offset + 30 > buf.length || buf.readUInt32LE(e.offset) !== 0x04034b50) throw new XlsxError("The workbook is damaged.");
  const start = e.offset + 30 + buf.readUInt16LE(e.offset + 26) + buf.readUInt16LE(e.offset + 28);
  const data = buf.subarray(start, start + e.compressed);
  if (e.method === 0) return data.toString("utf8");
  if (e.method !== 8) throw new XlsxError("The workbook is packed in a way this reader does not open.");
  try {
    return inflateRawSync(data, { maxOutputLength: MAX_PART }).toString("utf8");
  } catch {
    throw new XlsxError("The workbook is damaged or too large to read.");
  }
}

const decode = (s: string) => s.replace(/&(amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);/g, (_, e: string) => {
  if (e === "amp") return "&";
  if (e === "lt") return "<";
  if (e === "gt") return ">";
  if (e === "quot") return '"';
  if (e === "apos") return "'";
  const code = e.startsWith("#x") ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10);
  return code > 0 && code < 0x110000 ? String.fromCodePoint(code) : "";
});

/** The text of every <t> inside an XML fragment (a shared string, or an inline string), joined. */
const texts = (xml: string) => [...xml.matchAll(/<t(?:\s[^>]*)?>([\s\S]*?)<\/t>/g)].map((m) => decode(m[1])).join("");

/** Which cell styles (by index) show a date: built-in formats 14-22 and 45-47, or a custom format with d, m or y outside quotes. */
function dateStyles(styles: string | null): Set<number> {
  const out = new Set<number>();
  if (!styles) return out;
  const custom = new Map<number, string>();
  for (const m of styles.matchAll(/<numFmt\s[^>]*numFmtId="(\d+)"[^>]*formatCode="([^"]*)"/g)) custom.set(Number(m[1]), decode(m[2]));
  const xfs = /<cellXfs[^>]*>([\s\S]*?)<\/cellXfs>/.exec(styles)?.[1] ?? "";
  [...xfs.matchAll(/<xf\s([^>]*?)\/?>/g)].forEach((m, i) => {
    const id = Number(/numFmtId="(\d+)"/.exec(m[1])?.[1] ?? "0");
    const code = (custom.get(id) ?? "").replace(/"[^"]*"|\[[^\]]*\]/g, "");
    if ((id >= 14 && id <= 22) || (id >= 45 && id <= 47) || /[dmy]/i.test(code)) out.add(i);
  });
  return out;
}

/** An Excel date serial (1900 system) as YYYY-MM-DD. */
export function serialDate(serial: number): string {
  const ms = Date.UTC(1899, 11, 30) + Math.round(serial * 86400) * 1000;
  return new Date(ms).toISOString().slice(0, 10);
}

const columnIndex = (ref: string) => [...(/^[A-Z]+/.exec(ref)?.[0] ?? "A")].reduce((n, c) => n * 26 + (c.charCodeAt(0) - 64), 0) - 1;

/** The first worksheet as rows of text (empty cells as ""), at most 5,000 rows of 50 columns. */
export function readXlsx(data: Uint8Array): string[][] {
  if (data.byteLength > MAX_FILE) throw new XlsxError("A workbook of up to 5 MB.");
  const buf = Buffer.from(data.buffer, data.byteOffset, data.byteLength);
  if (buf.length < 22 || buf.readUInt32LE(0) !== 0x04034b50) throw new XlsxError("This is not an Excel workbook (.xlsx).");
  const all = entries(buf);
  const sheets = [...all.keys()].filter((n) => /^xl\/worksheets\/sheet\d+\.xml$/.test(n))
    .sort((a, b) => Number(/(\d+)\.xml$/.exec(a)![1]) - Number(/(\d+)\.xml$/.exec(b)![1]));
  const sheet = part(buf, all, sheets[0] ?? "");
  if (sheet === null) throw new XlsxError("The workbook has no worksheet.");
  const shared = [...(part(buf, all, "xl/sharedStrings.xml") ?? "").matchAll(/<si>([\s\S]*?)<\/si>/g)].map((m) => texts(m[1]));
  const dates = dateStyles(part(buf, all, "xl/styles.xml"));

  const rows: string[][] = [];
  for (const r of sheet.matchAll(/<row\b[^>]*>([\s\S]*?)<\/row>/g)) {
    if (rows.length >= MAX_ROWS) throw new XlsxError(`A sheet of up to ${MAX_ROWS} rows.`);
    const row: string[] = [];
    for (const c of r[1].matchAll(/<c\b([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g)) {
      const attrs = c[1];
      const body = c[2] ?? "";
      const at = columnIndex(/\br="([A-Z]+)\d+"/.exec(attrs)?.[1] ?? String.fromCharCode(65 + Math.min(row.length, 25)));
      if (at >= MAX_COLUMNS) continue;
      const type = /\bt="([^"]+)"/.exec(attrs)?.[1] ?? "n";
      const style = Number(/\bs="(\d+)"/.exec(attrs)?.[1] ?? "-1");
      const v = /<v>([\s\S]*?)<\/v>/.exec(body)?.[1];
      let value = "";
      if (type === "s") value = shared[Number(v)] ?? "";
      else if (type === "inlineStr") value = texts(body);
      else if (type === "b") value = v === "1" ? "TRUE" : "FALSE";
      else if (v !== undefined) value = type === "n" && dates.has(style) && /^-?\d+(\.\d+)?$/.test(v) ? serialDate(Number(v)) : decode(v);
      while (row.length < at) row.push("");
      row[at] = value;
    }
    rows.push(row);
  }
  return rows;
}

/** Rows as CSV text (every cell quoted when it needs to be), for the API's importers. */
export function rowsToCsv(rows: string[][]): string {
  const cell = (v: string) => (/[",\n\r]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v);
  return rows.map((r) => r.map(cell).join(",")).join("\n");
}

/** A form's file as CSV text: an Excel workbook (.xlsx, by its zip signature) is read here; anything else is left to the CSV reader (null). */
export async function spreadsheetFrom(form: FormData): Promise<{ csv: string } | { error: string } | null> {
  const file = form.get("file");
  if (!(file instanceof File) || file.size === 0) return null;
  const head = new Uint8Array(await file.slice(0, 4).arrayBuffer());
  if (!(head[0] === 0x50 && head[1] === 0x4b && head[2] === 0x03 && head[3] === 0x04)) return null;
  try {
    return { csv: rowsToCsv(readXlsx(new Uint8Array(await file.arrayBuffer()))) };
  } catch (error) {
    if (error instanceof XlsxError) return { error: error.message };
    throw error;
  }
}
