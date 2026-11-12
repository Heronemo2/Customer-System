# STRIDE UI + Team Sales Customer Ordering Workflow

The original STRIDE HTML/CSS/JavaScript storefront structure and visual styling are retained. Its API connects to `team_sales` for customer data, prices, and sellable stock, and reads active finished goods from the Inventory System JSON API.

## Run locally
1. Extract the `stride` folder into XAMPP `htdocs`.
2. Configure MySQL credentials in `api/config.php`.
3. Configure `INVENTORY_API_BASE_URL=http://localhost/Inventory_System/api` and the shared `INVENTORY_API_KEY` in the Customer System Apache environment. The key must match Inventory's configured integration key.
4. Open `http://localhost/stride/stride/index.php` (adjust path to the extracted folder).

## Customer workflow
- Customer registration creates a `customers` record and a `users` record with role `Customer` in `team_sales`.
- Customer login accepts the Team Sales username or email.
- Product identity and active status come from Inventory's `items.php` API; Sales-owned prices and sellable stock come from the Team Sales `inventory` table. The storefront does not write to or query the Inventory database.
- Cart submission creates `customer_order_requests` records in `team_sales` with `Pending Quotation` status. It does not deduct inventory, charge payment, or directly create a sales order.
- Quotations created by Sales are shown under My requests & quotations. Customer can approve/reject pending quotations. Approval creates an open Purchase Order using the same Team Sales workflow as the original Customer System.
- Request/order tracking is displayed under the account page.

## Important
This customer storefront intentionally does not use the original `stride_store` database, payments, or direct sales-order checkout. Fixed product prices are managed in Team Sales inventory, displayed in the customer catalog, and used when Sales creates a quotation. Keep database credentials appropriate for your local setup and use HTTPS for production.
