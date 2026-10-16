<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\Document;
use App\Models\NextcloudInstance;
use App\Models\Tag;
use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\Mcp\ToolRegistry;
use App\Services\Mcp\Tools\Tool;
use App\Services\OAuth\Endpoints;
use App\Services\Search\DocumentSearch;
use App\Services\Tags\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The facts behind the help page.
 *
 * The page says what *this* installation does, not what NextSearch does in
 * general — which folders are connected, which formats are read, whether OCR
 * runs, what the tags mean here. All of it comes from the configuration and the
 * database, so it cannot drift away from reality the way a hand-written page
 * would.
 *
 * The prose sits in the frontend's translation files; what travels from here
 * are numbers, names and switches. Everything is scoped to the caller: a user
 * without administrator rights reads about their own folders and nothing else.
 */
class HelpController extends Controller
{
    public function __construct(
        private readonly TagService $tags,
        private readonly ToolRegistry $mcpTools,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $folders = $this->visibleFolders($user);

        return response()->json(array_filter([
            'installation' => $this->installation($folders),
            'search' => $this->search(),
            'folders' => $this->folders($folders),
            'tags' => $this->tagVocabulary($user),
            'access' => $this->access($user),
            'admin' => $user->isAdmin() ? $this->admin() : null,
        ], fn ($section) => $section !== null));
    }

    /**
     * @return Collection<int, WatchedFolder>
     */
    private function visibleFolders(User $user)
    {
        $query = $user->isAdmin()
            ? WatchedFolder::query()
            : $user->folders()->getQuery();

        return $query->with('instance')->orderBy('label')->get();
    }

    /**
     * @param  Collection<int, WatchedFolder>  $folders
     * @return array<string, mixed>
     */
    private function installation($folders): array
    {
        $folderIds = $folders->pluck('id');

        $documents = Document::query()
            ->whereIn('watched_folder_id', $folderIds)
            ->where('state', Document::STATE_INDEXED);

        return [
            'version' => (string) config('nextsearch.mcp.server_version'),
            'instances' => $folders->pluck('instance')->filter()->unique('id')->count(),
            'folders' => $folders->count(),
            'documents' => (clone $documents)->count(),
            // The most recent indexing anywhere the caller can see. Says more
            // about whether the thing is alive than any status page.
            'last_indexed_at' => (clone $documents)->max('indexed_at'),
            'pending' => Document::query()
                ->whereIn('watched_folder_id', $folderIds)
                ->where('state', Document::STATE_PENDING)
                ->count(),
        ];
    }

    /**
     * What the search covers. Straight out of the configuration — an operator
     * who narrows INDEX_EXTENSIONS sees the shorter list here the next minute.
     *
     * @return array<string, mixed>
     */
    private function search(): array
    {
        return [
            'extensions' => config('nextsearch.index.extensions'),
            'max_file_size_mb' => (int) round(config('nextsearch.index.max_file_size') / 1024 / 1024),
            'ocr' => [
                'enabled' => (bool) config('nextsearch.tika.ocr.enabled'),
                'languages' => (string) config('nextsearch.tika.ocr.languages'),
            ],
            'preview' => [
                'enabled' => (bool) config('nextsearch.preview.enabled'),
                'office' => (bool) config('nextsearch.preview.office.enabled'),
            ],
            'per_page' => (int) config('nextsearch.search.per_page'),
            'vanished_retention_days' => (int) config('nextsearch.index.vanished_retention_days'),
            // The facet keys the panel can show. The frontend already
            // translates these for the filter panel and reuses those labels.
            'facets' => ['tags', 'my_tags', ...DocumentSearch::FACETS],
            'sorts' => ['relevance', 'newest', 'oldest', 'largest', 'name'],
        ];
    }

    /**
     * @param  Collection<int, WatchedFolder>  $folders
     * @return list<array<string, mixed>>
     */
    private function folders($folders): array
    {
        return $folders->map(fn (WatchedFolder $folder) => [
            'label' => $folder->label,
            'instance' => $folder->instance?->name,
            'interval_minutes' => $folder->interval_minutes,
            'enabled' => (bool) $folder->enabled,
        ])->values()->all();
    }

    /**
     * The vocabulary as this user meets it, with the two things that decide how
     * a tag behaves: who sees an assignment, and whether this user may make one.
     *
     * @return list<array<string, mixed>>
     */
    private function tagVocabulary(User $user): array
    {
        return $this->tags->visibleTo($user)->map(fn (Tag $tag) => [
            'slug' => $tag->slug,
            'name' => $tag->name,
            'color' => $tag->color,
            'description' => $tag->description,
            'scope' => $tag->scope,
            'private' => $tag->isPrivate(),
            'system' => (bool) $tag->is_system,
            'assignable' => $tag->assignableBy($user),
            'auto_rule' => $tag->auto_rule,
            'auto_duration_minutes' => $tag->auto_duration_minutes,
        ])->values()->all();
    }

    /**
     * The ways out of the interface: the HTTP API and the MCP server. Both are
     * listed with what the caller would actually need to use them.
     *
     * @return array<string, mixed>
     */
    private function access(User $user): array
    {
        $mcpEnabled = (bool) config('nextsearch.mcp.enabled');

        return [
            'is_admin' => $user->isAdmin(),
            'api' => [
                'rate_limit' => (int) config('nextsearch.api.rate_limit'),
            ],
            'mcp' => [
                'enabled' => $mcpEnabled,
                'url' => $mcpEnabled ? Endpoints::resource() : null,
                'oauth' => $mcpEnabled && (bool) config('nextsearch.mcp.oauth.enabled'),
                'tools' => $mcpEnabled
                    ? array_map(fn (Tool $tool) => $tool->name(), $this->mcpTools->all())
                    : [],
            ],
        ];
    }

    /**
     * The part only an administrator gets: what is connected, and where the
     * knobs are. Nothing secret — no credentials, no URLs into anyone's
     * Nextcloud beyond its host.
     *
     * @return array<string, mixed>
     */
    private function admin(): array
    {
        return [
            'instances' => NextcloudInstance::query()
                ->withCount('folders')
                ->orderBy('name')
                ->get()
                ->map(fn (NextcloudInstance $instance) => [
                    'name' => $instance->name,
                    'host' => parse_url($instance->base_url, PHP_URL_HOST) ?: $instance->base_url,
                    'folders' => $instance->folders_count,
                ])->values()->all(),
            'default_interval_minutes' => (int) config('nextsearch.index.default_interval_minutes'),
            'users' => User::query()->count(),
            'admins' => User::query()->where('role', User::ROLE_ADMIN)->count(),
            'users_disabled' => User::query()->whereNotNull('disabled_at')->count(),
            'invitations_pending' => User::query()
                ->whereNotNull('invitation_hash')
                ->where('invitation_expires_at', '>', now())
                ->count(),
            'invitation_expire_hours' => (int) config('nextsearch.invitations.expire_hours'),
            // Keys that open /api/v1/admin and still work — the flag, an owner
            // who is an administrator, not expired.
            'admin_api_keys' => ApiToken::query()
                ->where('admin', true)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('user', fn ($query) => $query->where('role', User::ROLE_ADMIN)->whereNull('disabled_at'))
                ->count(),
            'documents_total' => Document::query()->count(),
            'documents_failed' => Document::query()->where('state', Document::STATE_FAILED)->count(),
            'documents_skipped' => Document::query()->where('state', Document::STATE_SKIPPED)->count(),
            // Gone from their directory, waiting to be found again elsewhere.
            'documents_vanished' => Document::query()->withVanished()->whereNotNull('vanished_at')->count(),
        ];
    }
}
