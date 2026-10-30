<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\WorkspaceTableSort;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $rules = ['q' => ['nullable', 'string', 'max:100'], 'role' => ['nullable', Rule::in(['customer', 'staff', 'admin'])],
            'event' => ['nullable', Rule::in(array_keys(ActivityLog::ACTIONS))],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']];
        if ($request->filled('from')) {
            $rules['to'][] = 'after_or_equal:from';
        }
        $filters = $request->validate($rules);
        $query = ActivityLog::query();
        if ($request->filled('q')) {
            $term = trim($filters['q']);
            $events = array_keys(array_filter(ActivityLog::ACTIONS, fn ($label) => mb_stripos($label, $term) !== false));
            $query->where(function ($q) use ($term, $events) {
                $q->where('actor_name', 'like', '%'.$term.'%')->orWhere('action', 'like', '%'.$term.'%')->orWhere('target_type', 'like', '%'.$term.'%');
                if ($events) {
                    $q->orWhereIn('action', $events);
                }
                if (ctype_digit($term)) {
                    $q->orWhere('actor_id', (int) $term)->orWhere('target_id', (int) $term);
                }
            });
        }
        if ($request->filled('role')) {
            $query->where('actor_role', $filters['role']);
        }
        if ($request->filled('event')) {
            $query->where('action', $filters['event']);
        }
        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', CarbonImmutable::parse($filters['to'], 'Asia/Manila')->endOfDay());
        }
        $quote = static fn ($value) => "'".str_replace("'", "''", $value)."'";
        $actionLabel = 'CASE activity_logs.action';
        foreach (ActivityLog::ACTIONS as $action => $label) {
            $actionLabel .= ' WHEN '.$quote($action).' THEN '.$quote($label);
        }
        $actionLabel .= " ELSE 'Store activity' END";
        $targetLabel = "CASE activity_logs.target_type WHEN 'account' THEN 'Account' WHEN 'product' THEN 'Product' WHEN 'variant' THEN 'Variant'
            WHEN 'order' THEN 'Order' WHEN 'faq' THEN 'FAQ' WHEN 'faq_collection' THEN 'FAQ collection' WHEN 'store' THEN 'Store information'
            WHEN 'report' THEN 'Sales report' WHEN 'refund' THEN 'Refund request' WHEN 'staff_invitation' THEN 'Staff setup' ELSE '—' END";
        $targetWithId = $query->getConnection()->getDriverName() === 'sqlite'
            ? "(($targetLabel) || ' #' || activity_logs.target_id)"
            : "CONCAT(($targetLabel), ' #', activity_logs.target_id)";
        $target = "CASE WHEN activity_logs.target_id IS NULL OR activity_logs.target_id = 0 THEN ($targetLabel) ELSE $targetWithId END";
        $logs = WorkspaceTableSort::apply($query, $request, [
            'when' => 'activity_logs.occurred_at', 'actor' => 'activity_logs.actor_name', 'action' => $actionLabel, 'target' => $target,
        ], fn ($query) => $query->orderByDesc('occurred_at')->orderByDesc('id'))->paginate(25)->withQueryString();

        return view('admin.activity', ['logs' => $logs, 'filters' => $filters, 'events' => ActivityLog::ACTIONS]);
    }
}
