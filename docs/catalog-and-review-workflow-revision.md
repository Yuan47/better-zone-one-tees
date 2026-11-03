# Catalog and review workflow revision

Prepared locally on 3 November 2026. The user requested background work and deferred hosted publication. This document describes the prepared source; it is not evidence of a hosted deployment.

## Dannon necklines and existing records

The adult combined Dannon Round Neck / V-Neck product and the kids combined product become separate Round Neck and V-Neck items. Lucky Hanna already has separate V-Neck and With Collar products, so its product definitions and prices are unchanged.

Existing Round Neck product and variant identities, stock, reservations, original SKUs, order links, ratings and historical item snapshots remain intact. Legacy combined slugs remain valid for those records. New V-Neck siblings copy the actual size/color choices, three price tiers, chart and photo references, but start with zero stock and reservations. Copying a catalog definition does not create clothing or past sales.

Old combined invoices cannot identify how many pieces were specifically V-Neck. They are not retroactively split or copied into a V-Neck sales history. New V-Neck forecasts need their own observed paid-sales history. An occupied destination slug stops the split for review rather than overwriting a different product. The split is transactional and repeat-safe.

The additive catalog importer preserves owner changes and supports a fresh installation. A dedicated migration and `shop:split-dannon-necklines` command apply the split to existing installations. Neither may be run on the hosted store while publication is deferred. Before applying it, preserve a protected database backup and identify any pending customer carts: an old cart cannot establish which neckline the buyer meant. Staff should confirm those selections with the customer when needed.

## One color list

White is selected like any other color in the storefront and owner variant forms. A separate White/Colored selector is removed. The owner still sets prices per actual size and color, so removing the grouping does not equalize prices.

For a single variant, enter Size, Color, tier prices, counted stock and reorder threshold. For a new color across sizes, select the existing color to copy from. Only that color's size/price choices are copied, with zero new stock. Automatic SKU generation, duplicate checks, image validation, stock commitments and stale-stock-form checks remain in force.

The historical `color_group` database field remains for compatibility and is derived internally from the actual color. It is not a required owner decision or an additional customer filter. Removing a selector does not require dropping a populated database column.

## Receipt confirmation and reviews

Customer receipt confirmation remains the place to submit stars and an optional written review. It is available only for an eligible fully paid online order, after verified courier completion or ready pickup. The required receipt checkbox remains. There is no separate public form for reviewing a purchase that the customer did not make.

The product page shows verified completed-purchase ratings, including star-only entries. The existing rating is for the whole order, so an order containing several products can appear once on each product's review section. Buyer names are masked, written feedback is escaped, and private return evidence is not published. A refund does not remove a negative review.

Staff and owners can read Customer reviews in the workspace, filter the list, and open the related order. The page is read-only; it does not approve refunds or let store personnel replace a customer's rating. Customer and guest access to that internal workspace remains blocked.

## Required refund proof

The preceding source revision requires a valid private picture for a new customer or signed-guest refund request. JPG/JPEG/PNG/WebP up to 3 MB and 6000 pixels per side are accepted. A reason, full payment, completed receipt and the existing refund eligibility rules remain necessary. A photo alone does not establish approval or money returned.

Store staff and the owner use the protected refund-evidence route to open the proof. Public reviews never include these pictures. Old refund records are retained, and the owner's separate physical-inspection evidence rule remains unchanged.

## Acceptance and publication

Automated checks use disposable data and simulated provider responses. Record the exact source revision, tests and any known limits in the release evidence. Hosted migration, file replacement, visual phone/tablet checks and live website observations remain separate tasks after the user asks to publish. Do not claim that local test results mean these changes are already present at the public domain.
