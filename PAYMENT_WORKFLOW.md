# Customer payment workflow

Customer System does not start a gateway checkout or collect payment references
or receipt uploads. After paying externally by GCash or debit/credit, the
customer can confirm the invoice payment. The system then records the full
remaining balance as a collection and marks the invoice Paid in one transaction.
This relies on the customer's confirmation; the portal does not verify or
process the external payment.

For an existing database with the legacy submissions table, apply
`ITPROFEL/SALES/database/migrations/20261120_cancel_pending_customer_payments.sql`
to cancel pending submissions. It does not change invoice balances. Legacy
records and proof files remain private for audit; the customer and Sales review
screens no longer display or process them. A new database does not need the
legacy submissions table or migration.

Do not enter or store card numbers, expiration dates, or CVV values. Existing
gateway payment history is retained, and gateway callbacks are disabled.
