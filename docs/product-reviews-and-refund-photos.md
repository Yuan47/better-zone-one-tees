# Product reviews and private refund proof

Revision requested 2 November 2026. This describes the source change; hosted deployment and observations must be recorded separately.

## Refund request proof

A new refund request requires a supporting photo for both signed-in customers and signed-link guests. Browser forms mark the photo required, and the server rejects a missing or invalid image even if browser validation is bypassed. Allowed types remain JPG/JPEG/PNG/WebP, up to 3 MB and 6000 pixels on either side. A submitted storage path is not a substitute for an uploaded image.

Evidence stays in private storage and can be opened only by authorized store staff and the owner through the existing protected route. Customers submit it for their own order; guest submission requires the original valid signed invoice link. Neither customer nor guest submission creates a public photo link. Refund proof is not a public review attachment. Payment, delivery, request-window, remaining-refundable-value and owner-inspection safeguards still apply. A valid picture file does not prove the claim is true or guarantee approval.

The evidence route streams the authorized private file through the configured storage adapter, retaining inline display, no-store/private caching, noindex and nosniff headers. This also avoids a Windows file-readability metadata failure in BinaryFileResponse that prevented staff from opening otherwise readable proof files. Actual image bytes are checked in the regression test; access checks remain unchanged.

This change applies to new requests. Existing historical requests do not require a fabricated replacement photo. The owner's separate inspection workflow still permits its documented photo-or-recorded-reason rule. No financial, stock, inspection or historical review records are rewritten.

## Product review display

Each public product page displays Customer reviews below the item details and size chart. The summary, stars and review list come from completed, paid, customer-confirmed orders containing that product. The existing data model has one rating per order; the page labels that boundary instead of presenting an order rating as an independently submitted item rating. A mixed order can appear once on each product it contained. Multiple size/color lines for the same product do not count as separate reviews.

Written comments remain optional. Star-only submissions count and display without an invented written review. Review text is escaped, buyer identity is masked, and emails, invoice numbers, private photos and delivery addresses are not exposed. Star filtering and pagination change only which reviews are viewed. They do not create or edit a review, change an order, or alter the underlying summary population.

## Release and recovery

Deploy the reviewed controllers, service/model, views, stylesheet and additive FAQ migration together. Retain copies of the previous runtime files before replacing them. Apply only the new FAQ migration through the authorized hosting procedure; it adds two customer guides and refreshes exact old defaults while preserving custom answers and visibility. No schema changes or database reseeding are needed.

Verify the public product page, a valid rating filter, the empty-review state, and the stylesheet on the final domain. For refund form acceptance, use an isolated completed-order fixture to check that missing/invalid proof creates no refund and that a valid upload remains private. Do not create a refund against a real customer order just to test the new requirement. Keep the deployment and user-visible acceptance evidence distinct from isolated automated results.
