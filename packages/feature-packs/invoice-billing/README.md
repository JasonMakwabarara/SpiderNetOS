# Invoice billing

Extends the existing invoice. Adds `purchase_order_id`, `fiscal_status`, and `fiscalised_at`. Credit notes have line items. Issued credit notes must use the invoice currency, and their sum cannot exceed the invoice total.
