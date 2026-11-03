<?php

namespace App\Console\Commands;

use App\Services\DannonNecklineSplit;
use Illuminate\Console\Command;

class SplitDannonNecklines extends Command
{
    protected $signature = 'shop:split-dannon-necklines';

    protected $description = 'Separate existing adult and kids Dannon necklines without duplicating stock or changing old orders.';

    public function handle(DannonNecklineSplit $split): int
    {
        $counts = $split->apply();
        $this->info($counts['products_created'].' V-Neck products and '.$counts['variants_created'].' variants added.');
        $this->line('Original product IDs, stock, reservations and order history stay with Round Neck. New V-Neck stock starts at zero.');

        return self::SUCCESS;
    }
}
