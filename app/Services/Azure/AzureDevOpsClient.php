<?php

namespace App\Services\Azure;

use App\Services\Azure\Exceptions\AzureApiException;
use App\Services\Azure\Exceptions\AzureAuthenticationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SensitiveParameter;

/**
 * Cliente mínimo da REST API do Azure DevOps autenticado por PAT.
 * Instâncias vivem só durante a requisição: não são serializáveis (cache,
 * fila, sessão) e não exibem o token em dumps.
 */
class AzureDevOpsClient
{
    public function __construct(
        private readonly string $organization,
        #[SensitiveParameter] private readonly string $pat,
    ) {}

    public function organization(): string
    {
        return $this->organization;
    }

    /**
     * Identidade do dono do token.
     *
     * @return array{id: string, display_name: string|null}
     */
    public function authenticatedUser(): array
    {
        $data = $this->get('_apis/connectionData', [], '7.1-preview');
        $user = $data['authenticatedUser'] ?? null;

        if (! is_array($user) || empty($user['id'])) {
            throw new AzureAuthenticationException('O Azure DevOps não reconheceu o token.');
        }

        return [
            'id' => $user['id'],
            'display_name' => $user['customDisplayName'] ?? $user['providerDisplayName'] ?? null,
        ];
    }

    /**
     * PRs de TODOS os projetos e repositórios da organização.
     *
     * @param  array<string, string>  $criteria  ex. ['creatorId' => ..., 'status' => 'active']
     * @return list<array<string, mixed>>
     */
    public function pullRequests(array $criteria, int $top = 100): array
    {
        $query = ['$top' => $top];
        foreach ($criteria as $key => $value) {
            $query["searchCriteria.{$key}"] = $value;
        }

        return $this->get('_apis/git/pullrequests', $query)['value'] ?? [];
    }

    /**
     * Processos da organização (inclusive os personalizados/herdados) com os
     * projetos que usam cada um.
     *
     * @return list<array{id: string, name: string, projects: list<string>}>
     */
    public function processes(): array
    {
        $data = $this->get('_apis/work/processes', ['$expand' => 'projects']);

        return array_map(fn (array $p) => [
            'id' => (string) $p['typeId'],
            'name' => (string) ($p['name'] ?? ''),
            'projects' => array_values(array_filter(array_map(fn (array $pr) => (string) ($pr['name'] ?? ''), $p['projects'] ?? []))),
        ], array_values(array_filter($data['value'] ?? [], fn ($p) => is_array($p) && ! empty($p['typeId']))));
    }

    /**
     * Tipos de work item de um processo com seus estados (nome, cor, categoria).
     *
     * @return list<array<string, mixed>>
     */
    public function processWorkItemTypes(string $processId): array
    {
        return $this->get('_apis/work/processes/'.rawurlencode($processId).'/workitemtypes', ['$expand' => 'states'])['value'] ?? [];
    }

    /**
     * Executa uma WIQL no nível da organização (todos os projetos) e devolve
     * os ids na ordem do ORDER BY.
     *
     * @return list<int>
     */
    public function wiqlIds(string $wiql, int $top = 200): array
    {
        $data = $this->post('_apis/wit/wiql', ['query' => $wiql], ['$top' => $top]);

        return array_map(fn (array $wi) => (int) $wi['id'], $data['workItems'] ?? []);
    }

    /**
     * Detalhes de work items (de qualquer projeto), em lotes de 200 — limite da API.
     * Itens excluídos/sem acesso são omitidos. A ordem de $ids é preservada.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    public function workItems(array $ids, array $fields): array
    {
        $byId = [];

        foreach (array_chunk($ids, 200) as $chunk) {
            $data = $this->post('_apis/wit/workitemsbatch', ['ids' => $chunk, 'fields' => $fields, 'errorPolicy' => 'omit']);

            foreach ($data['value'] ?? [] as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $byId[(int) $item['id']] = $item;
                }
            }
        }

        return array_values(array_filter(array_map(fn (int $id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = [], ?string $apiVersion = null): array
    {
        try {
            $response = $this->request()->get($path, $query + ['api-version' => $apiVersion ?? config('azure.api_version')]);
        } catch (ConnectionException) {
            throw new AzureApiException('Não foi possível conectar ao Azure DevOps. Tente novamente em instantes.');
        }

        return $this->parse($response);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function post(string $path, array $body, array $query = []): array
    {
        $query += ['api-version' => config('azure.api_version')];

        try {
            $response = $this->request()->post($path.'?'.http_build_query($query), $body);
        } catch (ConnectionException) {
            throw new AzureApiException('Não foi possível conectar ao Azure DevOps. Tente novamente em instantes.');
        }

        return $this->parse($response);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim(config('azure.base_url'), '/').'/'.rawurlencode($this->organization))
            ->withBasicAuth('', $this->pat)
            ->acceptJson()
            ->timeout(config('azure.timeout'))
            ->connectTimeout(5)
            // PAT inválido costuma virar redirect para a tela de login.
            ->withOptions(['allow_redirects' => false])
            ->retry(2, 250, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(Response $response): array
    {
        $status = $response->status();
        $isJson = str_contains((string) $response->header('Content-Type'), 'json');

        // 203 + HTML = Azure devolvendo a página de login (token rejeitado).
        if ($status === 401 || $status === 203 || $response->redirect() || ($response->successful() && ! $isJson)) {
            throw new AzureAuthenticationException('Token inválido, expirado, revogado ou sem acesso à organização "'.$this->organization.'".', $status);
        }

        if ($status === 403) {
            throw new AzureApiException('O token não tem permissão para esta operação. Verifique os escopos do PAT (ex.: Code: Read, Work Items: Read).', $status);
        }

        if ($status === 404) {
            throw new AzureApiException('Organização "'.$this->organization.'" ou recurso não encontrado no Azure DevOps.', $status);
        }

        if ($response->failed()) {
            throw new AzureApiException("O Azure DevOps respondeu com erro HTTP {$status}.", $status);
        }

        return $response->json() ?? [];
    }

    public function __debugInfo(): array
    {
        return ['organization' => $this->organization, 'pat' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new RuntimeException('AzureDevOpsClient não pode ser serializado.');
    }
}
