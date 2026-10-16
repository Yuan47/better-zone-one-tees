<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public const ACTIONS = [
        'auth.login' => 'Signed in',
        'auth.logout' => 'Signed out',
        'auth.registered' => 'Created an account',
        'auth.email_verified' => 'Verified account email',
        'auth.password_reset' => 'Recovered account password',
        'staff.invited' => 'Prepared a staff account',
        'staff.activated' => 'Activated a verified staff account',
        'staff.code_sent' => 'Requested a staff setup code',
        'account.updated' => 'Updated account',
        'account.password_changed' => 'Changed password',
        'cart.added' => 'Added to cart',
        'cart.updated' => 'Updated cart quantity',
        'cart.removed' => 'Removed from cart',
        'wishlist.added' => 'Added to wishlist',
        'wishlist.removed' => 'Removed from wishlist',
        'order.created' => 'Created an order',
        'order.status_changed' => 'Changed order status',
        'order.received' => 'Customer confirmed delivery and reviewed order',
        'order.delivery_booked' => 'Booked a delivery',
        'order.delivery_reconciliation_required' => 'Delivery booking needs reconciliation',
        'order.delivery_reconciled' => 'Verified delivery recovery',
        'order.payment_reconciled' => 'Verified payment status',
        'invoice.payment_received' => 'Recorded payment received',
        'invoice.due_changed' => 'Logged agreed invoice deadline',
        'invoice.terms_changed' => 'Changed installment terms',
        'refund.requested' => 'Requested a refund',
        'refund.reviewed' => 'Reviewed a refund request',
        'refund.verified' => 'Verified refund with PayMongo',
        'refund.cash_recorded' => 'Recorded cash refund received',
        'product.created' => 'Created a product',
        'product.updated' => 'Updated a product',
        'variant.created' => 'Created a variant',
        'variant.updated' => 'Updated a variant',
        'catalog.color_added' => 'Added a product color',
        'settings.updated' => 'Updated store information',
        'faq.created' => 'Created an FAQ',
        'faq.updated' => 'Updated an FAQ',
        'faq.deleted' => 'Deleted an FAQ',
        'faq.imported' => 'Imported FAQs',
        'reports.exported' => 'Exported a sales report',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function label(): string
    {
        return self::ACTIONS[$this->action] ?? 'Store activity';
    }

    public function targetLabel(): string
    {
        $label = match ($this->target_type) {
            'account' => 'Account', 'product' => 'Product', 'variant' => 'Variant', 'order' => 'Order',
            'faq' => 'FAQ', 'faq_collection' => 'FAQ collection', 'store' => 'Store information', 'report' => 'Sales report',
            'refund' => 'Refund request',
            'staff_invitation' => 'Staff setup',
            default => '—',
        };

        return $this->target_id ? $label.' #'.$this->target_id : $label;
    }

    public function details(): array
    {
        $data = $this->metadata ?? [];
        $details = [];
        if (! empty($data['fields'])) {
            $details[] = 'Changed: '.implode(', ', array_map(fn ($field) => str_replace('_', ' ', $field), $data['fields']));
        }
        if (isset($data['quantity'])) {
            $details[] = isset($data['previous_quantity']) ? 'Quantity: '.$data['previous_quantity'].' → '.$data['quantity'] : 'Quantity: '.$data['quantity'];
        }
        if (isset($data['cart_quantity'])) {
            $details[] = 'Cart: '.$data['cart_quantity'].' pieces';
        }
        if (isset($data['status'])) {
            $details[] = 'Status: '.(isset($data['previous_status']) ? str_replace('_', ' ', $data['previous_status']).' → ' : '').str_replace('_', ' ', $data['status']);
        }
        if (isset($data['reason'])) {
            $details[] = 'Reason: '.$data['reason'];
        }
        if (isset($data['amount'])) {
            $details[] = 'Amount: ₱'.number_format($data['amount'] / 100, 2);
        }
        if (isset($data['method'])) {
            $details[] = 'Method: '.($data['method'] === 'cash' ? 'Cash received' : 'PayMongo');
        }
        if (isset($data['due_at'])) {
            $details[] = 'Agreed deadline: '.$data['due_at'];
        }
        if (isset($data['delivery_method'])) {
            $details[] = 'Delivery: '.($data['delivery_method'] === 'lalamove' ? 'Lalamove' : 'Store pickup');
        }
        if (isset($data['variant_count'])) {
            $details[] = 'Created '.$data['variant_count'].' size variants';
        }
        if (isset($data['imported_count'])) {
            $details[] = 'Imported '.$data['imported_count'].' FAQs';
        }
        if (isset($data['format'])) {
            $details[] = 'Format: '.strtoupper($data['format']);
        }

        return $details;
    }
}
