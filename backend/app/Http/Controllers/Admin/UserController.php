<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WatchedFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Accounts and their folder shares. The same controller answers the admin
 * interface over the session and the admin API under /api/v1/admin.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('folders:id,uuid,label')
            // Lets another system find an account it only knows by address.
            ->when(
                $request->query('email'),
                fn ($query, $email) => $query->whereRaw('lower(email) = ?', [mb_strtolower((string) $email)]),
            )
            ->orderBy('name')
            ->get()
            ->map($this->present(...));

        return response()->json(['users' => $users]);
    }

    /**
     * Without a password the account gets an invitation link instead. It comes
     * back in the response exactly once; NextSearch sends no mail.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['sometimes', 'nullable', Password::min(12)],
            'role' => ['sometimes', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
            'folders' => ['sometimes', 'array'],
            'folders.*' => ['string', 'exists:watched_folders,uuid'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => filled($data['password'] ?? null) ? $data['password'] : null,
            'role' => $data['role'] ?? User::ROLE_USER,
        ]);

        $this->syncFolders($user, $data['folders'] ?? []);

        $body = ['user' => $this->present($user->load('folders'))];

        if ($user->password === null) {
            $body['invitation'] = $this->invite($user);
            $body['user'] = $this->present($user->fresh('folders'));
        }

        return response()->json($body, 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:190'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', Password::min(12)],
            'role' => ['sometimes', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
            'disabled' => ['sometimes', 'boolean'],
        ]);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $disable = $data['disabled'] ?? null;
        unset($data['disabled']);

        if ($disable === true) {
            abort_if($user->is($request->user()), 422, __('nextsearch.user.cannot_disable_self'));
        }

        // Disabling an administrator takes them out of the count just like
        // demoting one does.
        $this->guardLastAdmin(
            $user,
            $disable === true ? User::ROLE_USER : ($data['role'] ?? $user->role),
        );

        $user->fill($data);

        // Repeating the call changes nothing — the original timestamp stays.
        if ($disable !== null) {
            $user->disabled_at = $disable ? ($user->disabled_at ?? now()) : null;
        }

        $user->save();

        return response()->json(['user' => $this->present($user->fresh('folders'))]);
    }

    /**
     * A new invitation link. Any earlier one stops working. For an account
     * that already has a password, the link works as a reset: the old password
     * stays valid until the link is used.
     */
    public function invitation(User $user): JsonResponse
    {
        abort_if($user->isDisabled(), 422, __('nextsearch.auth.account_disabled'));

        return response()->json([
            'invitation' => $this->invite($user),
            'user' => $this->present($user->fresh('folders')),
        ], 201);
    }

    /**
     * Set shares — exactly these folders, whatever was there before. What is
     * assigned here applies in NextSearch; Nextcloud's file permissions are not
     * considered.
     */
    public function syncFolderAccess(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'folders' => ['present', 'array'],
            'folders.*' => ['string', 'exists:watched_folders,uuid'],
        ]);

        $this->syncFolders($user, $data['folders']);

        return response()->json(['user' => $this->present($user->fresh('folders'))]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422, __('nextsearch.user.cannot_delete_self'));
        $this->guardLastAdmin($user, User::ROLE_USER);

        $user->delete();

        return response()->json(['message' => __('nextsearch.user.removed')]);
    }

    /**
     * @return array{url: string, expires_at: string}
     */
    private function invite(User $user): array
    {
        $issued = $user->issueInvitation();

        return [
            'url' => User::invitationUrl($issued['token']),
            'expires_at' => $issued['expires_at']->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $folderUuids
     */
    private function syncFolders(User $user, array $folderUuids): void
    {
        $ids = WatchedFolder::query()->whereIn('uuid', $folderUuids)->pluck('id');
        $user->folders()->sync($ids);
    }

    /**
     * Without an active administrator nobody could reach instances and shares
     * anymore.
     */
    private function guardLastAdmin(User $user, string $newRole): void
    {
        if (! $user->isAdmin() || $user->isDisabled() || $newRole === User::ROLE_ADMIN) {
            return;
        }

        $remaining = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->whereNull('disabled_at')
            ->whereKeyNot($user->id)
            ->count();

        abort_if($remaining === 0, 422, __('nextsearch.user.last_admin'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_admin' => $user->isAdmin(),
            'disabled' => $user->isDisabled(),
            'disabled_at' => $user->disabled_at?->toIso8601String(),
            'has_password' => $user->password !== null,
            'invitation_pending' => $user->hasPendingInvitation(),
            'invitation_expires_at' => $user->hasPendingInvitation()
                ? $user->invitation_expires_at->toIso8601String()
                : null,
            'created_at' => $user->created_at?->toIso8601String(),
            'folders' => $user->folders->map(fn (WatchedFolder $folder) => [
                'uuid' => $folder->uuid,
                'label' => $folder->label,
            ])->values(),
        ];
    }
}
