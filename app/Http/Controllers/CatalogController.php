<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Variant;
use App\Services\ActivityRecorder;
use App\Services\CatalogLifecycle;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function archiveProduct(Request $request, Product $product, CatalogLifecycle $catalog)
    {
        $reason = $this->reason($request);
        $catalog->archiveProduct($product);
        ActivityRecorder::mark($request, 'product.archived', $product, ['reason' => $reason]);

        return back()->with('notice', 'Product archived. Existing orders can still be completed.');
    }

    public function restoreProduct(Request $request, Product $product, CatalogLifecycle $catalog)
    {
        $reason = $this->reason($request);
        $catalog->restoreProduct($product);
        ActivityRecorder::mark($request, 'product.restored', $product, ['reason' => $reason]);

        return back()->with('notice', 'Product restored. Individually archived variants stay in the archive.');
    }

    public function deleteProduct(Request $request, Product $product, CatalogLifecycle $catalog)
    {
        $reason = $this->reason($request, true);
        $catalog->deleteProduct($product);
        ActivityRecorder::mark($request, 'product.deleted', $product, ['reason' => $reason]);

        return redirect()->route('admin.products', ['archived' => 1])->with('notice', 'Archived product deleted permanently.');
    }

    public function archiveVariant(Request $request, Product $product, Variant $variant, CatalogLifecycle $catalog)
    {
        $reason = $this->reason($request);
        $catalog->archiveVariant($product, $variant);
        ActivityRecorder::mark($request, 'variant.archived', $variant, ['reason' => $reason]);

        return back()->with('notice', 'Variant archived. Existing order records remain available.');
    }

    public function restoreVariant(Request $request, Product $product, Variant $variant, CatalogLifecycle $catalog)
    {
        $reason = $this->reason($request);
        $catalog->restoreVariant($product, $variant);
        ActivityRecorder::mark($request, 'variant.restored', $variant, ['reason' => $reason]);

        return back()->with('notice', 'Variant restored.');
    }

    public function deleteVariant(Request $request, Product $product, Variant $variant, CatalogLifecycle $catalog)
    {
        $reason = $this->reason($request, true);
        $catalog->deleteVariant($product, $variant);
        ActivityRecorder::mark($request, 'variant.deleted', $variant, ['reason' => $reason]);

        return back()->with('notice', 'Archived variant deleted permanently.');
    }

    private function reason(Request $request, bool $deleting = false): string
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $rules = ['reason' => 'required|string|min:5|max:1000'];
        if ($deleting) {
            $rules['confirm_delete'] = 'accepted';
        }
        $data = $request->validate($rules);

        return trim($data['reason']);
    }
}
