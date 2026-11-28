import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getBankAccounts, getCurrencies } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { parse, toString } from "@/lib/decimal";
import { DepositReceipts, NewBankAccount, TransferMoney } from "./BankingForms";

export const metadata = { title: "Banking and cash" };

const KIND: Record<string, string> = { bank: "Bank", mobile_money: "Mobile money", cash: "Cash", undeposited_funds: "Not yet banked" };

/** Module 5: every place the company keeps money, with its balance; transfers between them and bank deposits. */
export default async function BankingPage() {
  const [{ data, can, demo }, currencies] = await Promise.all([getBankAccounts(), getCurrencies()]);
  const money = data.filter((a) => a.kind !== "undeposited_funds");
  const sameCurrency = money.filter((a) => a.currency === currencies.base_currency);
  // Accounts in more than one currency: each also in the company's currency, and the total held (QuickBooks' home-currency balance).
  const multi = data.some((a) => a.currency !== currencies.base_currency);
  const held = toString(data.reduce((t, a) => t + parse(a.balance_base), 0n));

  return (
    <>
      <PageTitle title="Banking and cash" />
      <Card className="!p-2" label="Accounts">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Code</th><th scope="col">Account</th><th scope="col">Kind</th><th scope="col">Where</th><th scope="col" className="!text-right">Balance</th>{multi && <th scope="col" className="!text-right">In {currencies.base_currency}</th>}</tr></thead>
            <tbody>
              {data.length === 0 && <tr><td colSpan={5} className="text-muted">{demo ? "Not available in the demo." : "No money accounts yet."}</td></tr>}
              {data.map((a) => (
                <tr key={a.id}>
                  <td className="num">{a.code}</td>
                  <td className="font-semibold"><Link href={`/banking/${encodeURIComponent(a.id)}`} className="hover:underline">{a.name}</Link></td>
                  <td>{KIND[a.kind] ?? a.kind}</td>
                  <td className="text-[13px] text-muted">{[a.bank_details?.bank_name ?? a.bank_details?.provider, a.bank_details?.account_number ?? a.bank_details?.wallet_number].filter(Boolean).join(" · ") || "—"}</td>
                  <td className="num text-right font-semibold">{a.currency !== currencies.base_currency && <span className="mr-1 text-muted">{a.currency}</span>}{formatAmount(a.balance)}</td>
                  {multi && <td className="num text-right">{formatAmount(a.balance_base)}</td>}
                </tr>
              ))}
            </tbody>
            {multi && <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={5}>Cash and bank held, in {currencies.base_currency}</td><td className="num text-right">{formatAmount(held)}</td></tr></tfoot>}
          </table>
        </div>
      </Card>
      {!demo && can.transfer && money.filter((a) => a.is_active).length > 1 && (
        <Card className="mt-4" label="Transfer"><h2 className="mb-2 text-[15px] font-bold">Move money between accounts</h2><TransferMoney accounts={money.filter((a) => a.is_active)} base={currencies.base_currency} /></Card>
      )}
      {!demo && can.deposit && sameCurrency.some((a) => a.kind !== "cash") && (
        <Card className="mt-4" label="Deposit">
          <h2 className="mb-1 text-[15px] font-bold">Bank the cash and cheques not yet deposited</h2>
          <p className="mb-2 text-[13px] text-muted">One amount per line, as on the deposit slip. The bank&apos;s fee comes off the deposit.</p>
          <DepositReceipts banks={sameCurrency.filter((a) => a.kind !== "cash")} />
        </Card>
      )}
      {!demo && can.manage && (
        <Card className="mt-4" label="New account">
          <h2 className="mb-2 text-[15px] font-bold">Open a bank, wallet or cash account</h2>
          <NewBankAccount currencies={[currencies.base_currency, ...currencies.data.filter((c) => c.is_enabled).map((c) => c.currency)]} />
        </Card>
      )}
    </>
  );
}
