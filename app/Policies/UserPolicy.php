<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Admins can do everything.
     * Resellers can only act on CLIENTS they created (created_by = reseller_id).
     * Resellers can NOT create/update/delete other resellers.
     */

    public function viewAny(User $user): bool
    {
        // Admins and resellers can list users they are allowed to see
        return $this->isAdmin($user) || $this->isReseller($user);
    }

    public function view(User $user, User $model): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if ($this->isReseller($user)) {
            // Can view themselves
            if ($user->id === $model->id) return true;

            // Can view ONLY clients they own; never admins or resellers
            return $this->isClient($model) && $this->owns($user, $model);
        }

        // Clients: only themselves
        return $user->id === $model->id;
    }

    /**
     * Generic "create" (used by Laravel for showing a generic create button).
     * Here we allow:
     *  - Admin: yes
     *  - Reseller: yes (but ONLY for clients; enforced with createClient() below)
     *
     * If you want to be stricter, you can return $this->isAdmin($user) here and
     * authorize specific creates with createClient/createReseller instead.
     */
    public function create(User $user): bool
    {
        return $this->isAdmin($user) || $this->isReseller($user);
    }

    /**
     * Explicit: only admins can create RESELLERS.
     */
    public function createReseller(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Explicit: admins or resellers can create CLIENTS.
     * (Resellers cannot create sub-sellers.)
     */
    public function createClient(User $user): bool
    {
        return $this->isAdmin($user) || $this->isReseller($user);
    }

    public function update(User $user, User $model): bool
    {
        if ($this->isAdmin($user)) return true;

        if ($this->isReseller($user)) {
            // Cannot update themselves, admins, or resellers
            if ($user->id === $model->id) return false;
            if (!$this->isClient($model)) return false;

            // Only clients they own
            return $this->owns($user, $model);
        }

        // Clients: only themselves
        return $user->id === $model->id;
    }

    public function delete(User $user, User $model): bool
    {
        if ($this->isAdmin($user)) return true;

        if ($this->isReseller($user)) {
            // Cannot delete themselves, admins, or resellers
            if ($user->id === $model->id) return false;
            if (!$this->isClient($model)) return false;

            // Only clients they own
            return $this->owns($user, $model);
        }

        return false;
    }

    public function restore(User $user, User $model): bool
    {
        return $this->delete($user, $model);
    }

    public function forceDelete(User $user, User $model): bool
    {
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

    protected function isClient(User $user): bool
    {
        // Adjust if you use a different role name for end-users
        return $user->role === 'client';
    }

    protected function owns(User $actor, User $target): bool
    {
        return (int) $target->created_by === (int) $actor->id;
    }
}