<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Admins can do everything.
     * Resellers can generally act on users they created (clients only).
     */

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isReseller($user);
    }

    public function view(User $user, User $model): bool
    {
        if ($this->isAdmin($user)) return true;

        // Reseller can view themselves and users they own (created_by = reseller id), but never admins.
        if ($this->isReseller($user)) {
            if ($user->id === $model->id) return true;                 // own profile
            if ($this->isAdmin($model)) return false;                   // never view admins
            return $this->owns($user, $model);                          // created_by match
        }

        // Clients can view only themselves.
        return $user->id === $model->id;
    }

    public function create(User $user): bool
    {
        // Admin can create anyone; reseller can create their own clients.
        return $this->isAdmin($user) || $this->isReseller($user);
    }

    public function update(User $user, User $model): bool
    {
        if ($this->isAdmin($user)) return true;

        // Reseller: may update only users they own, and not admins or themselves.
        if ($this->isReseller($user)) {
            if ($user->id === $model->id) return false;                 // no self-elevation
            if ($this->isAdmin($model)) return false;                   // cannot touch admins
            return $this->owns($user, $model);
        }

        // Client: only themselves.
        return $user->id === $model->id;
    }

    public function delete(User $user, User $model): bool
    {
        if ($this->isAdmin($user)) return true;

        // Reseller: may delete only owned users; not admins; not themselves.
        if ($this->isReseller($user)) {
            if ($user->id === $model->id) return false;
            if ($this->isAdmin($model)) return false;
            return $this->owns($user, $model);
        }

        return false;
    }

    public function restore(User $user, User $model): bool
    {
        // Same rules as delete
        return $this->delete($user, $model);
    }

    public function forceDelete(User $user, User $model): bool
    {
        // Same rules as delete
        return $this->delete($user, $model);
    }

    /* ───────────────────────────── Helpers ───────────────────────────── */

    protected function isAdmin(User $user): bool
    {
        return method_exists($user, 'isAdmin')
            ? $user->isAdmin()
            : $user->role === 'admin';
    }

    protected function isReseller(User $user): bool
    {
        return method_exists($user, 'isReseller')
            ? $user->isReseller()
            : $user->role === 'reseller';
    }

    protected function owns(User $actor, User $target): bool
    {
        // target must have been created by this reseller
        return (int) $target->created_by === (int) $actor->id;
    }
}