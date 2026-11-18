<?php

namespace App\Services\Azure;

use App\Models\AzureToken;
use App\Services\Azure\Exceptions\AzureApiException;
use App\Services\Azure\Exceptions\AzureAuthenticationException;
use App\Services\Azure\Security\TokenDecryptionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Lista os PBIs e Bugs ligados ao dono do token (atribuídos a ele e/ou criados
 * por ele) em todos os projetos da organização, paginados no servidor.
 *
 * Fluxo: a WIQL devolve só os ids (barato, até `work_item_limit`), que ficam em
 * cache; os detalhes são buscados apenas para os ids da página pedida.
 *
 * Os estados (New, Disponível QA, Homologado…) são personalizados por processo,
 * então vêm do Azure com a categoria de cada um (Proposed, InProgress,
 * Resolved, Completed, Removed). Os grupos "Em aberto / Concluídos /
 * Removidos" são montados a partir dessas categorias.
 *
 * `@Me` na WIQL é resolvido pelo Azure para a identidade dona do PAT.
 */
class WorkItemService
{
    public const ROLES = ['all', 'assigned', 'created'];

    public const TYPES = ['all', 'pbi', 'bug'];

    public const STATUSES = ['open', 'done', 'removed', 'all'];

    public const PER_PAGE = 25;

    private const TYPE_NAMES = [
        'pbi' => 'Product Backlog Item',
        'bug' => 'Bug',
    ];

    /** Categoria do Azure → categoria usada aqui (e na UI), na ordem do fluxo. */
    private const CATEGORIES = [
        'Proposed' => 'proposed',
        'InProgress' => 'in_progress',
        'Resolved' => 'resolved',
        'Completed' => 'completed',
        'Removed' => 'removed',
    ];

    /**
     * Usado só se a API de processos falhar: estados padrão do Scrum/Agile.
     *
     * @var list<array{name: string, category: string, color: string|null}>
     */
    private const FALLBACK_STATES = [
        ['name' => 'New', 'category' => 'proposed', 'color' => null],
        ['name' => 'Approved', 'category' => 'proposed', 'color' => null],
        ['name' => 'Committed', 'category' => 'in_progress', 'color' => null],
        ['name' => 'Active', 'category' => 'in_progress', 'color' => null],
        ['name' => 'In Progress', 'category' => 'in_progress', 'color' => null],
        ['name' => 'Resolved', 'category' => 'resolved', 'color' => null],
        ['name' => 'Done', 'category' => 'completed', 'color' => null],
        ['name' => 'Closed', 'category' => 'completed', 'color' => null],
        ['name' => 'Removed', 'category' => 'removed', 'color' => null],
    ];

    private const FIELDS = [
        'System.Id',
        'System.Title',
        'System.WorkItemType',
        'System.State',
        'System.TeamProject',
        'System.IterationPath',
        'System.AssignedTo',
        'System.CreatedBy',
        'System.CreatedDate',
        'System.ChangedDate',
        'System.Tags',
        'Microsoft.VSTS.Common.Priority',
    ];

    public function __construct(private readonly AzureClientFactory $clients) {}

    /**
     * Estados e projetos da organização do token, vindos dos processos
     * (inclusive personalizados). Cache de 1h; se a API falhar, usa os estados
     * padrão por 5 min e sem lista de projetos (o erro real, se houver, aparece
     * na listagem).
     *
     * @return array{states: list<array{name: string, category: string, color: string|null}>, projects: list<string>}
     */
    public function catalog(AzureToken $token, bool $fresh = false): array
    {
        $key = "azure:wit-catalog:{$token->id}";

        if (! $fresh && $cached = Cache::get($key)) {
            return $cached;
        }

        try {
            $catalog = $this->fetchCatalog($this->clients->forToken($token));
            Cache::put($key, $catalog, 3600);
        } catch (AzureApiException|AzureAuthenticationException|TokenDecryptionException) {
            $catalog = ['states' => self::FALLBACK_STATES, 'projects' => []];
            Cache::put($key, $catalog, 300);
        }

        return $catalog;
    }

    /**
     * Nomes dos estados que pertencem ao grupo de status escolhido.
     *
     * @param  array{states: list<array{name: string, category: string, color: string|null}>}  $catalog
     * @return list<string>
     */
    public function statesFor(array $catalog, string $status): array
    {
        $categories = match ($status) {
            'open' => ['proposed', 'in_progress', 'resolved'],
            'done' => ['completed'],
            'removed' => ['removed'],
            default => array_values(self::CATEGORIES),
        };

        return array_values(array_map(
            fn (array $st) => $st['name'],
            array_filter($catalog['states'], fn (array $st) => in_array($st['category'], $categories, true)),
        ));
    }

    /**
     * @param  array{role?: string, type?: string, status?: string, q?: string|null, state?: list<string>, project?: string|null}  $filters
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, total: int, last_page: int, fetched_at: string, truncated: bool}
     */
    public function list(AzureToken $token, array $filters, int $page = 1, bool $fresh = false): array
    {
        $catalog = $this->catalog($token);
        $wiql = $this->wiql($filters, $catalog);
        $idsKey = "azure:wis:ids:{$token->id}:".md5($wiql);

        if ($fresh) {
            Cache::forget($idsKey);
        }

        $limit = config('azure.work_item_limit');

        // Só decripta o token/cria o client se algo realmente for ao Azure.
        $instance = null;
        $client = function () use (&$instance, $token): AzureDevOpsClient {
            return $instance ??= $this->clients->forToken($token);
        };

        $snapshot = Cache::remember($idsKey, config('azure.work_item_cache_ttl'), fn () => [
            'ids' => $client()->wiqlIds($wiql, $limit),
            'fetched_at' => now()->toIso8601String(),
        ]);

        $total = count($snapshot['ids']);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $pageIds = array_slice($snapshot['ids'], ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $items = [];
        if ($pageIds !== []) {
            // Detalhes da página: mesma validade do snapshot de ids.
            $pageKey = "azure:wis:page:{$token->id}:".md5($snapshot['fetched_at'].implode(',', $pageIds));
            if ($fresh) {
                Cache::forget($pageKey);
            }

            $items = Cache::remember($pageKey, config('azure.work_item_cache_ttl'), fn () => array_map(
                fn (array $wi) => $this->map($wi, $token, $catalog),
                $client()->workItems($pageIds, self::FIELDS),
            ));
        }

        return [
            'items' => $items,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'last_page' => $lastPage,
            'fetched_at' => $snapshot['fetched_at'],
            'truncated' => $total >= $limit,
        ];
    }

    /**
     * Monta a WIQL. role/type/status vêm de listas fixas (validadas no
     * controller); estados, project e q passam por quote(), que escapa aspas
     * simples no padrão da WIQL.
     *
     * @param  array{role?: string, type?: string, status?: string, q?: string|null, state?: list<string>, project?: string|null}  $filters
     * @param  array{states: list<array{name: string, category: string, color: string|null}>}  $catalog
     */
    public function wiql(array $filters, array $catalog): string
    {
        $role = $filters['role'] ?? 'all';
        $type = $filters['type'] ?? 'all';
        $status = $filters['status'] ?? 'open';

        $types = $type === 'all' ? array_values(self::TYPE_NAMES) : [self::TYPE_NAMES[$type]];

        $conditions = ['[System.WorkItemType] IN ('.$this->quoteList($types).')'];

        $conditions[] = match ($role) {
            'assigned' => '[System.AssignedTo] = @Me',
            'created' => '[System.CreatedBy] = @Me',
            default => '([System.AssignedTo] = @Me OR [System.CreatedBy] = @Me)',
        };

        // "Em aberto" exclui os finais (em vez de listar os abertos) para não
        // esconder um estado novo que ainda não esteja no cache do catálogo.
        $condition = match ($status) {
            'open' => $this->stateCondition('NOT IN', [...$this->statesFor($catalog, 'done'), ...$this->statesFor($catalog, 'removed')]),
            'done', 'removed' => $this->stateCondition('IN', $this->statesFor($catalog, $status)),
            default => null,
        };
        if ($condition !== null) {
            $conditions[] = $condition;
        }

        if (! empty($filters['state'])) {
            $conditions[] = $this->stateCondition('IN', array_values($filters['state']));
        }

        if (! empty($filters['project'])) {
            $conditions[] = '[System.TeamProject] = '.$this->quote($filters['project']);
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $text = '[System.Title] CONTAINS '.$this->quote($q).' OR [System.Tags] CONTAINS '.$this->quote($q);
            // "218834" ou "#218834" também encontra pelo número do card.
            $conditions[] = preg_match('/^#?(\d{1,9})$/', $q, $m)
                ? "([System.Id] = {$m[1]} OR {$text})"
                : "({$text})";
        }

        return 'SELECT [System.Id] FROM WorkItems WHERE '.implode(' AND ', $conditions).' ORDER BY [System.ChangedDate] DESC';
    }

    /**
     * @return array{states: list<array{name: string, category: string, color: string|null}>, projects: list<string>}
     */
    private function fetchCatalog(AzureDevOpsClient $client): array
    {
        $ranks = array_flip(array_values(self::CATEGORIES));
        $states = [];
        $projects = [];

        foreach ($client->processes() as $process) {
            // Processo sem projeto não interessa (evita chamadas à toa).
            if ($process['projects'] === []) {
                continue;
            }
            array_push($projects, ...$process['projects']);

            foreach ($client->processWorkItemTypes($process['id']) as $wit) {
                if (! in_array($wit['name'] ?? null, self::TYPE_NAMES, true)) {
                    continue;
                }

                foreach ($wit['states'] ?? [] as $st) {
                    $name = (string) ($st['name'] ?? '');
                    $category = self::CATEGORIES[$st['stateCategory'] ?? ''] ?? null;

                    if ($name === '' || $category === null || ($st['hidden'] ?? false) || isset($states[$name])) {
                        continue;
                    }

                    $states[$name] = [
                        'name' => $name,
                        'category' => $category,
                        'color' => isset($st['color']) && preg_match('/^[0-9a-f]{6}$/i', $st['color']) ? strtolower($st['color']) : null,
                        'sort' => [$ranks[$category], (int) ($st['order'] ?? 0)],
                    ];
                }
            }
        }

        // Ordem do fluxo: categoria e, dentro dela, a ordem definida no processo.
        uasort($states, fn ($a, $b) => $a['sort'] <=> $b['sort']);
        $projects = array_values(array_unique($projects));
        sort($projects, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'states' => $states === [] ? self::FALLBACK_STATES : array_values(array_map(fn ($st) => Arr::except($st, 'sort'), $states)),
            'projects' => $projects,
        ];
    }

    /**
     * @param  list<string>  $states
     */
    private function stateCondition(string $operator, array $states): string
    {
        // IN () é inválido na WIQL: sem estados no grupo, IN não casa nada e NOT IN casa tudo.
        if ($states === []) {
            return $operator === 'IN' ? '[System.Id] < 0' : '[System.Id] > 0';
        }

        return "[System.State] {$operator} (".$this->quoteList($states).')';
    }

    /**
     * @param  array<string, mixed>  $wi
     * @param  array{states: list<array{name: string, category: string, color: string|null}>}  $catalog
     * @return array<string, mixed>
     */
    private function map(array $wi, AzureToken $token, array $catalog): array
    {
        $f = $wi['fields'] ?? [];
        $id = (int) $wi['id'];
        $project = (string) ($f['System.TeamProject'] ?? '');
        $assigned = $f['System.AssignedTo'] ?? null;
        $createdBy = $f['System.CreatedBy'] ?? null;
        $state = (string) ($f['System.State'] ?? '');
        $stateInfo = Arr::first($catalog['states'], fn (array $st) => $st['name'] === $state);

        $roles = [];
        if (is_array($assigned) && ($assigned['id'] ?? null) === $token->azure_user_id) {
            $roles[] = 'assigned';
        }
        if (is_array($createdBy) && ($createdBy['id'] ?? null) === $token->azure_user_id) {
            $roles[] = 'created';
        }

        $base = rtrim(config('azure.base_url'), '/').'/'.rawurlencode($token->organization).'/'.rawurlencode($project);
        $iteration = (string) ($f['System.IterationPath'] ?? '');
        $tags = array_values(array_filter(array_map('trim', explode(';', (string) ($f['System.Tags'] ?? '')))));

        return [
            'id' => $id,
            'title' => (string) ($f['System.Title'] ?? ''),
            'type' => (string) ($f['System.WorkItemType'] ?? ''),
            'state' => $state,
            // Estado fora do catálogo (criado depois do cache): tratado como em andamento.
            'state_category' => $stateInfo['category'] ?? 'in_progress',
            'state_color' => $stateInfo['color'] ?? null,
            'project' => $project,
            // "Projeto\Sprint 95" → "Sprint 95" (a raiz é o próprio projeto)
            'iteration' => str_contains($iteration, '\\') ? Arr::last(explode('\\', $iteration)) : null,
            'assigned_to' => is_array($assigned) ? ($assigned['displayName'] ?? null) : null,
            'created_by' => is_array($createdBy) ? ($createdBy['displayName'] ?? null) : null,
            'priority' => isset($f['Microsoft.VSTS.Common.Priority']) ? (int) $f['Microsoft.VSTS.Common.Priority'] : null,
            'tags' => $tags,
            'roles' => $roles,
            'created_at' => isset($f['System.CreatedDate']) ? Carbon::parse($f['System.CreatedDate'])->toIso8601String() : null,
            'changed_at' => isset($f['System.ChangedDate']) ? Carbon::parse($f['System.ChangedDate'])->toIso8601String() : null,
            'url' => "{$base}/_workitems/edit/{$id}",
        ];
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @param  list<string>  $values
     */
    private function quoteList(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => $this->quote($v), $values));
    }
}
