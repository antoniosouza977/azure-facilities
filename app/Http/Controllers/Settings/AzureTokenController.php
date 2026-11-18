<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAzureTokenRequest;
use App\Models\AzureToken;
use App\Services\Azure\AzureClientFactory;
use App\Services\Azure\Exceptions\AzureApiException;
use App\Services\Azure\Exceptions\AzureAuthenticationException;
use App\Services\Azure\Security\TokenVault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AzureTokenController extends Controller
{
    public function __construct(
        private readonly TokenVault $vault,
        private readonly AzureClientFactory $clients,
    ) {}

    public function index(Request $request): Response
    {
        $tokens = $request->user()->azureTokens()->orderByDesc('is_default')->oldest()->get();

        return Inertia::render('settings/AzureTokens', [
            'tokens' => $tokens->map->toSafeArray()->values(),
        ]);
    }

    public function store(StoreAzureTokenRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $client = $this->clients->make($data['organization'], $data['token']);

        try {
            $identity = $client->authenticatedUser();
        } catch (AzureApiException $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }

        // Sonda o escopo de leitura de código (necessário para listar PRs).
        $warning = null;
        try {
            $client->pullRequests(['creatorId' => $identity['id'], 'status' => 'active'], 1);
        } catch (AzureApiException) {
            $warning = 'Token salvo, mas ele não conseguiu ler pull requests. Confira se o PAT tem o escopo "Code (Read)".';
        }

        $isDefault = ($data['is_default'] ?? false) || ! $user->azureTokens()->exists();

        DB::transaction(function () use ($user, $data, $identity, $isDefault) {
            if ($isDefault) {
                $user->azureTokens()->update(['is_default' => false]);
            }

            $token = $this->vault->store($user, [
                'label' => $data['label'],
                'organization' => $data['organization'],
                'expires_at' => $data['expires_at'] ?? null,
                'is_default' => $isDefault,
                'azure_user_id' => $identity['id'],
                'azure_display_name' => $identity['display_name'],
            ], $data['token']);

            $token->forceFill(['last_validated_at' => now()])->save();
        });

        Log::info('azure_token.created', ['user_id' => $user->id, 'organization' => $data['organization']]);

        return to_route('azure-tokens.index')->with($warning ? 'warning' : 'success', $warning ?? 'Token conectado como '.$identity['display_name'].'.');
    }

    public function check(Request $request, string $token): RedirectResponse
    {
        $token = $this->find($request, $token);

        try {
            $identity = $this->clients->forToken($token)->authenticatedUser();
        } catch (AzureAuthenticationException $e) {
            return back()->with('error', "\"{$token->label}\": {$e->getMessage()}");
        } catch (AzureApiException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($identity['id'] !== $token->azure_user_id) {
            return back()->with('error', "\"{$token->label}\" agora pertence a outro usuário do Azure. Remova e cadastre novamente.");
        }

        $token->forceFill(['last_validated_at' => now(), 'azure_display_name' => $identity['display_name']])->save();

        return back()->with('success', "\"{$token->label}\" está válido.");
    }

    public function makeDefault(Request $request, string $token): RedirectResponse
    {
        $token = $this->find($request, $token);

        DB::transaction(function () use ($request, $token) {
            $request->user()->azureTokens()->update(['is_default' => false]);
            $token->forceFill(['is_default' => true])->save();
        });

        return back()->with('success', "\"{$token->label}\" agora é o token padrão.");
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        $token = $this->find($request, $token);
        $wasDefault = $token->is_default;
        $token->delete();

        if ($wasDefault) {
            $request->user()->azureTokens()->oldest()->first()?->forceFill(['is_default' => true])->save();
        }

        Log::info('azure_token.deleted', ['user_id' => $request->user()->id, 'token_id' => $token->id]);

        return back()->with('success', "Token \"{$token->label}\" removido. Lembre-se de revogá-lo também no Azure DevOps se não for mais usá-lo.");
    }

    /**
     * Busca sempre restrita aos tokens do usuário logado (404 para o resto).
     */
    private function find(Request $request, string $id): AzureToken
    {
        return $request->user()->azureTokens()->whereKey($id)->firstOrFail();
    }
}
