<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ActivityRecorder
{
    public const REQUEST_KEY = 'zone_one.activity';

    private const FIELDS = [
        'name', 'username', 'avatar_path', 'phone', 'street_address', 'city', 'province', 'postal_code', 'country', 'lat', 'lng',
        'description', 'active', 'image_path', 'size_chart', 'size_chart_note', 'size', 'color', 'color_group', 'stock', 'reorder_level',
        'retail_price', 'wholesale_price', 'reseller_price', 'question', 'answer', 'keywords', 'sort_order',
        'opening_hours', 'pickup_address', 'pickup_instructions', 'support_email', 'support_phone', 'store_note',
    ];

    private const STATUSES = ['awaiting_payment', 'to_pack', 'packing', 'ready', 'delivery_booking', 'delivery_attention', 'out_for_delivery', 'completed', 'cancelled'];

    /** Controllers mark only completed actions, after validation and actual changes. */
    public static function mark(Request $request, string $action, ?Model $target = null, array $metadata = []): void
    {
        if (! array_key_exists($action, ActivityLog::ACTIONS)) {
            return;
        }
        $events = $request->attributes->get(self::REQUEST_KEY, []);
        $events[] = [
            'action' => $action,
            'target_type' => self::targetType($target, $action),
            'target_id' => $target?->getKey(),
            'metadata' => $metadata,
        ];
        $request->attributes->set(self::REQUEST_KEY, $events);
    }

    public static function actor(?User $user): ?array
    {
        // Database defaults are not hydrated on a newly registered model.
        if ($user && $user->role === null && $user->exists) {
            $user = $user->fresh();
        }
        if (! $user || ! in_array($user->role, ['customer', 'staff', 'admin'], true)) {
            return null;
        }

        return ['id' => $user->id, 'name' => $user->name, 'role' => $user->role];
    }

    public function record(?User $actor, string $action, ?Model $target = null, array $metadata = []): ?ActivityLog
    {
        return $this->recordEvent(self::actor($actor), ['action' => $action, 'target_type' => self::targetType($target, $action),
            'target_id' => $target?->getKey(), 'metadata' => $metadata]);
    }

    public function recordEvent(?array $actor, array $event): ?ActivityLog
    {
        if (! $actor || ! in_array($actor['role'] ?? null, ['customer', 'staff', 'admin'], true)
            || ! array_key_exists($event['action'] ?? '', ActivityLog::ACTIONS)) {
            return null;
        }

        return ActivityLog::create([
            'actor_id' => $actor['id'], 'actor_name' => mb_substr($actor['name'], 0, 100), 'actor_role' => $actor['role'],
            'action' => $event['action'], 'target_type' => $event['target_type'] ?? null, 'target_id' => $event['target_id'] ?? null,
            'metadata' => $this->safeMetadata($event['action'], $event['metadata'] ?? []), 'occurred_at' => now('Asia/Manila'),
        ]);
    }

    private static function targetType(?Model $target, string $action): ?string
    {
        if ($target) {
            return match (class_basename($target)) {
                'User' => 'account', 'Product' => 'product', 'Variant' => 'variant', 'Order' => 'order', 'ChatbotFaq' => 'faq', 'Refund' => 'refund', default => null,
            };
        }

        return match ($action) {
            'settings.updated' => 'store', 'faq.imported' => 'faq_collection', 'reports.exported' => 'report', default => null,
        };
    }

    private function safeMetadata(string $action, array $metadata): array
    {
        $allowed = match ($action) {
            'account.updated', 'product.created', 'product.updated', 'variant.created', 'variant.updated', 'settings.updated', 'faq.created', 'faq.updated', 'faq.deleted' => ['fields'],
            'cart.added', 'cart.updated', 'cart.removed' => ['quantity', 'previous_quantity', 'cart_quantity'],
            'order.created' => ['quantity', 'delivery_method', 'status'],
            'order.status_changed', 'order.delivery_reconciled', 'order.payment_reconciled' => ['previous_status', 'status'], 'order.delivery_booked', 'order.delivery_reconciliation_required' => ['status'],
            'catalog.color_added' => ['variant_count'], 'faq.imported' => ['imported_count'], 'reports.exported' => ['format'],
            default => [],
        };
        $safe = [];
        foreach ($allowed as $key) {
            $value = $metadata[$key] ?? null;
            if ($key === 'fields' && is_array($value)) {
                $fields = array_values(array_unique(array_filter($value, fn ($field) => is_string($field) && in_array($field, self::FIELDS, true))));
                if ($fields) {
                    sort($fields);
                    $safe[$key] = $fields;
                }
            } elseif (in_array($key, ['quantity', 'previous_quantity', 'cart_quantity', 'variant_count', 'imported_count'], true) && is_int($value) && $value >= 0 && $value <= 10000000) {
                $safe[$key] = $value;
            } elseif (in_array($key, ['status', 'previous_status'], true) && in_array($value, self::STATUSES, true)) {
                $safe[$key] = $value;
            } elseif ($key === 'delivery_method' && in_array($value, ['pickup', 'lalamove'], true)) {
                $safe[$key] = $value;
            } elseif ($key === 'format' && $value === 'csv') {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
