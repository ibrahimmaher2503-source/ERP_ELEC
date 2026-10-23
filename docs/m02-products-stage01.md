# M02 Products — executable master-data contract

Authority: owner's 2026-10-21 Complete Product Master request supersedes this document's previous Stage01-only preparation and approves S01–S07. Owner explicitly removed Accounting dependency: create, activate, edit and future import/export require no tax profile, catalog draft or tax-readiness gate. Transaction tax belongs to Sales/Purchasing. Import/export screens are not implemented by this catalog request.

## Persisted rules

Categories have canonical unique code, name, parent hierarchy, active/version. Serialized Products write locking prevents cycle races; self/circular parenting rejected. Brands have stable code/name, active/version. Units have code/name, dimension count/length/mass/volume/time, active/version. No global unit/category/sample seed and no hard-delete path. Referenced masters deactivate only.

Products have stable ID, canonical uppercase NFC unique SKU, name, optional English name/descriptions, approved kind serialized_equipment/bulk/service, independently selected tracking serial/quantity/none, one base unit, optional primary category/brand/model, and one optional printable-ASCII barcode. Barcode ownership remains unique across archived records; no EAN/GS1 generation or inferred tracking mapping.

Explicit positive NUMERIC(18,8) alternative-unit-to-base factors must share the base dimension. Each alternative maps directly to base; no chained graph or float. Public BCMath conversion rejects overflow and results requiring rounding. SKU/base unit/kind/tracking and saved factors are immutable through generic editing. Relaxation needs a future reference-aware identity-change operation, not a fabricated transactional-reference check.

Specifications are bounded structured name/value/optional unit metadata, escaped in Blade. No category-dependent required schema invented. Internal notes have separate view/update privileges and stay outside public DTOs, normal model serialization and audit snapshots.

Private product_media uses configured local storage, generated paths and sanitized display filenames. JPEG/PNG/WebP/PDF, maximum 5 MiB/file and 20 active files; image dimensions at most 6000×6000. MIME/extension and current read/download authorization are enforced. First active image is the cover. Removal retires metadata while retaining files/history.

## Actions, authorization and public contracts

ProductMasters.save, ProductActions.save/changeStatus and ProductMediaActions.upload/remove own mutations with current OrganizationApi authorization, atomic persistence, optimistic versions and safe synchronous System audit bridge. ProductActions.saveWithMedia atomically saves the form and its attachments and cleans stored files on rollback. M02.product_view/create/update/status, category_manage, brand_manage, unit_manage, media_manage and notes_view/update are explicit company-scope capabilities registered in M01, with no automatic role grants or finance/cost authority.

Readonly scalar ProductsApi methods getProduct, listMasters, findByBarcode and convertQuantity expose allowlisted identity, not Eloquent, notes, prices, tax profiles or stock. Selectors cap pages at100. No foreign business model queries. Commercial resolvePrice is unavailable pending its own contract.

## UI and delivery boundary

Nine /products routes provide catalog with persisted search/filter/sort/pagination, recursive category tree with direct counts, master list/create/edit/details/status, full-page shared product create/edit with unsaved preview, details and protected media. Existing shell/Flux/panels/tables/status/information rows are reused. Price/Inventory panels display unavailable integration, with no fake values. No tax profile input or readiness restriction exists.

Migrations070–073 add only required Products tables, canonical identity constraints and explicit optional purchase/sale units. Each trade unit defaults to base when unspecified; an alternative requires an explicit direct factor. Saved trade-unit definitions are stable. Brand names reject case/trim duplicates. Existing M01 data is preserved. Essential checks are recorded in .ai/TEST_RESULTS.md. Full QA, Playwright, screenshots and production acceptance remain deferred. See EXECUTION_PIPELINE.md for slice receipts.
