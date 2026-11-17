<?php

namespace App\Services;

use App\Models\Variant;

class InventoryWarnings
{
    public function counts(): array
    {
        $request = request();
        $cacheKey = self::class.'.counts';
        if ($request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey);
        }

        // Integer available counts at or below half the threshold are critical,
        // matching Variant::inventoryStatus without loading every variant.
        $counts = Variant::sellable()->whereRaw('stock - reserved <= reorder_level')
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN stock - reserved <= 0 OR (stock - reserved) * 2 <= reorder_level THEN 1 ELSE 0 END) AS critical')->first();
        $critical = (int) $counts->critical;
        $result = ['critical' => $critical, 'alert' => (int) $counts->total - $critical];
        $request->attributes->set($cacheKey, $result);

        return $result;
    }
}
