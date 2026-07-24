<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\NextcloudException;
use App\Http\Controllers\Controller;
use App\Models\NextcloudInstance;
use App\Services\Nextcloud\ConnectionTester;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use App\Services\Nextcloud\RemoteEntry;
use App\Services\Search\SearchIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InstanceController extends Controller
{
    public function index(): JsonResponse
    {
        $instances = NextcloudInstance::query()
            ->withCount('folders')
            ->withCount('documents')
            ->orderBy('name')
            ->get()
            ->map($this->present(...));

        return response()->json(['instances' => $instances]);
    }

    public function store(Request $request, ConnectionTester $tester): JsonResponse
    {
        $data = $request->validate($this->rules());

        $instance = NextcloudInstance::query()->create([
            ...$data,
            'base_url' => rtrim($data['base_url'], '/'),
        ]);

        $tester->test($instance);

        return response()->json(['instance' => $this->present($instance->refresh())], 201);
    }

    public function update(Request $request, NextcloudInstance $instance): JsonResponse
    {
        $data = $request->validate($this->rules($instance));

        // Ein leeres Passwortfeld heißt „unverändert lassen" — die Oberfläche
        // bekommt das gespeicherte Passwort nie zu sehen.
        if (blank($data['app_password'] ?? null)) {
            unset($data['app_password']);
        }

        if (isset($data['base_url'])) {
            $data['base_url'] = rtrim($data['base_url'], '/');
        }

        $instance->update($data);

        return response()->json(['instance' => $this->present($instance->refresh())]);
    }

    public function destroy(NextcloudInstance $instance, SearchIndex $index): JsonResponse
    {
        // Erst aus dem Suchindex, dann aus der Datenbank — andersherum wüsste
        // niemand mehr, welche Dokumente zu entfernen wären.
        foreach ($instance->folders as $folder) {
            $index->forgetFolder($folder->id);
        }

        $instance->delete();

        return response()->json(['message' => 'Instanz entfernt.']);
    }

    public function test(NextcloudInstance $instance, ConnectionTester $tester): JsonResponse
    {
        return response()->json($tester->test($instance));
    }

    /**
     * Ordner einer Ebene — Grundlage für den Ordner-Picker.
     */
    public function browse(Request $request, NextcloudInstance $instance, ReadOnlyWebDavClient $dav): JsonResponse
    {
        $path = trim((string) $request->query('path', ''), '/');

        try {
            $directories = $dav->listDirectories($instance, $path);
        } catch (NextcloudException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'path' => $path,
            'parent' => $path === '' ? null : trim(dirname($path), '.'),
            'directories' => array_map(fn (RemoteEntry $entry) => [
                'name' => $entry->name,
                'path' => $entry->path,
                'modified_at' => $entry->modifiedAt?->toIso8601String(),
            ], $directories),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?NextcloudInstance $instance = null): array
    {
        $unique = Rule::unique('nextcloud_instances', 'base_url')
            ->where(fn ($query) => $query->where('username', request('username')));

        return [
            'name' => [$instance ? 'sometimes' : 'required', 'string', 'max:120'],
            'base_url' => [
                $instance ? 'sometimes' : 'required',
                'url:http,https',
                'max:500',
                $instance ? $unique->ignore($instance->id) : $unique,
            ],
            'username' => [$instance ? 'sometimes' : 'required', 'string', 'max:190'],
            'app_password' => [$instance ? 'nullable' : 'required', 'string', 'max:500'],
            'verify_tls' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NextcloudInstance $instance): array
    {
        return [
            'uuid' => $instance->uuid,
            'name' => $instance->name,
            'base_url' => $instance->base_url,
            'username' => $instance->username,
            'verify_tls' => $instance->verify_tls,
            'enabled' => $instance->enabled,
            'health_state' => $instance->health_state,
            'health_message' => $instance->health_message,
            'health_checked_at' => $instance->health_checked_at?->toIso8601String(),
            'folders_count' => $instance->folders_count ?? $instance->folders()->count(),
            'documents_count' => $instance->documents_count ?? $instance->documents()->count(),
        ];
    }
}
