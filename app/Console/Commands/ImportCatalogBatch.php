<?php

namespace App\Console\Commands;

use App\Services\CatalogBatch;
use Illuminate\Console\Command;

class ImportCatalogBatch extends Command
{
    protected $signature = 'shop:import-catalog';

    protected $description = 'Add missing permanent catalog products and variants without changing owner updates.';

    public function handle(CatalogBatch $catalog): int
    {
        $counts = $catalog->import();
        $this->info($counts['products_created'].' products and '.$counts['variants_created'].' variants added.');
        $this->info($counts['samples_archived'].' original sample products archived. Existing prices, stock and images were preserved.');
        $this->line('New variants start with zero stock. Enter physical inventory counts before accepting orders.');

        return self::SUCCESS;
    }
}
