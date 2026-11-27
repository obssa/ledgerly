import { mergeRows, type Line } from "./compare";

/**
 * A report of one figure per row (sales by customer, profit by location, an item's value …) with a column per period:
 * the rows joined across the periods by their key, and a total line when the report has one.
 */
export function measureLines<R, T>(results: R[], rows: (r: R) => readonly T[], key: (t: T) => string, value: (t: T) => string, label: (t: T) => string,
  total?: { label: string; value: (r: R) => string }): Line[] {
  const lines = mergeRows(results.map(rows), key, value, label);
  return total ? [...lines, { key: "t:total", label: total.label, kind: "grand", values: results.map(total.value) }] : lines;
}
