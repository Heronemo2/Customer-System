-- Customer Ordering Portal
-- No duplicate customer, quotation, order, or product tables are created.
-- The portal intentionally uses the Team Sales database as its shared source.
--
-- Run this only if your Team Sales database is missing the supporting indexes.
-- The statements are safe to skip if the indexes already exist.

USE team_sales;

CREATE INDEX idx_customer_requests_portal
ON customer_order_requests(customer_id, status, req_id);

CREATE INDEX idx_sales_quotations_portal
ON sales_quotations(customer_id, customer_status, sq_id);

CREATE INDEX idx_purchase_orders_portal
ON purchase_orders(customer_id, quotation_id, status);

CREATE INDEX idx_sales_orders_portal
ON sales_orders(customer_id, quotation_id, status);
