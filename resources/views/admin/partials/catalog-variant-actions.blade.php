<details class="catalog-variant-actions">
    <summary>{{ $variant->archived_at ? 'Archive actions' : 'Manage' }}</summary>
    @if($variant->archived_at)
        <form method="post" action="{{ route('admin.variants.restore', [$product, $variant]) }}" class="stack">
            @csrf
            <label>Reason for restoring *<input name="reason" required minlength="5" maxlength="1000" aria-label="Reason for restoring {{ $variant->sku }}"></label>
            <button class="button small" @disabled($product->archived_at)>Restore variant</button>
        </form>
        <form method="post" action="{{ route('admin.variants.destroy', [$product, $variant]) }}" class="stack">
            @csrf @method('DELETE')
            <label>Reason for permanent deletion *<input name="reason" required minlength="5" maxlength="1000" aria-label="Reason for deleting {{ $variant->sku }}"></label>
            <label class="check"><input name="confirm_delete" type="checkbox" value="1" required> Permanently delete this archived variant.</label>
            <button class="button small catalog-delete">Delete permanently</button>
        </form>
    @else
        <form method="post" action="{{ route('admin.variants.archive', [$product, $variant]) }}" class="stack">
            @csrf
            <label>Reason for archiving *<input name="reason" required minlength="5" maxlength="1000" aria-label="Reason for archiving {{ $variant->sku }}"></label>
            <button class="button small">Archive variant</button>
        </form>
    @endif
</details>
