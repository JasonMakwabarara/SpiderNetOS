# Procurement

Owns the single `vendors` table. Supplier identity is not split across spend and procurement.

Purchase orders are `draft`, `issued`, or `received`. A requisition link is optional. Issue is allowed only from `draft`. Goods receipts are recorded against an issued order's lines, and the order becomes `received` once every line is fully received. A supplier invoice raised from the order is matched against those receipts before it is posted.
