# AJCore HOA roadmap

Created 2026-11-06. Numbers are stable; do not renumber.

AJCore HOA is a private extension for the wozig.com site (AJ Core + AJ Core HOA, no AJOps today). **Version 0.1.0 is a roadmap only**: it registers with AJ Core, shows this plan under AJ Core > AJCore HOA, and does nothing else.

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

## Phase 1: violations

1. [ ] Violation request type: unit, owner, rule broken, photos, date reported.
2. [ ] Workflow: reported, courtesy notice, first notice, second notice, final notice, hearing, fine, resolved or closed.
3. [ ] Notice letters as email templates, filed to the owner's File Library.
4. [ ] Cure deadline with reminders (Compliance reminders).
5. [ ] Owner response and a photo of the fix.
6. [ ] Fines and a hearing step (fine amount goes onto the owner's ledger).

## Phase 2: requests and invoices

7. [ ] Repair / maintenance request workflow: request, vendor assigned, work done, pending vendor receipt, payment, closed.
8. [ ] Vendor invoice workflow variant: received, board approval above a set amount, paid.
9. [ ] Architectural change request (ARC): submit, committee review, approved or denied, with the decision letter filed.
10. [ ] Vendors list (contact, insurance on file, W-9).

## Phase 3: inspections

11. [ ] Inspection request type: scheduled and ad hoc, per unit or common area.
12. [ ] Checklist with photos.
13. [ ] A follow-up can open a violation or a repair request.

## Phase 4: compliance and community

14. [ ] HOA compliance templates: annual meeting, insurance renewal, reserve study, state filings (offered through "Start from template").
15. [ ] Units and owners directory.
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
