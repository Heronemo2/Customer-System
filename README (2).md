# STRIDE UI + Team Sales Customer Ordering Workflow

The original STRIDE HTML/CSS/JavaScript storefront structure and visual styling are retained. Its API now connects to the existing `team_sales` database and reads active catalog items from `inventory_system.finished_goods` (read-only).

## Run locally
1. Extract the `stride` folder into XAMPP `htdocs`.
2. Configure MySQL credentials in `api/config.php`.
3. Ensure the `team_sales` and `inventory_system` databases are available to the same MySQL account.
4. Open `http://localhost/stride/stride/index.php` (adjust path to the extracted folder).

## Customer workflow
- Customer registration creates a `customers` record and a `users` record with role `Customer` in `team_sales`.
- Customer login accepts the Team Sales username or email.
- Products are read from `inventory_system.finished_goods`; the storefront does not write to the Inventory System.
- Cart submission creates `customer_order_requests` records in `team_sales` with `Pending Quotation` status. It does not deduct inventory, charge payment, or directly create a sales order.
- Quotations created by Sales are shown under My requests & quotations. Customer can approve/reject pending quotations. Approval creates an open Purchase Order using the same Team Sales workflow as the original Customer System.
- Request/order tracking is displayed under the account page.

## Important
This customer storefront intentionally does not use the original `stride_store` database, payments, or direct sales-order checkout. Fixed product prices are managed in Team Sales inventory, displayed in the customer catalog, and used when Sales creates a quotation. `inventory_system.finished_goods` is read-only from this storefront. Keep database credentials appropriate for your local setup and use HTTPS for production.
