# Billing: Ledgerly's own subscriptions

Release one has plans and a trial, with no payment provider. Customers pay by bank transfer; you record each payment.

## What a company sees

- **Settings, Plan and billing:** the plan, the status (trial, active, payment overdue, suspended), when the trial ends or
  what is paid until, and, for owners and admins, how to pay.
- **A line across the app:**
  - when the books are read-only;
  - when a payment is overdue;
  - when, with billing enforced, the trial ends within a week.
- A new company starts a 30-day trial.
- A **suspended** or **cancelled** subscription leaves the books **read-only**. They can still be viewed and exported,
  but nothing new can be posted.

## Settings (API environment, `shared/api.env`)

| Setting | What it does |
|---|---|
| `BILLING_ENFORCE` | `true` turns on the daily job (01:00): a trial past its end is suspended; an active subscription past its paid-until date becomes past due, then suspended after the grace days. **Off by default**: nothing is suspended until you turn it on. The shared demo is never touched. |
| `BILLING_GRACE_DAYS` | Days after the paid-until date before suspension (default 14). |
| `BILLING_PAYMENT_INSTRUCTIONS` | How to pay, shown to owners and admins: bank, account number, the reference to quote. |
| `BILLING_CONTACT_EMAIL` | Where customers ask about billing. |

## Recording a payment, or any change

GitHub, Actions, **billing**, Run workflow:

| Input | Example |
|---|---|
| id | the company's id (from its address bar or the API), or its organisation's |
| action | `activate` (a payment), `plan`, `extend-trial`, `suspend`, `cancel`, or `show` |
| plan | `essentials` (optional; with `activate` or `plan`) |
| paid_until | `2027-01-31`: the last day paid for (with `activate`) |
| days | `14` (with `extend-trial`, 1 to 90) |
| reference | the bank transfer reference |

How it works:
- The workflow runs `ledgerly:subscription` on the server as the owner role. The owner password is passed on stdin only,
  as for a release; the application itself cannot change a subscription.
- Every change goes into `subscription_events`, with the time, the previous and new status, the reference and who made
  it. The application cannot read or change that table.

The same command works locally against any database you hold the owner password for:

```bash
php artisan ledgerly:subscription <company-or-organisation-id> --activate --paid-until=2027-01-31 --plan=essentials --reference=TRF-123 --by="Your name"
```

## Prices and limits

None are set in code: the plans are names (`config/billing.php`). Prices and what each plan includes are business
decisions. Quote them in the payment instructions or on the website until a payment provider is added.
