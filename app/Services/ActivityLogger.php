<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\SupportedCurrency;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\User;

class ActivityLogger
{
    /**
     * @var array<int, string>
     */
    private const TRACKED_FIELDS = [
        'bank_name', 'account_name', 'account_number', 'currency',
        'sort_code', 'routing_number', 'iban', 'swift_bic',
        'bank_address', 'bank_country', 'is_active',
    ];

    public function created(BankAccount $account, User $actor): void
    {
        $this->write($account, $actor, ActivityAction::Created, 'Created bank account', null);
    }

    /**
     * @param  array<string, mixed>  $originalAttributes
     * @param  array<int, int>  $originalUserIds
     */
    public function updated(BankAccount $account, User $actor, array $originalAttributes, array $originalUserIds): void
    {
        $currentUserIds = $this->resolveAssignedUserIds($account);
        $previousUserIds = collect($originalUserIds)->sort()->values()->all();

        $changes = $this->diffBankAccount($account, $originalAttributes, $currentUserIds, $previousUserIds);

        if (empty($changes)) {
            return;
        }

        $addedUserIds = array_values(array_diff($currentUserIds, $previousUserIds));
        $removedUserIds = array_values(array_diff($previousUserIds, $currentUserIds));

        $description = $this->buildUpdateDescription($changes, $addedUserIds, $removedUserIds);

        $this->write($account, $actor, ActivityAction::Updated, $description, $changes);
    }

    public function deleted(BankAccount $account, User $actor): void
    {
        $this->write($account, $actor, ActivityAction::Deleted, 'Deleted bank account', null);
    }

    public function statusChanged(BankAccount $account, User $actor, bool $wasActive, bool $isActive): void
    {
        if ($wasActive === $isActive) {
            return;
        }

        $action = $isActive ? ActivityAction::Activated : ActivityAction::Deactivated;

        $changes = [[
            'field' => 'is_active',
            'label' => $this->fieldLabel('is_active'),
            'before' => $wasActive ? 'Active' : 'Inactive',
            'after' => $isActive ? 'Active' : 'Inactive',
        ]];

        $this->write($account, $actor, $action, $action->label().' bank account', $changes);
    }

    /**
     * @param  array<string, mixed>  $originalAttributes
     * @param  array<int, int>  $currentUserIds
     * @param  array<int, int>  $previousUserIds
     * @return array<int, array{field: string, label: string, before: mixed, after: mixed}>
     */
    private function diffBankAccount(BankAccount $account, array $originalAttributes, array $currentUserIds, array $previousUserIds): array
    {
        $changes = [];

        foreach (self::TRACKED_FIELDS as $field) {
            $before = $originalAttributes[$field] ?? null;
            $after = $account->getAttribute($field);

            [$beforeValue, $afterValue] = $this->normalizePair($field, $before, $after);

            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $this->fieldLabel($field),
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        if ($currentUserIds !== $previousUserIds) {
            $changes[] = [
                'field' => 'assigned_users',
                'label' => $this->fieldLabel('assigned_users'),
                'before' => $this->userNames($previousUserIds),
                'after' => $this->userNames($currentUserIds),
            ];
        }

        return $changes;
    }

    /**
     * @return array<int, int>
     */
    private function resolveAssignedUserIds(BankAccount $account): array
    {
        return $account->assignedUsers()->pluck('users.id')->sort()->values()->all();
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private function normalizePair(string $field, mixed $before, mixed $after): array
    {
        if ($field === 'is_active') {
            return [$before ? 'Active' : 'Inactive', $after ? 'Active' : 'Inactive'];
        }

        if ($field === 'currency') {
            return [
                $before instanceof SupportedCurrency ? $before->label() : $before,
                $after instanceof SupportedCurrency ? $after->label() : $after,
            ];
        }

        return [$before, $after];
    }

    /**
     * @param  array<int, array{field: string, label: string, before: mixed, after: mixed}>  $changes
     * @param  array<int, int>  $addedUserIds
     * @param  array<int, int>  $removedUserIds
     */
    private function buildUpdateDescription(array $changes, array $addedUserIds, array $removedUserIds): string
    {
        if (count($changes) === 1 && $changes[0]['field'] === 'assigned_users' && (! empty($addedUserIds) || ! empty($removedUserIds))) {
            return $this->assignedUsersDeltaDescription($addedUserIds, $removedUserIds);
        }

        $labels = array_column($changes, 'label');
        $count = count($labels);

        if ($count === 1) {
            return "Updated {$labels[0]}";
        }

        if ($count <= 3) {
            return 'Updated '.collect($labels)->join(', ', ' and ');
        }

        return "Updated {$count} fields";
    }

    /**
     * @param  array<int, int>  $addedUserIds
     * @param  array<int, int>  $removedUserIds
     */
    private function assignedUsersDeltaDescription(array $addedUserIds, array $removedUserIds): string
    {
        if (! empty($addedUserIds) && ! empty($removedUserIds)) {
            return "Added {$this->userNames($addedUserIds)}, removed {$this->userNames($removedUserIds)} from assigned users";
        }

        if (! empty($addedUserIds)) {
            return "Added {$this->userNames($addedUserIds)} to assigned users";
        }

        return "Removed {$this->userNames($removedUserIds)} from assigned users";
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function userNames(array $userIds): string
    {
        if (empty($userIds)) {
            return 'None';
        }

        return User::whereIn('id', $userIds)->orderBy('name')->pluck('name')->join(', ');
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'bank_name' => 'Bank Name',
            'account_name' => 'Account Name',
            'account_number' => 'Account Number',
            'currency' => 'Currency',
            'sort_code' => 'Sort Code',
            'routing_number' => 'Routing Number',
            'iban' => 'IBAN',
            'swift_bic' => 'SWIFT/BIC',
            'bank_address' => 'Bank Address',
            'bank_country' => 'Bank Country',
            'is_active' => 'Status',
            'assigned_users' => 'Assigned Users',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    private function subjectLabel(BankAccount $account): string
    {
        return "{$account->bank_name} — {$account->account_name} ({$account->currency->label()})";
    }

    /**
     * @param  array<int, array{field: string, label: string, before: mixed, after: mixed}>|null  $changes
     */
    private function write(BankAccount $account, User $actor, ActivityAction $action, string $description, ?array $changes): void
    {
        ActivityLog::create([
            'subject_type' => BankAccount::class,
            'subject_id' => $account->id,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_role' => $actor->getRoleNames()->first() ?? '',
            'action' => $action,
            'subject_label' => $this->subjectLabel($account),
            'description' => $description,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }
}
