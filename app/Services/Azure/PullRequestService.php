<?php

namespace App\Services\Azure;

use App\Models\AzureToken;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Lista os PRs ligados ao dono do token (autor e/ou revisor) em todos os
 * projetos e repositórios da organização.
 */
class PullRequestService
{
    public const ROLES = ['all', 'author', 'reviewer'];

    public const STATUSES = ['active', 'completed', 'abandoned', 'all'];

    public function __construct(private readonly AzureClientFactory $clients) {}

    /**
     * @return array{items: list<array<string, mixed>>, fetched_at: string, truncated: bool}
     */
    public function list(AzureToken $token, string $role = 'all', string $status = 'active', bool $fresh = false): array
    {
        $key = "azure:prs:{$token->id}:{$role}:{$status}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, config('azure.pr_cache_ttl'), fn () => $this->fetch($token, $role, $status));
    }

    /**
     * @return array{items: list<array<string, mixed>>, fetched_at: string, truncated: bool}
     */
    private function fetch(AzureToken $token, string $role, string $status): array
    {
        $client = $this->clients->forToken($token);
        $limit = config('azure.pr_limit');
        $me = $token->azure_user_id;

        $criteria = [];
        if ($role !== 'reviewer') {
            $criteria['author'] = ['creatorId' => $me, 'status' => $status];
        }
        if ($role !== 'author') {
            $criteria['reviewer'] = ['reviewerId' => $me, 'status' => $status];
        }

        /** @var array<int, array{raw: array<string, mixed>, roles: list<string>}> $merged */
        $merged = [];
        $truncated = false;

        foreach ($criteria as $as => $search) {
            $results = $client->pullRequests($search, $limit);
            $truncated = $truncated || count($results) >= $limit;

            foreach ($results as $pr) {
                $id = $pr['pullRequestId'];
                $merged[$id] ??= ['raw' => $pr, 'roles' => []];
                $merged[$id]['roles'][] = $as;
            }
        }

        $items = array_map(
            fn (array $entry) => $this->map($entry['raw'], $entry['roles'], $token),
            array_values($merged),
        );

        usort($items, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return [
            'items' => $items,
            'fetched_at' => now()->toIso8601String(),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<string, mixed>  $pr
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function map(array $pr, array $roles, AzureToken $token): array
    {
        $project = Arr::get($pr, 'repository.project.name', '');
        $repository = Arr::get($pr, 'repository.name', '');
        $title = (string) ($pr['title'] ?? '');
        $source = $this->branch($pr['sourceRefName'] ?? '');

        $reviewers = array_map(fn (array $r) => [
            'id' => $r['id'] ?? null,
            'name' => $r['displayName'] ?? '',
            'vote' => (int) ($r['vote'] ?? 0),
            'is_required' => (bool) ($r['isRequired'] ?? false),
            'is_group' => (bool) ($r['isContainer'] ?? false) || str_starts_with((string) ($r['uniqueName'] ?? ''), 'vstfs:'),
            'has_declined' => (bool) ($r['hasDeclined'] ?? false),
        ], $pr['reviewers'] ?? []);

        $mine = Arr::first($reviewers, fn ($r) => $r['id'] === $token->azure_user_id);
        $status = $pr['status'] ?? 'active';
        $isAuthor = in_array('author', $roles, true);

        $base = rtrim(config('azure.base_url'), '/').'/'.rawurlencode($token->organization).'/'.rawurlencode($project);
        $workItem = $this->workItemHint($title, $source);

        return [
            'id' => (int) $pr['pullRequestId'],
            'title' => $title,
            'status' => $status,
            'is_draft' => (bool) ($pr['isDraft'] ?? false),
            'merge_status' => $pr['mergeStatus'] ?? null,
            'project' => $project,
            'repository' => $repository,
            'source_branch' => $source,
            'target_branch' => $this->branch($pr['targetRefName'] ?? ''),
            'author' => Arr::get($pr, 'createdBy.displayName', ''),
            'created_at' => Carbon::parse($pr['creationDate'])->toIso8601String(),
            'closed_at' => isset($pr['closedDate']) ? Carbon::parse($pr['closedDate'])->toIso8601String() : null,
            'roles' => $roles,
            'my_vote' => $mine['vote'] ?? null,
            'awaiting_my_review' => $status === 'active' && ! $isAuthor && $mine !== null && $mine['vote'] === 0 && ! $mine['has_declined'],
            'reviewers' => $reviewers,
            'url' => "{$base}/_git/".rawurlencode($repository)."/pullrequest/{$pr['pullRequestId']}",
            'work_item' => $workItem ? ['id' => $workItem, 'url' => "{$base}/_workitems/edit/{$workItem}"] : null,
        ];
    }

    private function branch(string $ref): string
    {
        return preg_replace('#^refs/heads/#', '', $ref);
    }

    /**
     * Convenções observadas nos times:
     *   título "[pbi:218834] ..." ou "160767 - ..."
     *   branch "PBI/SP_95_218834", "SP018_PBI_160767" ou "hotfix/2.86.6_190153"
     */
    private function workItemHint(string $title, string $branch): ?int
    {
        $patterns = [
            [$title, '/\b(?:pbi|bug|card)\s*[:#]\s*(\d{4,8})/i'],
            [$title, '/^\s*(\d{5,8})\s+-\s/'],
            [$branch, '/pbi[_\/-](\d{5,8})\b/i'],
            [$branch, '#^(?:pbi|hotfix|bug)/.*_(\d{5,8})(?:_[a-z]+)?$#i'],
        ];

        foreach ($patterns as [$subject, $regex]) {
            if (preg_match($regex, $subject, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }
}
