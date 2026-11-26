<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A list filtered by its date, as QuickBooks' lists are: from and to (either may be left open, "All dates" when both
 * are), on the list's own date (issue date, payment date, delivery date ...). The column is named by the controller,
 * never by the request (CLAUDE.md rule 1); the dates arrive as bindings.
 */
trait FiltersByDate
{
    /**
     * The period asked for, checked: real dates, the end on or after the start.
     *
     * @return array{from: ?string, to: ?string}
     */
    protected function dateRange(Request $request): array
    {
        $q = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = $q['from'] ?? null;
        $to = $q['to'] ?? null;
        if ($from !== null && $to !== null && $to < $from) {
            throw ValidationException::withMessages(['to' => ['The period ends on or after it starts.']]);
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * The rows of the period on $column, a fixed column of the list's own table. A timestamp column is compared by its
     * date.
     *
     * @param  array{from: ?string, to: ?string}  $range
     */
    protected function withinDates(QueryBuilder $query, string $column, array $range, bool $timestamp = false): QueryBuilder
    {
        if ($range['from'] !== null) {
            $timestamp ? $query->whereDate($column, '>=', $range['from']) : $query->where($column, '>=', $range['from']);
        }
        if ($range['to'] !== null) {
            $timestamp ? $query->whereDate($column, '<=', $range['to']) : $query->where($column, '<=', $range['to']);
        }

        return $query;
    }
}
