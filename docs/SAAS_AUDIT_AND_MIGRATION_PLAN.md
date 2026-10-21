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

## Owner-confirmed legacy company mapping

- The owner confirmed Balaji Webtech as the initial company for existing ERP records because the supplied SQL export and connected local database had no named `quotation_company_settings` row at `id=1`.
- The migration CLI accepts `--initial-company-name="Balaji Webtech"` for this owner-confirmed fallback. If the settings row is absent, apply creates the initial company and a minimal company-settings row; unavailable profile fields remain blank for the Super Admin to complete later.
- The initial read-only preflight against the local `quotations` database found zero company-settings rows and stopped safely. After the owner approved migration and confirmed the backup, the local MAMP database was migrated; verification is recorded below.

## Latest migration verification

- PHP 8.2 lint passed for `tools/migrate_multicompany.php`.
- The read-only preflight passed against the local `quotations` database on MySQL 5.7.44 when supplied the owner-confirmed mapping. It printed metadata-only table counts and made no changes.
- An isolated database clone was made from the local database; all 26 source table counts matched before migration. The migration applied and then applied a second time successfully on that clone.
- After both runs, all 25 legacy tables with pre-existing data retained their row counts, primary-key sets, and original column contents, including `updated_at`; tenant-owned rows had no null company mapping, and user/quotation template assignments were present.
- The local MAMP `quotations` database was migrated on 2026-10-21 after explicit owner approval, using the confirmed Balaji Webtech mapping. An isolated clone of the local database was migrated twice and verified; source-database post-migration checks show legacy row counts unchanged, all tenant mappings populated, and template assignments/snapshots present. The local company profile fields missing from the source remain blank for Super Admin completion.
- The owner confirmed a backup exists. A restore test of the attached SQL file was not completed; the export has the same table/count baseline but lacks the company-settings row. The source database migration therefore has clone-based preservation evidence, while independent backup-restore and application/print regression checks remain.

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
- Record row counts for every table and a schema/foreign-key/index inventory. The CLI preflight now prints row counts for all base tables using metadata-only counts; do not export customer row contents into reports or commits.
- Confirm a deterministic mapping of all existing users, customers, products, quotations, quotation child rows, terms, activity, and revision chains to the initial company. Stop if any row has no unambiguous owner.
- Run the migration on a restored copy first. Check it is repeat-safe or fails safely before applying any mutation, and test interruption/rollback behavior. The apply command also requires an explicit maintenance-mode confirmation, and it refuses to commit unless all tenant-owned rows have a non-NULL company mapping.
- Compare every table's row count and primary-key set before and after. Validate quotation numbers, totals, child rows, terms, revision parent links, customer links, and foreign-key integrity.
- Exercise legacy login and quotation create/edit/view/print/revise flows, then test two-company isolation and the rule that only a quotation's creator may edit it.
- Confirm that no database config, credentials, SQL dump, customer data, backup, or log is staged for commit.
- Do not run the live migration until the owner has reviewed the concrete scripts and dry-run evidence and explicitly approved execution. The CLI preflight remains read-only by default; applying requires `--apply --backup-verified --maintenance-confirmed`.


## Full PHP source pass

A static pass covered all 41 committed PHP files outside the bundled `dist/` template assets at the audited baseline. It confirmed these cross-cutting concerns:

- No application PHP file contains `company_id` or `tenant_id`; tenancy has not been implemented in the baseline.
- Only the user administration pages contain route-level `erpRequire()` calls. Most routes rely on login checks and conditional sidebar links, so direct URL access bypasses the menu as an authorization boundary.
- Quotation pages and handlers consistently check that a user is logged in. The save, update, and revision mutation handlers also validate CSRF tokens. Those controls should be retained while adding company and creator ownership checks.
- Client and product mutation handlers do not consistently enforce HTTP method, CSRF, or permission checks. User save/update handlers have POST checks but do not show CSRF validation. These require explicit controls during the SaaS conversion.
- Core quotation data is spread across the master quotation table and child tables for columns, rows, commercial terms, general terms, and revision lineage. All tenant checks and migration validations must cover this linked set.
- Existing non-quotation ERP pages query operational records such as purchase orders, dispatch, production, vendors, and clients. Keep those records and tables intact through the quotation SaaS migration; do not silently delete them.

The source pass does not substitute for executing the application or validating a restored database. No production database has been accessed or changed.


## Development implementation status

The development branch contains the multi-company migration and preflight tooling, Super Admin company/user/template/subscription/change-request flows, tenant-scoped company dashboards, customer management, quotation visibility and creator-only editing, immutable template snapshots, safe calculation formulas, and four print presets. The migration was applied to the local MAMP `quotations` database on 2026-10-21 after explicit owner approval and backup confirmation; it was not applied to a production server.

The migration was checked on an isolated clone and applied twice. All 25 pre-existing populated data tables retained row counts, primary-key sets, and original column values/timestamps. Tenant rows had no null company mappings, and quotation/template assignments were populated. The local post-migration checks passed. The supplied SQL export did not include a company-settings row; the migration accepted the owner-confirmed initial company name, `Balaji Webtech`, and left unavailable profile fields blank. An independent restore test of the supplied export was not performed.

Recent source-audit fixes on `development`:
- Quotation print branding now reads the company profile and no longer falls back to Unicorn Technoflex contact details or logos.
- Product listing, viewing, creation, editing, updating, and deletion now check company roles and company ownership. Product writes also honor read-only subscription state; create/update forms validate CSRF tokens.
- Legacy ERP search results are restricted through the current company's client records and product records. The legacy ERP dashboard now requires a verified company session.
- Legacy user-master routes remain blocked by empty permission grants; company-user lifecycle is managed through Super Admin routes.
- PHP 8.2 lint passed for all 11 source files changed in this pass. Static checks confirmed tenant insert/filter conditions, CSRF validation, and removal of legacy print branding.

Super Admin bootstrap remains a private setup action. `tools/create_super_admin.php` reads credentials from environment variables and does not print or store the password. No Super Admin credentials or customer data were added to GitHub.

## Remaining review gates

- Check real migrated quotations in browser print/PDF for portrait and landscape output, legacy totals behavior, GST, custom formulas, rounding, and all four presets.
- Run authenticated end-to-end checks using two companies: customer/product/quotation isolation, same-company quote visibility, creator-only edits, template snapshot stability, and read-only subscription behavior.
- Restore the supplied backup independently before any production migration. The current preservation proof came from an isolated clone of the connected local database, not from a restore of the attached SQL export.
- Create the first Super Admin using private credentials, then review the complete application on the development branch before deployment.

The `main` branch was not modified. No database credentials, customer records, backups, or secrets were committed.


## Follow-up requirement completion

- Quotation rows now have an optional company-scoped Product Master picker. It fills matching product code, part number, description, and default-rate columns; users can then edit those values before saving. The submitted product ID is checked against the current company and stored with the quotation row, while the copied row values remain the historical snapshot.
- Product Master supports an optional non-negative default rate. Company Master supports a validated company-specific number pattern (`{FY}`, `{YYYY}`, `{YY}`, `{SEQ}`, and `{SEQ:n}`) and an optional PNG/JPEG/WebP logo upload. Company profile changes sync to the print-facing settings. Uploaded logo files remain under the ignored `uploads/` directory.
- The safe migration now idempotently adds nullable `products.default_rate` and `companies.logo_path` columns. PHP 8.2 lint passed for all 18 changed/supporting PHP files. SQL placeholder/bind counts were checked for product create/update, quotation-row save, and company create/update; the counts match. Number-pattern formatting cases passed.
- Removed legacy Unicorn branding from the authenticated header, login screen, 404 title, and client GSTIN placeholders. New migration template terms use generic seller wording. PHP 8.2 lint passed for the six PHP files touched in this cleanup and migration-string correction.
- After owner approval and a successful read-only preflight, the development migration was applied to the local `quotations` database on 2026-10-21. Both new columns are present, all seven tenant tables have zero rows without a company owner, and row counts for the key legacy user, customer, product, quotation, quotation-row, and quotation-term tables match preflight. The migration reported no quotation IDs, numbers, or revision links rewritten. No database credentials or customer values were added to GitHub.
- The MAMP PHP checkout is still behind the current GitHub `development` source, so browser/runtime verification of the SaaS routes remains pending until that local code is synchronized. The migration was applied to the local development database only.

The development branch remains the only branch changed. The `main` branch is still at its original audited commit.