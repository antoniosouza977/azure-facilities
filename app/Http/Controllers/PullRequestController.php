<?php

namespace App\Http\Controllers;

use App\Services\Azure\Exceptions\AzureApiException;
use App\Services\Azure\Exceptions\AzureAuthenticationException;
use App\Services\Azure\PullRequestService;
use App\Services\Azure\Security\TokenDecryptionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PullRequestController extends Controller
{
    public function __construct(private readonly PullRequestService $pullRequests) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'role' => ['nullable', Rule::in(PullRequestService::ROLES)],
            'status' => ['nullable', Rule::in(PullRequestService::STATUSES)],
            'token' => ['nullable', 'uuid'],
            'fresh' => ['nullable', 'boolean'],
        ]);

        $role = $filters['role'] ?? 'all';
        $status = $filters['status'] ?? 'active';
        $user = $request->user();
        $tokens = $user->azureTokens()->orderByDesc('is_default')->oldest()->get();

        $token = (isset($filters['token']) ? $tokens->firstWhere('id', $filters['token']) : null)
            ?? $tokens->first();

        $result = null;
        $error = null;

        if ($token) {
            // Ignorar o cache tem limite, para não martelar a API do Azure.
            $fresh = ($filters['fresh'] ?? false)
                && RateLimiter::attempt("pr-refresh:{$user->id}", 10, fn () => true, 60);

            try {
                $result = $this->pullRequests->list($token, $role, $status, $fresh);
            } catch (AzureAuthenticationException $e) {
                $error = $e->getMessage().' Atualize o token em Configurações → Tokens do Azure.';
            } catch (AzureApiException $e) {
                $error = $e->getMessage();
            } catch (TokenDecryptionException $e) {
                Log::error('azure_token.decrypt_failed', ['token_id' => $token->id, 'message' => $e->getMessage()]);
                $error = 'Não foi possível ler este token. Cadastre-o novamente.';
            }
        }

        return Inertia::render('PullRequests/Index', [
            'filters' => ['role' => $role, 'status' => $status, 'token' => $token?->id],
            'tokens' => $tokens->map->toSafeArray()->values(),
            'pullRequests' => $result['items'] ?? [],
            'fetchedAt' => $result['fetched_at'] ?? null,
            'truncated' => $result['truncated'] ?? false,
            'limit' => config('azure.pr_limit'),
            'error' => $error,
        ]);
    }
}
