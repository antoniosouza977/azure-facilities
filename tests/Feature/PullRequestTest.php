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

class PullRequestTest extends TestCase
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
    private function pr(int $id, string $repo, string $created, array $overrides = []): array
    {
        return array_replace_recursive([
            'pullRequestId' => $id,
            'title' => "PR {$id}",
            'status' => 'active',
            'isDraft' => false,
            'mergeStatus' => 'succeeded',
            'creationDate' => $created,
            'sourceRefName' => 'refs/heads/PBI/SP_95_218834',
            'targetRefName' => 'refs/heads/sprints/sprint_095',
            'repository' => ['name' => $repo, 'project' => ['name' => 'Projeto X']],
            'createdBy' => ['displayName' => 'Alguém'],
            'reviewers' => [],
        ], $overrides);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/pull-requests')->assertRedirect('/login');
    }

    public function test_shows_empty_state_without_token(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/pull-requests')
            ->assertInertia(fn (Assert $page) => $page->component('PullRequests/Index')->where('tokens', [])->where('pullRequests', []));

        Http::assertNothingSent();
    }

    public function test_lists_authored_and_reviewing_prs_across_repositories(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);

        Http::fake(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);

            if (isset($q['searchCriteria_creatorId'])) {
                return Http::response(['value' => [
                    $this->pr(1, 'api', '2026-11-01T10:00:00Z', ['title' => '[sprints/sprint_095] - [pbi:218834] - Feature']),
                    // Também sou revisor deste (auto-review)
                    $this->pr(3, 'front', '2026-11-03T10:00:00Z', ['reviewers' => [['id' => self::ME, 'displayName' => 'Eu', 'vote' => 0]]]),
                ]]);
            }

            return Http::response(['value' => [
                $this->pr(2, 'front', '2026-11-02T10:00:00Z', ['mergeStatus' => 'conflicts', 'reviewers' => [
                    ['id' => self::ME, 'displayName' => 'Eu', 'vote' => 0],
                    ['id' => 'x', 'displayName' => 'Outro', 'vote' => 10],
                ]]),
                $this->pr(3, 'front', '2026-11-03T10:00:00Z', ['reviewers' => [['id' => self::ME, 'displayName' => 'Eu', 'vote' => 0]]]),
            ]]);
        });

        $this->actingAs($user)->get('/pull-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PullRequests/Index')
                ->where('error', null)
                ->has('pullRequests', 3)
                // Ordenado do mais novo para o mais antigo e sem duplicar o #3
                ->where('pullRequests.0.id', 3)
                ->where('pullRequests.0.roles', ['author', 'reviewer'])
                ->where('pullRequests.0.awaiting_my_review', false)
                ->where('pullRequests.1.id', 2)
                ->where('pullRequests.1.awaiting_my_review', true)
                ->where('pullRequests.1.merge_status', 'conflicts')
                ->where('pullRequests.1.url', 'https://dev.azure.com/MinhaOrg/Projeto%20X/_git/front/pullrequest/2')
                ->where('pullRequests.2.id', 1)
                ->where('pullRequests.2.source_branch', 'PBI/SP_95_218834')
                ->where('pullRequests.2.work_item.id', 218834)
            );

        // Consulta no nível da organização (todos os projetos/repos), filtrando por mim
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://dev.azure.com/MinhaOrg/_apis/git/pullrequests?')
            && str_contains($r->url(), 'searchCriteria.creatorId='.self::ME));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'searchCriteria.reviewerId='.self::ME));
    }

    public function test_results_are_cached_until_refresh(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        Http::fake(['*' => Http::response(['value' => []])]);

        $this->actingAs($user)->get('/pull-requests?role=author');
        $this->actingAs($user)->get('/pull-requests?role=author');
        Http::assertSentCount(1);

        $this->actingAs($user)->get('/pull-requests?role=author&fresh=1');
        Http::assertSentCount(2);
    }

    public function test_shows_friendly_error_when_token_is_revoked(): void
    {
        $user = User::factory()->create();
        $this->tokenFor($user);
        Http::fake(['*' => Http::response('<html>login</html>', 203, ['Content-Type' => 'text/html'])]);

        $this->actingAs($user)->get('/pull-requests?role=author')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pullRequests', [])
                ->where('error', fn ($e) => str_contains($e, 'Token inválido'))
            );
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/pull-requests?status=hack')
            ->assertSessionHasErrors('status');
    }

    public function test_cannot_use_another_users_token(): void
    {
        $other = $this->tokenFor(User::factory()->create());
        $user = User::factory()->create();

        $this->actingAs($user)->get("/pull-requests?token={$other->id}")
            ->assertInertia(fn (Assert $page) => $page->where('tokens', [])->where('filters.token', null));

        Http::assertNothingSent();
    }
}
