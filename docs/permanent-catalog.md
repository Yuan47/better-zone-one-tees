# Permanent product catalog

The owner-supplied product data is versioned in `database/data/catalog.json`. It is application source and belongs in GitHub, alongside its import command and regression tests. It is not a disposable set of placeholder products.

The catalog has 18 products and 1,049 size/color variants:

| Product family | Products | Variants |
| --- | ---: | ---: |
| Original Dannon, Lucky Hanna, and Thanos batch | 6 | 628 |
| Yalex Round Neck, Kids, V-Neck, and With Collar | 4 | 182 |
| Softex Round Neck, Kids, With Collar, and Long Sleeve | 4 | 161 |
| Flipper | 1 | 28 |
| Apple Tees Dri-Fit Round Neck, With Collar, and Plain/Reflector | 3 | 50 |
| Total | 18 | 1,049 |

The twelve added products use the supplied Wholesale and Retail/SRP prices exactly, including rows where their difference is not ₱10. Their Re-seller price is Retail/SRP minus ₱20. The earlier six products retain their original three prices.

For the added batch, Light Color means separate Light Green, Light Red, and Light Yellow variants; Dark Color means Dark Green, Dark Red, and Dark Yellow; Color/Colored means Green, Red, and Yellow. Plain and Reflector remain the two supplied options on the free-size Apple Tees product. Product names spell out Round Neck, V-Neck, With Collar, and Long Sleeve. Size labels and reference charts are managed separately; no unavailable size should be inferred just because a reference chart contains it.

## Apply to an existing installation

1. Take a protected database backup and a copy of uploaded product photos. Retain the deployed source revision and `.env` separately. Verify the intended database before changing it.
2. Deploy the reviewed source, including `database/data/catalog.json`. Apply only pending migrations if there are any.
3. Run `php artisan shop:import-catalog` against that installation. Do not use `migrate:fresh`, `db:wipe`, or account reseeding to add products.
4. Confirm the new products and size/color prices in the owner catalog. Check the command's added counts; they depend on what the installation already contains.
5. Enter physical inventory counts before accepting orders for newly added variants. The import sets every new variant's stock and reserved quantities to zero.

The import is additive. Existing products are matched by stable slug. Existing imported variants are matched by their original SKU, preserving owner-renamed sizes/colors; an already-created size/color with a custom SKU is retained as well. Missing variants receive an automatically generated unique SKU. Repeating the import adds nothing when all source rows are present, and leaves owner-edited names, descriptions, visibility, charts, prices, stock, reservations, and images unchanged. Existing invoices and their historical order items are not rewritten.

On its first run only, the command archives the eight original placeholder styles. Their rows, variants, and order history remain. This behavior does not archive the 18 permanent products.

## What GitHub backs up

GitHub keeps the permanent catalog's product definitions, price tables, colors, reference measurements, import logic, and source history. It does not contain the live database, uploaded photos, customer data, receipts, or `.env` credentials. Owner changes made through the website live in that installation's database, so retain database and upload backups in protected storage outside the repository. A source checkout alone cannot restore those operational records.

Before restoring a backup, stop conflicting writes and review the effect on later orders and payments. Test restoration in a separate environment first. The additive import does not replace a database restore or a full backup procedure.
