<?php

namespace App\Http\Controllers;

use App\Services\Azure\Exceptions\AzureApiException;
use App\Services\Azure\Exceptions\AzureAuthenticationException;
use App\Services\Azure\Security\TokenDecryptionException;
use App\Services\Azure\WorkItemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkItemController extends Controller
{
    public function __construct(private readonly WorkItemService $workItems) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'role' => ['nullable', Rule::in(WorkItemService::ROLES)],
            'type' => ['nullable', Rule::in(WorkItemService::TYPES)],
            'status' => ['nullable', Rule::in(WorkItemService::STATUSES)],
            // Lista de estados (state[]=A&state[]=B). Os válidos dependem do
            // processo da organização: checados abaixo.
            'state' => ['nullable', 'array', 'max:50'],
            'state.*' => ['string', 'max:64'],
            'project' => ['nullable', 'string', 'max:128'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'token' => ['nullable', 'uuid'],
            'fresh' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $tokens = $user->azureTokens()->orderByDesc('is_default')->oldest()->get();

        $token = (isset($filters['token']) ? $tokens->firstWhere('id', $filters['token']) : null)
            ?? $tokens->first();

        // Ignorar o cache tem limite, para não martelar a API do Azure.
        $fresh = $token
            && ($filters['fresh'] ?? false)
            && RateLimiter::attempt("wi-refresh:{$user->id}", 10, fn () => true, 60);

        $status = $filters['status'] ?? 'open';
        $catalog = $token ? $this->workItems->catalog($token, $fresh) : ['states' => [], 'projects' => []];
        $states = $this->workItems->statesFor($catalog, $status);

        $query = [
            'role' => $filters['role'] ?? 'all',
            'type' => $filters['type'] ?? 'all',
            'status' => $status,
            // Estados desconhecidos ou de outro grupo (ex.: Done em "Em aberto") são
            // descartados; a ordem segue o fluxo do processo, não a da URL.
            'state' => array_values(array_intersect($states, $filters['state'] ?? [])),
            'project' => $filters['project'] ?? null,
            'q' => isset($filters['q']) ? trim($filters['q']) : null,
        ];

        $result = null;
        $error = null;

        if ($token) {
            try {
                $result = $this->workItems->list($token, $query, (int) ($filters['page'] ?? 1), $fresh);
            } catch (AzureAuthenticationException $e) {
                $error = $e->getMessage().' Atualize o token em Configurações → Tokens do Azure.';
            } catch (AzureApiException $e) {
                $error = $e->getMessage();
            } catch (TokenDecryptionException $e) {
                Log::error('azure_token.decrypt_failed', ['token_id' => $token->id, 'message' => $e->getMessage()]);
                $error = 'Não foi possível ler este token. Cadastre-o novamente.';
            }
        }

        return Inertia::render('WorkItems/Index', [
            'filters' => [...$query, 'token' => $token?->id],
            'tokens' => $tokens->map->toSafeArray()->values(),
            'workItems' => $result['items'] ?? [],
            'pagination' => [
                'page' => $result['page'] ?? 1,
                'per_page' => $result['per_page'] ?? WorkItemService::PER_PAGE,
                'total' => $result['total'] ?? 0,
                'last_page' => $result['last_page'] ?? 1,
            ],
            // Estados do grupo escolhido, na ordem do fluxo do processo, com a cor do Azure.
            'states' => array_values(array_filter($catalog['states'], fn (array $st) => in_array($st['name'], $states, true))),
            'projects' => $catalog['projects'],
            'fetchedAt' => $result['fetched_at'] ?? null,
            'truncated' => $result['truncated'] ?? false,
            'limit' => config('azure.work_item_limit'),
            'error' => $error,
        ]);
    }
}
