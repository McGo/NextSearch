<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WatchedFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('folders:id,uuid,label')
            ->orderBy('name')
            ->get()
            ->map($this->present(...));

        return response()->json(['users' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(12)],
            'role' => ['sometimes', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
            'folders' => ['sometimes', 'array'],
            'folders.*' => ['string', 'exists:watched_folders,uuid'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'] ?? User::ROLE_USER,
        ]);

        $this->syncFolders($user, $data['folders'] ?? []);

        return response()->json(['user' => $this->present($user->load('folders'))], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:190'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', Password::min(12)],
            'role' => ['sometimes', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
        ]);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $this->guardLastAdmin($user, $data['role'] ?? $user->role);
        $user->update($data);

        return response()->json(['user' => $this->present($user->fresh('folders'))]);
    }

    /**
     * Freigaben setzen. Was hier zugewiesen wird, gilt in NextSearch — die
     * Dateirechte der Nextcloud bleiben unberücksichtigt.
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
        abort_if($user->is($request->user()), 422, 'Das eigene Konto lässt sich nicht löschen.');
        $this->guardLastAdmin($user, User::ROLE_USER);

        $user->delete();

        return response()->json(['message' => 'Konto entfernt.']);
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
     * Ohne Administrator käme niemand mehr an Instanzen und Freigaben.
     */
    private function guardLastAdmin(User $user, string $newRole): void
    {
        if (! $user->isAdmin() || $newRole === User::ROLE_ADMIN) {
            return;
        }

        $remaining = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->whereKeyNot($user->id)
            ->count();

        abort_if($remaining === 0, 422, 'Es muss mindestens ein Administrator übrig bleiben.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_admin' => $user->isAdmin(),
            'created_at' => $user->created_at?->toIso8601String(),
            'folders' => $user->folders->map(fn (WatchedFolder $folder) => [
                'uuid' => $folder->uuid,
                'label' => $folder->label,
            ])->values(),
        ];
    }
}
