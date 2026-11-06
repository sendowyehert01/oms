<?php

namespace App\Http\Controllers;

use App\AuditAction;
use App\Http\Requests\AuditLogFilterRequest;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(AuditLogFilterRequest $request): View
    {
        $filters = $request->validated();
        $timezone = config('app.reporting_timezone');
        $query = AuditLog::query()->with('order:id');

        if (filled($filters['search'] ?? null)) {
            $query->whereRaw('LOWER(statement) LIKE ?', ['%'.mb_strtolower($filters['search']).'%']);
        }

        if (filled($filters['actor_id'] ?? null)) {
            $query->where('actor_id', $filters['actor_id']);
        }

        if (filled($filters['action'] ?? null)) {
            $query->where('action', $filters['action']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['date_from'], $timezone)->startOfDay()->utc());
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['date_to'], $timezone)->startOfDay()->addDay()->utc());
        }

        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();
        $actors = AuditLog::query()
            ->whereIn('id', AuditLog::query()->selectRaw('MAX(id)')->groupBy('actor_id'))
            ->select('actor_id', 'actor_name')->orderBy('actor_name')->get();

        return view('audit-logs.index', ['logs' => $logs, 'actors' => $actors, 'actions' => AuditAction::cases(), 'filters' => $filters, 'timezone' => $timezone]);
    }
}
