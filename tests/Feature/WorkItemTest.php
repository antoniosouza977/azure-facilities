<?php

namespace Tests\Feature;

use App\Models\AzureToken;
use App\Models\User;
use App\Services\Azure\Security\TokenVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkItemTest extends TestCase
{
    use RefreshDatabase;

    private const ME = '762d4aac-78a1-6dc1-abc1-ea906f0a5518';

    private const PAT = 'pat0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789zzzz';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function tokenFor(User $user): AzureToken
    {
        return app(TokenVault::class)->store($user, [
            'label' => 'Trabalho', 'organization' => 'MinhaOrg', 'azure_user_id' => self::ME, 'is_default' => true,
        ], self::PAT);
    }

    /** @return array<string, mixed> */
    private function wi(int $id, string $type, string $state, array $fields = []): array
    {
        return ['id' => $id, 'fields' => array_replace([
            'System.Id' => $id,
            'System.Title' => "Item {$id}",
            'System.WorkItemType' => $type,
            'System.State' => $state,
            'System.TeamProject' => 'Projeto X',
            'System.IterationPath' => 'Projeto X\\Sprint 95',
            'System.AssignedTo' => ['id' => self::ME, 'displayName' => 'Eu'],
            'System.CreatedBy' => ['id' => 'outro', 'displayName' => 'Outra Pessoa'],
            'System.CreatedDate' => '2026-11-01T10:00:00Z',
            'System.ChangedDate' => '2026-11-05T10:00:00Z',
            'System.Tags' => 'backend; urgente',
            'Microsoft.VSTS.Common.Priority' => 2,
        ], $fields)];
    }

    /**
     * Responde a WIQL com $ids; o batch devolve os itens pedidos (de $items, ou
     * gerados) em ordem invertida, para provar que a ordem da WIQL prevalece.
     */
    private function fakeAzure(array $ids, array $items = []): void
    {
        $byId = collect($items)->keyBy('id');

        Http::fake([
            '*/_apis/wit/wiql*' => Http::response(['workItems' => array_map(fn ($id) => ['id' => $id], $ids)]),
            '*/_apis/wit/workitemsbatch*' => fn (Request $r) => Http::response(['value' => array_reverse(array_map(
                fn ($id) => $byId[$id] ?? $this->wi($id, 'Product Backlog Item', 'New'),
                $r['ids'],
            ))]),
            ...$this->processFakes(),
        ]);
    }

    /**
     * Processo personalizado igual ao do time (status customizados), mais um
     * processo sem projetos que não deve ser consultado.
     *
     * @return array<string, mixed>
     */
    private function processFakes(): array
    {
        $flow = [
            ['name' => 'New', 'stateCategory' => 'Proposed', 'color' => 'b2b2b2', 'order' => 1],
            ['name' => 'In Progress', 'stateCategory' => 'InProgress', 'color' => '007acc', 'order' => 2],
            ['name' => 'Disponível QA', 'stateCategory' => 'InProgress', 'color' => 'ff9d00', 'order' => 3],
            ['name' => 'Pronto Homologação', 'stateCategory' => 'InProgress', 'color' => 'f2cb1d', 'order' => 4],
            ['name' => 'Homologação', 'stateCategory' => 'InProgress', 'color' => '5688e0', 'order' => 5],
            ['name' => 'Homologado', 'stateCategory' => 'Resolved', 'color' => '339947', 'order' => 6],
            ['name' => 'Done', 'stateCategory' => 'Completed', 'color' => '339933', 'order' => 7],
            ['name' => 'Removed', 'stateCategory' => 'Removed', 'color' => 'ffffff', 'order' => 8],
            ['name' => 'Approved', 'stateCategory' => 'Proposed', 'color' => 'cccccc', 'order' => 9, 'hidden' => true],
        ];

        return [
            '*/_apis/work/processes/proc-custom/workitemtypes*' => Http::response(['value' => [
                // Bug vem antes e com a lista invertida: a ordem final segue categoria + order.
                ['name' => 'Bug', 'states' => array_reverse($flow)],
                ['name' => 'Product Backlog Item', 'states' => $flow],
                ['name' => 'Task', 'states' => [['name' => 'To Do', 'stateCategory' => 'Proposed', 'order' => 1]]],
            ]]),
            '*/_apis/work/processes?*' => Http::response(['value' => [
                ['typeId' => 'proc-custom', 'name' => 'Scrum Time', 'projects' => [['name' => 'Projeto Y'], ['name' => 'Projeto X']]],
                ['typeId' => 'proc-unused', 'name' => 'Agile', 'projects' => []],
            ]]),
        ];
    }

    private function batchCalls(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'workitemsbatch'))
            ->map(fn ($pair) => $pair[0]['ids'])->values()->all();
    }

    private function wiqlSent(): string
    {
        $query = null;
        Http::assertSent(function (Request $r) use (&$query) {
            if (str_contains($r->url(), '_apis/wit/wiql')) {
                $query = $r['query'];

                return true;
            }

            return false;
        });

        return (string) $query;
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/work-items')->assertRedirect('/login');
    }

    public function test_shows_empty_state_without_token(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/work-items')
            ->assertInertia(fn (Assert $page) => $page->component('WorkItems/Index')->where('tokens', [])->where('workItems', []));

        Http::assertNothingSent();
    }

    public function test_lists_open_pbis_and_bugs_of_token_owner_by_default(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        $this->fakeAzure([20, 10], [
            $this->wi(10, 'Product Backlog Item', 'Disponível QA', ['System.CreatedBy' => ['id' => self::ME, 'displayName' => 'Eu']]),
            $this->wi(20, 'Bug', 'New', ['System.AssignedTo' => null, 'System.CreatedBy' => ['id' => self::ME, 'displayName' => 'Eu'], 'System.Tags' => null]),
        ]);

        $this->actingAs($user)->get('/work-items')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WorkItems/Index')
                ->where('error', null)
                ->where('filters.role', 'all')
                ->where('filters.type', 'all')
                ->where('filters.status', 'open')
                ->has('workItems', 2)
                ->where('workItems.0.id', 20)
                ->where('workItems.0.type', 'Bug')
                ->where('workItems.0.state_category', 'proposed')
                ->where('workItems.0.state_color', 'b2b2b2')
                ->where('workItems.0.assigned_to', null)
                ->where('workItems.0.roles', ['created'])
                ->where('workItems.0.tags', [])
                ->where('workItems.1.id', 10)
                ->where('workItems.1.state', 'Disponível QA')
                ->where('workItems.1.state_category', 'in_progress')
                ->where('workItems.1.state_color', 'ff9d00')
                ->where('workItems.1.roles', ['assigned', 'created'])
                ->where('workItems.1.iteration', 'Sprint 95')
                ->where('workItems.1.tags', ['backend', 'urgente'])
                ->where('workItems.1.url', 'https://dev.azure.com/MinhaOrg/Projeto%20X/_workitems/edit/10')
                ->where('pagination', ['page' => 1, 'per_page' => 25, 'total' => 2, 'last_page' => 1])
                ->where('projects', ['Projeto X', 'Projeto Y'])
                // Estados do processo personalizado, grupo "Em aberto", na ordem do fluxo (sem ocultos)
                ->where('states', fn ($states) => collect($states)->pluck('name')->all() === [
                    'New', 'In Progress', 'Disponível QA', 'Pronto Homologação', 'Homologação', 'Homologado',
                ])
                ->where('states.2', ['name' => 'Disponível QA', 'category' => 'in_progress', 'color' => 'ff9d00'])
            );

        $wiql = $this->wiqlSent();
        $this->assertStringContainsString("[System.WorkItemType] IN ('Product Backlog Item', 'Bug')", $wiql);
        $this->assertStringContainsString('([System.AssignedTo] = @Me OR [System.CreatedBy] = @Me)', $wiql);
        // Grupos montados pelas categorias do processo
        $this->assertStringContainsString("[System.State] NOT IN ('Done', 'Removed')", $wiql);

        // Processo sem projetos não é consultado
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'proc-unused'));

        // Consulta no nível da organização (todos os projetos)
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://dev.azure.com/MinhaOrg/_apis/wit/wiql?'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'workitemsbatch') && $r['ids'] === [20, 10]);
    }

    public function test_type_status_and_role_filters_change_the_query(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        $this->fakeAzure([], []);

        $this->actingAs($user)->get('/work-items?type=bug&status=done&role=assigned')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.type', 'bug')
                ->where('filters.status', 'done')
                ->where('filters.role', 'assigned')
                ->where('workItems', [])
            );

        $wiql = $this->wiqlSent();
        $this->assertStringContainsString("[System.WorkItemType] IN ('Bug')", $wiql);
        $this->assertStringContainsString("[System.State] IN ('Done')", $wiql);
        $this->assertStringContainsString('[System.AssignedTo] = @Me', $wiql);
        $this->assertStringNotContainsString('CreatedBy', $wiql);

        // Sem ids, não busca detalhes
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'workitemsbatch'));
    }

    public function test_status_all_has_no_state_condition(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        $this->fakeAzure([], []);

        $this->actingAs($user)->get('/work-items?type=pbi&status=all&role=created');

        $wiql = $this->wiqlSent();
        $this->assertStringContainsString("[System.WorkItemType] IN ('Product Backlog Item')", $wiql);
        $this->assertStringContainsString('[System.CreatedBy] = @Me', $wiql);
        $this->assertStringNotContainsString('System.State', $wiql);
    }

    public function test_results_are_cached_until_refresh(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        $this->fakeAzure([1, 2]);

        $this->actingAs($user)->get('/work-items?type=bug');
        $this->actingAs($user)->get('/work-items?type=bug');
        // processos + tipos do processo + WIQL + batch, uma vez só
        Http::assertSentCount(4);

        $this->actingAs($user)->get('/work-items?type=bug&fresh=1');
        // Atualizar recarrega tudo, inclusive o catálogo (estado novo no processo)
        Http::assertSentCount(8);
    }

    public function test_paginates_on_the_server_fetching_details_only_for_the_page(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        $this->fakeAzure(range(1000, 1059)); // 60 itens → 3 páginas de 25

        $this->actingAs($user)->get('/work-items?page=2')
            ->assertInertia(fn (Assert $page) => $page
                ->has('workItems', 25)
                ->where('workItems.0.id', 1025)
                ->where('workItems.24.id', 1049)
                ->where('pagination', ['page' => 2, 'per_page' => 25, 'total' => 60, 'last_page' => 3])
            );

        $this->actingAs($user)->get('/work-items?page=3')
            ->assertInertia(fn (Assert $page) => $page->has('workItems', 10)->where('workItems.0.id', 1050));

        // Página além do fim cai na última
        $this->actingAs($user)->get('/work-items?page=99')
            ->assertInertia(fn (Assert $page) => $page->where('pagination.page', 3));

        // Uma WIQL só (ids em cache); detalhes só dos ids de cada página
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), '_apis/wit/wiql')));
        $this->assertSame([range(1025, 1049), range(1050, 1059)], $this->batchCalls());
    }

    public function test_search_state_and_project_go_to_the_query(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        $this->fakeAzure([]);

        $this->actingAs($user)->get('/work-items?'.http_build_query(['q' => "login d'água", 'state' => ['Disponível QA'], 'project' => "Projeto O'Neil"]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.q', "login d'água")
                ->where('filters.state', ['Disponível QA'])
                ->where('filters.project', "Projeto O'Neil")
            );

        $wiql = $this->wiqlSent();
        // Aspas simples escapadas no padrão WIQL ('')
        $this->assertStringContainsString("([System.Title] CONTAINS 'login d''água' OR [System.Tags] CONTAINS 'login d''água')", $wiql);
        $this->assertStringContainsString("[System.State] IN ('Disponível QA')", $wiql);
        $this->assertStringContainsString("[System.TeamProject] = 'Projeto O''Neil'", $wiql);
    }

    public function test_filters_by_multiple_states_in_process_order(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        $this->fakeAzure([]);

        // Fora de ordem e com repetição: o servidor normaliza pela ordem do fluxo.
        $this->actingAs($user)->get('/work-items?'.http_build_query(['state' => ['Homologação', 'Disponível QA', 'Homologação']]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.state', ['Disponível QA', 'Homologação']));

        $wiql = $this->wiqlSent();
        $this->assertStringContainsString("[System.State] IN ('Disponível QA', 'Homologação')", $wiql);
        // Continua restrito ao grupo "Em aberto"
        $this->assertStringContainsString("[System.State] NOT IN ('Done', 'Removed')", $wiql);
    }

    public function test_numeric_search_also_matches_the_card_id(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        $this->fakeAzure([]);

        $this->actingAs($user)->get('/work-items?q=%23218834');

        $this->assertStringContainsString('([System.Id] = 218834 OR [System.Title] CONTAINS', $this->wiqlSent());
    }

    public function test_state_incompatible_with_status_group_is_ignored(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        $this->fakeAzure([]);

        $this->actingAs($user)->get('/work-items?status=open&state[]=Done&state[]=Homologado')
            ->assertInertia(fn (Assert $page) => $page->where('filters.state', ['Homologado']));

        $this->assertStringContainsString("[System.State] IN ('Homologado')", $this->wiqlSent());
    }

    public function test_unknown_state_is_ignored(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        $this->fakeAzure([]);

        $this->actingAs($user)->get('/work-items?'.http_build_query(['state' => ["Hacked') OR ([System.Id] > 0"]]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.state', []));

        $this->assertStringNotContainsString('Hacked', $this->wiqlSent());
    }

    public function test_falls_back_to_default_states_when_process_api_is_unavailable(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        Http::fake([
            '*/_apis/work/processes*' => Http::response(['message' => 'forbidden'], 403),
            '*/_apis/wit/wiql*' => Http::response(['workItems' => []]),
        ]);

        $this->actingAs($user)->get('/work-items')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('error', null)
                ->where('projects', [])
                ->where('states', fn ($states) => collect($states)->pluck('name')->contains('Committed'))
            );

        $this->assertStringContainsString("[System.State] NOT IN ('Done', 'Closed', 'Removed')", $this->wiqlSent());
    }

    public function test_shows_friendly_error_when_token_lacks_scope(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);

        $this->actingAs($user)->get('/work-items')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workItems', [])
                ->where('error', fn ($e) => str_contains($e, 'Work Items: Read'))
            );
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            // state precisa ser lista
            ->get("/work-items?type=Task' OR 1=1&page=0&state=Done")
            ->assertSessionHasErrors(['type', 'page', 'state']);
    }

    public function test_cannot_use_another_users_token(): void
    {
        $other = $this->tokenFor(User::factory()->create());
        $user = User::factory()->create();

        $this->actingAs($user)->get("/work-items?token={$other->id}")
            ->assertInertia(fn (Assert $page) => $page->where('tokens', [])->where('filters.token', null));

        Http::assertNothingSent();
    }
}
