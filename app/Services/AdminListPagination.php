<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminListPagination
{
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public static function paginate(
        Request $request,
        string $sessionKey,
        Builder $query,
        array $filters = [],
    ): LengthAwarePaginator {
        $remembered = $request->session()->get($sessionKey, []);
        $perPage = $request->integer('per_page', $remembered['per_page'] ?? self::PER_PAGE_OPTIONS[0]);

        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::PER_PAGE_OPTIONS[0];
        }

        $total = $query->toBase()->getCountForPagination();
        $lastPage = (int) ceil($total / $perPage);
        $page = $request->integer('page', $remembered['page'] ?? 1);
        $page = max(1, min($page, $lastPage));

        $state = ['per_page' => $perPage, ...$filters];
        $request->session()->put($sessionKey, ['page' => $page, ...$state]);

        return $query->paginate($perPage, page: $page, total: $total)->appends($state);
    }
}
