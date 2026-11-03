<?php

namespace App\Http\Controllers;

use App\Models\OrderReview;
use App\Models\Product;
use App\Support\WorkspaceTableSort;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
            'rating' => 'nullable|integer|between:1,5',
        ]);
        $query = OrderReview::query()->verifiedPurchase()->whereHas('order.items.variant.product')
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->forProduct((int) $id))
            ->when($filters['rating'] ?? null, fn ($query, $rating) => $query->where('rating', $rating));
        $reviewCount = (clone $query)->count();
        $averageRating = (clone $query)->avg('rating');
        $products = Product::orderBy('name')->orderBy('id')->get(['id', 'name']);
        $firstProduct = '(SELECT MIN(products.name) FROM order_items JOIN variants ON variants.id = order_items.variant_id JOIN products ON products.id = variants.product_id WHERE order_items.order_id = order_reviews.order_id)';
        $reviews = WorkspaceTableSort::apply($query, $request, [
            'date' => 'order_reviews.created_at', 'rating' => 'order_reviews.rating',
            'product' => $firstProduct, 'customer' => '(SELECT LOWER(SUBSTR(TRIM(users.name), 1, 1)) FROM users WHERE users.id = order_reviews.user_id)',
            'review' => 'order_reviews.comment', 'order' => '(SELECT orders.number FROM orders WHERE orders.id = order_reviews.order_id)',
        ], fn ($query) => $query->orderByDesc('order_reviews.created_at')->orderByDesc('order_reviews.id'))
            ->with(['user:id,name', 'order:id,number', 'order.items:id,order_id,variant_id,name,size,color', 'order.items.variant:id,product_id', 'order.items.variant.product:id,name,slug,active'])
            ->paginate(20, ['order_reviews.id', 'order_id', 'user_id', 'rating', 'comment', 'created_at'])->withQueryString();

        return view('admin.reviews', compact('reviews', 'products', 'reviewCount', 'averageRating'));
    }
}
