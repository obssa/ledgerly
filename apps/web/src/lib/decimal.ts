/**
 * Exact decimal maths for money in the browser, mirroring the API's Money/TaxCalculator
 * (apps/api/app/Domain). Product rule: every amount has exactly 2 decimal places. Amounts are bigint
 * hundredths — never JS floats — so a total previewed in the invoice form always equals the API's.
 * Rates (tax, discount) are parsed at 6 decimals so 7.5% stays exact.
 */

export const SCALE = 2;
const FACTOR = 10n ** BigInt(SCALE);
const RATE_SCALE = 6;
const RATE_FACTOR = 10n ** BigInt(RATE_SCALE);

/** Currencies the product supports; all use 2 decimal places. */
export const CURRENCIES = ["ETB", "KES", "UGX", "TZS", "RWF", "NGN", "GHS", "ZAR", "USD", "EUR", "GBP", "AED", "CNY"] as const;

export type Scaled = bigint; // amount × 100

function parseAt(value: string | number, scale: number): bigint {
  const s = String(value).trim();
  if (!/^-?\d+(\.\d+)?$/.test(s)) throw new Error(`Not a decimal: ${s}`);
  const negative = s.startsWith("-");
  const [whole, frac = ""] = s.replace("-", "").split(".");
  const digits = (frac + "0".repeat(scale)).slice(0, scale);
  let scaled = BigInt(whole) * 10n ** BigInt(scale) + BigInt(digits || "0");
  // Round half-up on the first digit beyond the scale.
  if (frac.length > scale && Number(frac[scale]) >= 5) scaled += 1n;
  return negative ? -scaled : scaled;
}

/** Parse an amount, price or quantity to 2 decimals (half-up). */
export function parse(value: string | number): Scaled {
  return parseAt(value, SCALE);
}

/** Parse a tax or discount rate (0..1) at 6 decimals. */
export function parseRate(value: string | number): bigint {
  return parseAt(value, RATE_SCALE);
}

export function toString(value: Scaled): string {
  const negative = value < 0n;
  const abs = negative ? -value : value;
  const whole = abs / FACTOR;
  const frac = (abs % FACTOR).toString().padStart(SCALE, "0");
  return `${negative ? "-" : ""}${whole}.${frac}`;
}

/** Kept for call sites: amounts are already at 2 decimals for every currency. */
// eslint-disable-next-line @typescript-eslint/no-unused-vars -- currency kept in the signature for call sites
export function roundToCurrency(value: Scaled, _currency?: string): Scaled {
  return value;
}

function divRound(numerator: bigint, denominator: bigint): bigint {
  const negative = (numerator < 0n) !== (denominator < 0n);
  const n = numerator < 0n ? -numerator : numerator;
  const d = denominator < 0n ? -denominator : denominator;
  let q = n / d;
  if ((n % d) * 2n >= d) q += 1n;
  return negative ? -q : q;
}

export interface LineInput {
  quantity: string;
  unitPrice: string;
  discountRate?: string; // 0..1
  taxRate?: string; // 0..1
}

export interface Amounts {
  subtotal: Scaled;
  tax: Scaled;
  total: Scaled;
}

/** Same rules as TaxCalculator::line(): round each line to 2 decimals, then sum. */
// eslint-disable-next-line @typescript-eslint/no-unused-vars -- currency kept in the signature for call sites
export function lineAmounts(line: LineInput, _currency?: string, inclusive = false): Amounts {
  const qty = parse(line.quantity);
  const price = parse(line.unitPrice);
  const keep = RATE_FACTOR - parseRate(line.discountRate ?? "0");
  const rate = parseRate(line.taxRate ?? "0");

  // price × quantity × (1 − discount), exact, then one rounding to 2 decimals
  const gross = divRound(price * qty * keep, FACTOR * RATE_FACTOR);

  if (inclusive) {
    const subtotal = divRound(gross * RATE_FACTOR, RATE_FACTOR + rate);
    return { subtotal, tax: gross - subtotal, total: gross };
  }
  const tax = divRound(gross * rate, RATE_FACTOR);
  return { subtotal: gross, tax, total: gross + tax };
}

export function documentAmounts(lines: LineInput[], currency?: string, inclusive = false): Amounts {
  return lines.reduce<Amounts>(
    (sum, l) => {
      const a = lineAmounts(l, currency, inclusive);
      return { subtotal: sum.subtotal + a.subtotal, tax: sum.tax + a.tax, total: sum.total + a.total };
    },
    { subtotal: 0n, tax: 0n, total: 0n },
  );
}
