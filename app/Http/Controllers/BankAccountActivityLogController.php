<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BankAccountActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewActivityLog', BankAccount::class);

        $query = ActivityLog::query()
            ->where('subject_type', BankAccount::class)
            ->when($request->from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($request->user_id, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($request->action, fn ($q, $v) => $q->where('action', $v))
            ->when($request->bank_account_id, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($request->search, fn ($q, $v) => $q->where(fn ($q2) => $q2
                ->where('description', 'LIKE', "%{$v}%")
                ->orWhere('subject_label', 'LIKE', "%{$v}%")))
            ->when($request->status && $request->status !== 'all', function ($q) use ($request) {
                $q->whereExists(function ($sub) use ($request) {
                    $sub->select(DB::raw(1))->from('bank_accounts')
                        ->whereColumn('bank_accounts.id', 'activity_logs.subject_id')
                        ->when($request->status === 'deleted', fn ($s) => $s->whereNotNull('bank_accounts.deleted_at'))
                        ->when($request->status === 'active', fn ($s) => $s->whereNull('bank_accounts.deleted_at')->where('bank_accounts.is_active', true))
                        ->when($request->status === 'inactive', fn ($s) => $s->whereNull('bank_accounts.deleted_at')->where('bank_accounts.is_active', false));
                });
            })
            ->orderByDesc('created_at');

        return Inertia::render('bank-accounts/ActivityLog', [
            'logs' => $query->paginate(20)->withQueryString()->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action->value,
                'action_label' => $log->action->label(),
                'actor_name' => $log->actor_name,
                'actor_role' => $log->actor_role,
                'subject_label' => $log->subject_label,
                'description' => $log->description,
                'changes' => $log->changes,
                'created_at' => $log->created_at,
                'bank_account_id' => $log->subject_id,
            ]),
            'filters' => $request->only(['from', 'to', 'user_id', 'action', 'bank_account_id', 'status', 'search']),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'bankAccountOptions' => BankAccount::withTrashed()->orderBy('bank_name')->get(['id', 'bank_name', 'account_name']),
            'actions' => collect(ActivityAction::cases())->map(fn (ActivityAction $a) => ['value' => $a->value, 'label' => $a->label()]),
        ]);
    }
}
