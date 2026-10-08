# Fiscalisation

Live fiscalisation talks to the ZIMRA FDMS device API over mutual TLS with the device certificate. It runs only when `FDMS_LIVE` is true, the configured tenant matches, and the device certificate and key are readable. An operator syncs the device configuration, opens the fiscal day, fiscalises the tenant's own sent or paid sales invoices, and closes the day.

Each invoice becomes one signed receipt in `fdms_receipts`: `pending`, `accepted`, or `rejected`. A pending receipt is resent with the identical payload, a rejected one is rebuilt, and device counters advance only on acceptance. A day with pending receipts cannot be closed.

Supplier invoices are never sent to FDMS: the supplier fiscalises them. A sandbox submission can be recorded for a posted supplier invoice; any other environment is refused.

Not yet built: device registration, discounts on fiscal receipts, and sales credit notes.
