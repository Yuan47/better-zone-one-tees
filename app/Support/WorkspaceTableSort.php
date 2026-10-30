<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkspaceTableSort
{
    /** Only developer-defined expressions may enter an ORDER BY clause. */
    public static function apply(Builder $query, Request $request, array $columns, callable $default, string $prefix = ''): Builder
    {
        $sort = $prefix.'sort';
        $direction = $prefix.'direction';
        $data = $request->validate([
            $sort => ['nullable', 'string', Rule::in(array_keys($columns))],
            $direction => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        if (empty($data[$sort]) || empty($data[$direction])) {
            return $default($query);
        }
        $expression = $columns[$data[$sort]];
        // NULL values remain last in either direction. ID makes ties deterministic across pages.
        return $query->reorder()->orderByRaw('('.$expression.') IS NULL ASC')
            ->orderByRaw('('.$expression.') '.$data[$direction])->orderBy($query->getModel()->qualifyColumn('id'));
    }

    public static function header(Request $request, string $column, string $type = 'text', string $prefix = ''): array
    {
        $sort = $prefix.'sort';
        $direction = $prefix.'direction';
        $current = $request->query($sort) === $column ? $request->query($direction) : null;
        $first = $type === 'text' ? 'asc' : 'desc';
        $second = $first === 'asc' ? 'desc' : 'asc';
        $next = $current === $first ? $second : ($current === $second ? null : $first);
        $label = static fn ($value) => $value === null ? 'Default' : ($type === 'text'
            ? ($value === 'asc' ? 'A to Z' : 'Z to A')
            : ($type === 'date' ? ($value === 'asc' ? 'Oldest to newest' : 'Newest to oldest')
                : ($value === 'asc' ? 'Lowest to Highest' : 'Highest to Lowest')));
        $parameters = $request->query();
        unset($parameters['page'], $parameters['staff_page'], $parameters['setup_page'], $parameters[$sort], $parameters[$direction]);
        if ($next !== null) {
            $parameters[$sort] = $column;
            $parameters[$direction] = $next;
        }

        return ['url' => $request->url().($parameters ? '?'.http_build_query($parameters) : ''),
            'current' => $label(in_array($current, ['asc', 'desc'], true) ? $current : null), 'next' => $label($next),
            'aria' => $current === 'asc' ? 'ascending' : ($current === 'desc' ? 'descending' : 'none')];
    }
}
