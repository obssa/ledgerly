import "server-only";
import { deflateRawSync } from "node:zlib";
import type { Cell } from "./csv";

/*
 * Module 10: a report as an Excel workbook (.xlsx), one sheet, written here so no library is needed. Amounts and
 * counts become numbers Excel can add up (two decimals shown with thousands separators); everything else is text,
 * stored as inline strings, which Excel never runs as a formula. Title rows (company, report, period) may come first:
 * the headings are then at `headingRow`, bold, with the panes frozen under them, and the company name is bold too.
 */

const NUMBER = /^-?\d+(\.\d+)?$/;
const ISO_DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

/** Excel's own date formats for the company's (Settings, Date format). */
const EXCEL_DATE: Record<string, string> = { "DD/MM/YYYY": "dd/mm/yyyy", "MM/DD/YYYY": "mm/dd/yyyy", "YYYY-MM-DD": "yyyy-mm-dd" };

/** Days since 30 December 1899: what Excel stores a date as. */
export function excelSerial(iso: string): number {
  const [, y, m, d] = ISO_DAY.exec(iso)!.map(Number);
  return Math.round((Date.UTC(y, m - 1, d) - Date.UTC(1899, 11, 30)) / 86_400_000);
}

const escapeXml = (text: string) =>
  text.replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c] as string)
    // Characters XML 1.0 does not allow (control characters other than tab and line breaks).
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, "");

/** "A", "B", ..., "Z", "AA", ... for a zero-based column. */
export function columnName(index: number): string {
  let n = index + 1;
  let name = "";
  while (n > 0) {
    const r = (n - 1) % 26;
    name = String.fromCharCode(65 + r) + name;
    n = Math.floor((n - 1) / 26);
  }
  return name;
}

function cellXml(value: Cell, ref: string, text: boolean, bold: boolean): string {
  if (value === null || value === undefined || value === "") return "";
  const s = String(value);
  if (!text && ISO_DAY.test(s)) return `<c r="${ref}" s="3"><v>${excelSerial(s)}</v></c>`;
  if (!text && (typeof value === "number" || NUMBER.test(s))) {
    const style = s.includes(".") ? ' s="2"' : "";
    return `<c r="${ref}"${style}><v>${s}</v></c>`;
  }
  return `<c r="${ref}" t="inlineStr"${bold ? ' s="1"' : ""}><is><t xml:space="preserve">${escapeXml(s)}</t></is></c>`;
}

export function sheetXml(rows: Cell[][], headingRow = 0): string {
  // Title rows and the headings are text, never numbers (a year in a period is not a figure to add up).
  const body = rows.map((row, r) =>
    `<row r="${r + 1}">${row.map((v, c) => cellXml(v, `${columnName(c)}${r + 1}`, r <= headingRow, r === headingRow || (r === 0 && headingRow > 0))).join("")}</row>`).join("");
  const widest = Math.max(1, ...rows.map((r) => r.length));
  const cols = Array.from({ length: widest }, (_, c) => {
    const width = Math.min(60, Math.max(10, ...rows.map((r) => String(r[c] ?? "").length + 2)));
    return `<col min="${c + 1}" max="${c + 1}" width="${width}" customWidth="1"/>`;
  }).join("");
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="${headingRow + 1}" topLeftCell="A${headingRow + 2}" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>${cols}</cols><sheetData>${body}</sheetData></worksheet>`;
}

const styles = (dateFormat: string) => `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="${EXCEL_DATE[dateFormat] ?? "dd/mm/yyyy"}"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`;

/** The workbook's files, by name. The sheet name is cut to Excel's 31 characters and cleared of the ones it refuses. */
export function workbookFiles(sheetName: string, rows: Cell[][], headingRow = 0, dateFormat = "DD/MM/YYYY"): Record<string, string> {
  const name = escapeXml((sheetName.replace(/[\\/?*[\]:]/g, " ").trim() || "Report").slice(0, 31));
  return {
    "[Content_Types].xml": `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>`,
    "_rels/.rels": `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>`,
    "xl/workbook.xml": `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="${name}" sheetId="1" r:id="rId1"/></sheets></workbook>`,
    "xl/_rels/workbook.xml.rels": `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>`,
    "xl/styles.xml": styles(dateFormat),
    "xl/worksheets/sheet1.xml": sheetXml(rows, headingRow),
  };
}

const CRC_TABLE = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c >>> 0;
  }
  return t;
})();

export function crc32(data: Uint8Array): number {
  let c = 0xffffffff;
  for (const b of data) c = CRC_TABLE[(c ^ b) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

/** A ZIP archive (deflated, UTF-8 names), as an .xlsx file is. */
export function zip(files: Record<string, string>): Uint8Array {
  const parts: Buffer[] = [];
  const central: Buffer[] = [];
  let offset = 0;
  for (const [fileName, text] of Object.entries(files)) {
    const name = Buffer.from(fileName, "utf8");
    const data = Buffer.from(text, "utf8");
    const body = deflateRawSync(data);
    const crc = crc32(data);
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0); local.writeUInt16LE(20, 4); local.writeUInt16LE(0x0800, 6); local.writeUInt16LE(8, 8);
    local.writeUInt16LE(0, 10); local.writeUInt16LE(0x21, 12);   // 1 Jan 1980: no date that would change the file
    local.writeUInt32LE(crc, 14); local.writeUInt32LE(body.length, 18); local.writeUInt32LE(data.length, 22);
    local.writeUInt16LE(name.length, 26); local.writeUInt16LE(0, 28);
    const entry = Buffer.alloc(46);
    entry.writeUInt32LE(0x02014b50, 0); entry.writeUInt16LE(20, 4); entry.writeUInt16LE(20, 6); entry.writeUInt16LE(0x0800, 8);
    entry.writeUInt16LE(8, 10); entry.writeUInt16LE(0, 12); entry.writeUInt16LE(0x21, 14);
    entry.writeUInt32LE(crc, 16); entry.writeUInt32LE(body.length, 20); entry.writeUInt32LE(data.length, 24);
    entry.writeUInt16LE(name.length, 28); entry.writeUInt32LE(offset, 42);
    parts.push(local, name, body);
    central.push(entry, name);
    offset += local.length + name.length + body.length;
  }
  const directory = Buffer.concat(central);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(Object.keys(files).length, 8); end.writeUInt16LE(Object.keys(files).length, 10);
  end.writeUInt32LE(directory.length, 12); end.writeUInt32LE(offset, 16);
  return new Uint8Array(Buffer.concat([...parts, directory, end]));
}

export function toXlsx(sheetName: string, rows: Cell[][], headingRow = 0, dateFormat = "DD/MM/YYYY"): Uint8Array {
  return zip(workbookFiles(sheetName, rows, headingRow, dateFormat));
}
