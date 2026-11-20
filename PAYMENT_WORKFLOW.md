# Manual customer payment workflow

Customer System no longer starts a gateway checkout. Customers choose GCash or
debit/credit, enter the payment reference, and upload a receipt. The submission
remains pending until Sales verifies it. Approving a submission records a
collection and updates the invoice in one database transaction; rejecting it
does not change invoice payment data.

Apply
`ITPROFEL/SALES/database/migrations/20261120_add_customer_payment_submissions.sql`
once to the shared `team_sales` database. Ensure the web server can write to
`Customer-System/storage/payment-proofs`; files are served only through the
authorized Sales receipt endpoint.

The page does not process a card or collect card numbers, expiration dates, or
CVV values. Existing gateway payment records are retained; gateway callbacks
are disabled.
