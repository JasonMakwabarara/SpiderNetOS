# Cash

A cashbook has one currency. Movements are `receipt` or `payment` with an amount greater than zero and a movement date. Movement currency must match the cashbook. A payment against a supplier invoice settles it through payables, which posts to the ledger and refuses an amount above what is owed.
