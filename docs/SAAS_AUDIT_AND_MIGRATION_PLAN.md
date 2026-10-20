# Quotation SaaS Source Audit and Migration Plan

## Scope and baseline

- Repository: `balaji-quotation/balaji-quotation`
- Working branch: `development` (created from the repository's current `main` snapshot because no development branch existed)
- Audited source ref: `main`, commit `c96687e5d167456b5417e2add20770b7ae6acf86`
- Runtime target: PHP 8.2 and MySQL
- Database migration execution: explicitly gated on the owner's approval after the migration scripts and their dry-run evidence are reviewable.
- No database credentials, customer records, dumps, or secrets are included in this plan.

## Existing behavior to preserve

The current application includes quotation creation, editing, viewing, printing, revisions, dynamic quotation columns and rows, commercial/general terms, customer selection, and user roles. Existing quote numbers, IDs, child rows, terms, and revision links must remain intact.

Approved client behavior:
- A company user can view and print every quotation belonging to their own company.
- A company user can edit only quotations they created.
- New quotation entry supports selecting a company customer or quick-adding one inline. Required quick-add fields are customer/company name, billing address, and at least one of mobile or alternate mobile; other customer details are optional. A saved customer is selected immediately.
- Product rows permit manual entry as well as selecting previously saved products, subject to the existing quotation template.

Approved client dashboard menus: Dashboard, Customers, Quotations, Template Change Request, Company Profile (read-only), My Profile.

Approved Super Admin dashboard menus: Dashboard, Manage Companies, Manage Users, Quotation Templates, Change Requests, Subscriptions.

## Audit findings from the available source

1. The repository currently exposes only `main`; no `development` branch existed when checked. The new `development` branch now exists and is the sole target for project changes.
2. The existing quotation implementation is substantial: quotation save/update, list, view, print, revision, and revision-save files contain the existing workflows and must be extended in place or through compatible shared helpers.
3. The inspected quotation list/view/print and save/update/revision endpoints identify quotation records by ID and do not apply a company/tenant filter. A logged-in user can therefore reach records outside any future tenant boundary unless each query is scoped server-side.
4. Several quotation pages and handlers check only for a logged-in session. Permission checks used by the sidebar do not secure direct requests. Server-side route checks and record ownership checks are required.
5. The inspected customer save/update endpoints likewise check login without enforcing their corresponding permissions. Mutating endpoints must enforce permission and tenant ownership independently of page visibility.
6. CSRF checks exist in important quotation mutation handlers; the conversion must preserve and standardize those checks across all new and modified POST actions.
7. The repository deliberately excludes `includes/db.php`, `.env`, SQL dumps, uploads, logs, and backups through `.gitignore`. Keep these exclusions; do not add credentials, customer data, database exports, or local login files.
8. The legacy database export is separate from GitHub and includes quotation tables plus other ERP tables and relationships. Preserve all existing tables and records; do not use destructive table rebuilds, truncation, or ID renumbering as a migration strategy. The export indicates quotation revision linkage and child-table foreign keys that must be preserved.

This is a source-level audit of the committed baseline, not a production database inspection. The currently available local execution environment could not run PHP or MySQL tests, so runtime verification remains a required implementation gate.

## Implementation sequence

1. Create and review a schema inventory and record-count baseline from the supplied legacy database export, without exposing row contents. Map the existing single-company records to the initial SaaS company using an explicit migration rule.
2. Add the company master and tenant/user relationships additively. Design the migration so existing IDs and quotation relationships remain unchanged; keep legacy tables and fields.
3. Add shared authentication, role/permission, tenant-scope, and ownership helpers. Enforce tenant scope in every customer, product/template, quotation, revision, print, and related endpoint that reads or mutates tenant data.
4. Implement the approved Super Admin and client menus and company management. Keep client Company Profile read-only. Add user/company lifecycle and subscription behavior only according to the approved requirements.
5. Adapt quotation workflows while preserving their existing behavior, including quick-add customer, manual or saved product rows, templates, terms, totals, printing, and revisions.
6. Prepare migration and rollback scripts, automated dry-run checks, and a PHP 8.2/MySQL regression suite. Run those against a restored copy of the backup before considering any live migration.
7. Present the exact migration, before/after checks, and rollback evidence for review. Execute the database migration only after the owner's explicit approval.

## Migration safety checks and approval gate

Before any live migration is run:

- Confirm the target database identity and engine/version without logging credentials.
- Verify that the supplied backup can be restored to a separate test database; record backup timestamp, size, and checksum outside Git.
- Record row counts for every table and a schema/foreign-key/index inventory. Do not export customer row contents into reports or commits.
- Confirm a deterministic mapping of all existing users, customers, products, quotations, quotation child rows, terms, activity, and revision chains to the initial company. Stop if any row has no unambiguous owner.
- Run the migration on a restored copy first. Check it is repeat-safe or fails safely before applying any mutation, and test interruption/rollback behavior.
- Compare every table's row count and primary-key set before and after. Validate quotation numbers, totals, child rows, terms, revision parent links, customer links, and foreign-key integrity.
- Exercise legacy login and quotation create/edit/view/print/revise flows, then test two-company isolation and the rule that only a quotation's creator may edit it.
- Confirm that no database config, credentials, SQL dump, customer data, backup, or log is staged for commit.
- Do not run the live migration until the owner has reviewed the concrete scripts and dry-run evidence and explicitly approved execution.
