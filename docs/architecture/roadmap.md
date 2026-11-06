# AJCore HOA roadmap

Created 2026-11-06. Numbers are stable; do not renumber.

AJCore HOA is a private extension for the wozig.com site (AJ Core + AJ Core HOA, no AJOps today). The plugin registers with AJ Core, shows this plan under AJ Core > AJCore HOA, and ships two request workflows (below). Everything else is still planned.

## How it fits

- **AJ Core** provides the engines every business can use: Requests with workflows, Compliance, invoices as a workflow, files, portal, payments, email, AJPhone, leads.
- **AJ Core HOA** adds only what is HOA specific: workflow templates, fields, screens and letters. Extensions register through AJ Core's filters (the same way AJ Core RA does), so nothing here forks AJ Core.
- **Requests are workflow driven.** A repair request comes in, a step is "pending receipt from vendor", payment is a step. Invoices are not a separate screen.
- Needs AJ Core extension API 24 or newer. Deploy order: AJ Core, then AJ Core HOA, then AJOps.

## Dependencies on AJ Core (build these first, in AJ Core)

D1. [ ] Requests engine with configurable workflows (steps, assignees, due dates, notes, attachments, vendor, cost, payment, history). Today AJ Core ships one generic pipeline and a read-only Workflows view; extensions add pipelines through `ajcore_service_status_pipelines`.
D2. [ ] Workflow editing in the UI (workflows stored in the database instead of code).
D3. [ ] Generic invoice workflow template (Received, Approved, Paid; vendor, amount, due date, attachments, payment method).
D4. [ ] Capabilities endpoint and AJOps site switcher, so AJOps can show an HOA module for wozig.

## How a request picks its workflow

A request uses a workflow when its request type is `hoa_violation` or `hoa_work_order_invoice`, or when its name contains "violation" (violations) or "work order", "repair" or "maintenance" (work orders). There is no HOA request-creation screen yet, so for now create or name requests that way. Status keys are prefixed `hoa_`. Needs AJ Core with the request workflow filters (ajcore_service_status_labels, _pipelines, ajcore_service_workflow_labels, ajcore_service_request_product_type, _quick_actions, _action_map).

## Request creation

Staff "New request" is generic and lives in AJ Core (WP admin Requests and AJOps Requests). HOA supplies the types (Violation, Work order to invoice to accounts payable) through the `ajcore_service_request_types` filter. Still planned: forms that create a request on submit, and the portal "Submit a New Request" with a type picker.

## Gmail Intake (idea, not decided)

If Gmail Intake moves back into AJ Core, HOA would add intake rules only: vendor invoice emails attach to a work order, violation reports from email become violations.

## Communities and homeowners (built, directory only)

Communities are AJ Core customers (use the Local type). **AJ Core > HOA Homeowners** lists the units and homeowners of each community: unit, owner, email, phone, mailing address, status (owner-occupied, rented, vacant), "OK to email", notes. Search, add, edit, bulk delete, CSV import and export. Stored in `{prefix}aj_hoa_units` on the site's WordPress database. No payments and no owner logins. Purpose: the recipient list for violation notices.

Next: pick the unit when creating a violation, send the notice by email (or print a mailing letter for owners with no email), and file a copy under the community.

## Phase 1: violations

1. [ ] Violation request type: unit (pick from the homeowners directory), rule broken, photos, date reported.
2. [x] Workflow (built, class-hoa-workflows.php): New, Courtesy Notice Sent, First Notice Sent, Final Notice Sent, Hearing Scheduled, Hearing Held, Fine Issued, Resolved. "Mark Resolved (cured)" ends it early. Appears under Requests > Workflows and gives a next-step button on each request.
3. [ ] Notice letters: email to the homeowner (or a printable mailing letter when there is no email), copy filed to the community.
4. [ ] Cure deadline with reminders (Compliance reminders).
5. [ ] Owner response and a photo of the fix.
6. [ ] Fines and a hearing step (fine amount goes onto the owner's ledger).

## Phase 2: requests and invoices

7. [x] Work order to invoice to accounts payable workflow (built): New, Vendor Assigned, Work In Progress, Work Complete, Invoice Received, Invoice Approved, In Accounts Payable, Paid, Closed.
8. [ ] Board approval step above a set dollar amount, added to the work order workflow.
9. [ ] Architectural change request (ARC): submit, committee review, approved or denied, with the decision letter filed.
10. [ ] Vendors list (contact, insurance on file, W-9).

## Phase 3: inspections

11. [ ] Inspection request type: scheduled and ad hoc, per unit or common area.
12. [ ] Checklist with photos.
13. [ ] A follow-up can open a violation or a repair request.

## Phase 4: compliance and community

14. [ ] HOA compliance templates: annual meeting, insurance renewal, reserve study, state filings (offered through "Start from template").
15. [x] Units and owners directory (built: AJ Core > HOA Homeowners; letters and violation link still to do).
16. [ ] Dues and assessments through Stripe.
17. [ ] Board and meetings: agendas and minutes.
18. [ ] Community documents (CC&Rs, rules, forms) in the File Library.
19. [ ] Announcements to owners.

## Phase 5: AJOps and iOS

20. [ ] HOA module in AJOps, shown only where AJ Core reports this extension.
21. [ ] HOA screens in the iOS app.

## Open questions

- Who sees what: do owners see violations and requests about their own unit only, and can they respond in the portal?
- Do vendors get a portal, or only receive email?
- Is the fine schedule fixed or set per rule?
- Board approval: one approver or a vote, and what is the dollar threshold?
- Do owners pay dues online, and how are late fees handled?

## Releasing

`./bin/build-release.sh` builds `releases/ajcore-hoa-<version>.zip` the same way AJ Core RA does (copied and renamed; it also commits, tags, pushes and publishes a GitHub release, and deploys to local ddev sites). Create the GitHub repo `ssnanda/ajcore-hoa` and run `git init` before the first run, or use `--no-push --no-github-release`. To install on wozig.com, upload the zip under Plugins, Add New.
