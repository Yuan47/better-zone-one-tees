<section class="panel catalog-lifecycle" aria-label="Product archive actions">
    @if($product->archived_at)
        <h2>Archived product</h2>
        <p class="muted">Archived {{ $product->archived_at->format('M j, Y') }}. Restore it to edit or offer it again.</p>
        <div class="catalog-lifecycle-grid">
            <form method="post" action="{{ route('admin.products.restore', $product) }}" class="stack">
                @csrf
                <label>Reason for restoring *<input name="reason" required minlength="5" maxlength="1000"></label>
                <button class="button">Restore product</button>
            </form>
            <form method="post" action="{{ route('admin.products.destroy', $product) }}" class="stack">
                @csrf @method('DELETE')
                <p class="muted">Permanent deletion is available when the product has no order history or reserved pieces. Items linked to orders stay archived for traceable invoices.</p>
                <label>Reason for permanent deletion *<input name="reason" required minlength="5" maxlength="1000"></label>
                <label class="check"><input name="confirm_delete" type="checkbox" value="1" required> I understand this permanently deletes the archived product and its unused variants.</label>
                <button class="button catalog-delete">Delete permanently</button>
            </form>
        </div>
    @else
        <details>
            <summary>Archive this product</summary>
            <p class="muted">Remove this product from new purchases. You can restore it from Archived products.</p>
            <form method="post" action="{{ route('admin.products.archive', $product) }}" class="stack">
                @csrf
                <label>Reason for archiving *<input name="reason" required minlength="5" maxlength="1000"></label>
                <button class="button">Archive product</button>
            </form>
        </details>
    @endif
</section>
