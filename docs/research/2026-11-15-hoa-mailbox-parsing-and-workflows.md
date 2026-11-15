# HOA mailboxes (Invoice, Legal, Closing, Insurance): findings, parsers, workflows

Date: 2026-11-15. Data: all 321 emails in the four wozig mailboxes on the local ddev site (Invoice 150, Insurance 78, Closing 67,
Legal 26), text fetched read-only from Zoho. Nothing was written to Zoho or any live site.

## What the mailboxes really contain

wozig is a management company: these mailboxes hold the work of running communities (mostly Fountaingrove, Mallard Forest, Luxity
Terraces VI, Grenelefe), not owner mail.

| Mailbox | What arrives | Key fields |
|---|---|---|
| Invoice | vendor invoices (QuickBooks / Intuit "Your invoice is ready! BALANCE DUE"), utility e-bills (Charlotte Water lists several accounts per email; Duke Energy), staffing and landscaping, our own replies "Paid via ACH ... Memo: Invoice #10625", payment requests via Encircle, forwarded vendor threads | vendor, invoice #, amount, due date, community, utility account #, work order / PO |
| Closing | title attorneys, Rexera and lenders asking for HOA information, resale documents, statement of unpaid assessments; our fee quote ($250 standard, +$100 expedited, +$250 lender questionnaire); our Stripe invoice for the closing package; payment; documents sent; closing statement, recorded deed and buyer form; terminated contracts | property address, requester, fee, closing date ("CLOSING 11/20"), invoice #, rush |
| Legal | collections law firm asking for a homeowner's ledger (owner, address, HOA), counsel opinions on governing documents, bankruptcy of a community, litigation (case 26CV007227-590, settlement, legal invoice), a restoration vendor threatening a lien; plus collections-software sales and meeting invites | case #, owner, address, deadline, amount |
| Insurance | Honeycomb master policy (premium invoice, cancellation and rescission notices with the cancel date and premium owed), ACH debit authorizations and receipts, Travelers certificates, Cincinnati and Nationwide account mail, a premium-audit debt sent to collections | policy #, named insured (the community), premium, due / cancellation date |

## Parser design (class-hoa-mail.php, class-hoa-match.php)

- Each pipeline has a parser. It reads the newest message of a thread (the text before the quoted "From: ... Sent:" header), so a
  long reply chain is no longer classified by something quoted three replies down.
- Kinds: Invoice: invoice, utility, paid, receipt, past due, statement, quote, vendor thread. Closing: request, status check, docs
  sent, invoice, payment, approval, question, transfer (deed / buyer form), terminated, lender request. Legal: ledger request,
  litigation, legal bill, lien, collection, enforcement, bankruptcy, counsel, vendor. Insurance: premium, payment, cancel notice,
  renewal, certificate, collection, claim, account, promo.
- Mail that is not work (newsletters, webinars, Teams invites, login and paperless notices) is labelled **Notices**.
- "Paid via ACH ... Memo ..." in our own reply moves that invoice to **Paid** by itself (only from New; later stages are never
  overridden). The Zoho `PAID` label does the same.
- Community and unit come from the Homeowners directory when it has them. The directory is empty on this ddev site and on live, so today
  the association is taken from the text ("Fountain Grove HOA", "Bill to Fountaingrove HOA c/o ...", "Named Insured: ..."). Once communities and
  units are loaded, a street address in an email resolves to the unit and its current owner.
- A vendor or utility account that always bills one community is learned from earlier mail and fills in the rest.

## Accuracy on the real data (after learning)

| Pipeline | Emails | Work | Notices | Work with no community / property |
|---|---|---|---|---|
| Invoice | 150 | 139 | 11 | 47 (mostly "Paid via ACH" replies that carry no community) |
| Closing | 67 | 67 | 0 | 7 |
| Legal | 26 | 11 | 15 | 3 |
| Insurance | 78 | 47 | 31 | 20 (agency and Travelers mail) |

Gaps that cannot be closed from the text: **amounts are often only in the attached PDF** (56 invoices have no amount; PrideStaff invoices
and most vendor invoices); violation or hearing details in letters; the amount of a lien or ledger is in an attachment.

## HOA workflows to build on this (not built yet)

1. **Invoice to accounts payable.** New > Verified (community, vendor, amount match a work order or contract) > Approved (manager or board
   by dollar threshold) > Paid. Duplicate detection (same vendor and invoice #). Recurring vendors (landscaping, pool, pet waste) and
   utilities pre-approve when the amount is within a band. One tap from an invoice to the work order request that caused it (the
   parser already extracts "Work order #").
2. **Closing (resale) desk.** Request > fee quoted > invoice sent (Stripe) > paid > documents sent > closing confirmed > **Record the sale**
   (the unit's owners change, new-owner welcome). Clock per request (standard 2 weeks, expedited 48 hours); rush and "closing 11/20"
   become a due date; status-check emails surface as urgent; terminated contracts close the request.
3. **Legal matters.** One matter per case # or per delinquent unit: ledger request > demand > lien > payment plan or foreclosure. Hearing and
   response dates become Compliance reminders. Legal invoices go to Invoice. Bankruptcy and litigation are always urgent.
4. **Insurance calendar.** Per community: master, D&O, crime, flood; renewal 60 / 30 / 15 days, premium due, cancellation notices flagged red
   with the cancel date, vendor certificates on file with expiry and the "additional insured" check.
5. **Cross links.** Unit > owner > violations > legal matter > closing in one place; community > vendors > insurance.

## Decisions needed

1. Load the community and unit directory on wozig (Homeowners import) so units, owners and the Closing "Record the sale" action work.
2. Approval threshold for invoices that need the board.
3. Standard closing fee schedule ($250 / +$100 / +$250) as data, so quotes and invoices can be generated.
4. Whether collections (Axela) mail should stay out of Legal (it is now labelled Notices).
