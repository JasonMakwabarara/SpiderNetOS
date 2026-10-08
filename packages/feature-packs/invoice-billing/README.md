# Invoice billing

Extends the existing invoice. A supplier invoice can be raised from a purchase order, which links it through `purchase_order_id` and `vendor_id`, then matched against receipts and posted to payables. Credit notes have lines, start as drafts, and on issue post to the ledger; issued credit notes on an invoice cannot exceed its total.
