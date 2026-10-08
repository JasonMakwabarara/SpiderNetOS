# People

Enterprise Operations pack for the employee register.

HR enters a first name, surname, and position. SpiderNetOS assigns the employee number from the tenant prefix and the `employee` document sequence. The number is immutable. Status moves from active to inactive only through deactivation, which keeps the row for historical references.

The register supports search, profile editing, an append-only activity log, and CSV export of that log. Job descriptions can be suggested from the position title and then edited. Hours, punctuality, leave, shifts, payroll, and a formal positions model are not part of this pack.

Schema lives in Laravel migrations, not in this manifest.
