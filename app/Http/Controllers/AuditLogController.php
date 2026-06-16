<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $action = (string) $request->query('action', '');
        $search = (string) $request->query('q', '');

        $logs = AuditLog::with('user')
            ->when($action !== '', fn ($q) => $q->where('action', $action))
            ->when($search !== '', fn ($q) => $q->where('action', 'like', "%{$search}%"))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action');

        return view('audit-logs.index', compact('logs', 'actions', 'action', 'search'));
    }
}
