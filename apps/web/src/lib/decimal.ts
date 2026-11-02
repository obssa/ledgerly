/**
 * Exact decimal maths for money in the browser, mirroring the API's Money/TaxCalculator
 * (apps/api/app/Domain). Values are bigint at scale 4 — never JS floats — so a total previewed
 * in the invoice form always equals the total the API calculates.
 */

export const SCALE = 4;
const FACTOR = 10n ** BigInt(SCALE);

export const CURRENCY_DECIMALS: Record<string, number> = {
  ETB: 2, KES: 2, UGX: 0, TZS: 2, RWF: 0, NGN: 2, XOF: 0, XAF: 0, GHS: 2, ZAR: 2, USD: 2, EUR: 2, GBP: 2, AED: 2, CNY: 2,
};

export type Scaled = bigint; // value × 10^4

export function parse(value: string | number): Scaled {
  const s = String(value).trim();
  if (!/^-?\d+(\.\d+)?$/.test(s)) throw new Error(`Not a decimal: ${s}`);
  const negative = s.startsWith("-");
  const [whole, frac = ""] = s.replace("-", "").split(".");
  const digits = (frac + "0".repeat(SCALE)).slice(0, SCALE);
  let scaled = BigInt(whole) * FACTOR + BigInt(digits);
  // Round half-up on the digit after the 4th decimal.
  if (frac.length > SCALE && Number(frac[SCALE]) >= 5) scaled += 1n;
  return negative ? -scaled : scaled;
}

export function toString(value: Scaled): string {
  const negative = value < 0n;
  const abs = negative ? -value : value;
  const whole = abs / FACTOR;
  const frac = (abs % FACTOR).toString().padStart(SCALE, "0");
  return `${negative ? "-" : ""}${whole}.${frac}`;
}

/** a × b, both at scale 4, rounded half-up (away from zero) back to scale 4. */
export function multiply(a: Scaled, b: Scaled): Scaled {
  return divRound(a * b, FACTOR);
}

/** a ÷ b, both at scale 4, rounded half-up to scale 4. */
export function divide(a: Scaled, b: Scaled): Scaled {
  return divRound(a * FACTOR, b);
}

/** Round to the currency's document decimals (ETB 2, UGX 0 …), result still at scale 4. */
export function roundToCurrency(value: Scaled, currency: string): Scaled {
  const decimals = CURRENCY_DECIMALS[currency] ?? 2;
  const step = 10n ** BigInt(SCALE - decimals);
  return divRound(value, step) * step;
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

/** Same rules as TaxCalculator::line(): round each line to the currency, then sum. */
export function lineAmounts(line: LineInput, currency: string, inclusive = false): Amounts {
  const factor = multiply(parse(line.quantity), FACTOR - parse(line.discountRate ?? "0"));
  const gross = roundToCurrency(multiply(parse(line.unitPrice), factor), currency);
  const rate = parse(line.taxRate ?? "0");

  if (inclusive) {
    const subtotal = roundToCurrency(divide(gross, FACTOR + rate), currency);
    return { subtotal, tax: gross - subtotal, total: gross };
  }
  const tax = roundToCurrency(multiply(gross, rate), currency);
  return { subtotal: gross, tax, total: gross + tax };
}

export function documentAmounts(lines: LineInput[], currency: string, inclusive = false): Amounts {
  return lines.reduce<Amounts>(
    (sum, l) => {
      const a = lineAmounts(l, currency, inclusive);
      return { subtotal: sum.subtotal + a.subtotal, tax: sum.tax + a.tax, total: sum.total + a.total };
    },
    { subtotal: 0n, tax: 0n, total: 0n },
  );
}
