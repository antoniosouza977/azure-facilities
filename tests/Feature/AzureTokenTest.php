<?php

namespace Tests\Feature;

use App\Models\AzureToken;
use App\Models\User;
use App\Services\Azure\Security\TokenCipher;
use App\Services\Azure\Security\TokenDecryptionException;
use App\Services\Azure\Security\TokenVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AzureTokenTest extends TestCase
{
    use RefreshDatabase;

    private const PAT = 'pat0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789zzzz';

    private const AZURE_ID = '762d4aac-78a1-6dc1-abc1-ea906f0a5518';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function fakeAzure(int $connectionStatus = 200, int $prStatus = 200): void
    {
        Http::fake([
            'dev.azure.com/MinhaOrg/_apis/connectionData*' => Http::response(
                $connectionStatus === 200 ? ['authenticatedUser' => ['id' => self::AZURE_ID, 'providerDisplayName' => 'Dev Teste']] : '',
                $connectionStatus,
                ['Content-Type' => 'application/json'],
            ),
            'dev.azure.com/MinhaOrg/_apis/git/pullrequests*' => Http::response(['value' => [], 'count' => 0], $prStatus),
        ]);
    }

    private function confirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_page_requires_password_confirmation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/azure-tokens')
            ->assertRedirect('/confirm-password');
    }

    public function test_token_is_validated_encrypted_and_never_exposed(): void
    {
        $this->fakeAzure();
        $user = User::factory()->create();

        $this->confirmed($user)
            ->post('/settings/azure-tokens', [
                'label' => 'Trabalho',
                'organization' => 'https://dev.azure.com/MinhaOrg/Projeto',
                'token' => self::PAT,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/azure-tokens');

        $token = AzureToken::sole();
        $this->assertSame('MinhaOrg', $token->organization);
        $this->assertSame(self::AZURE_ID, $token->azure_user_id);
        $this->assertSame('zzzz', $token->token_hint);
        $this->assertTrue($token->is_default);

        // Nada em texto puro no banco
        $row = (array) DB::table('azure_tokens')->first();
        $this->assertStringNotContainsString(self::PAT, json_encode($row));
        $this->assertSame(self::PAT, app(TokenVault::class)->reveal($token));

        // Autenticou via Basic com o PAT
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode(':'.self::PAT)));

        // Nem a página nem o JSON do model carregam o segredo
        $this->confirmed($user)->get('/settings/azure-tokens')
            ->assertInertia(fn (Assert $page) => $page->component('settings/AzureTokens')->has('tokens', 1)->missing('tokens.0.ciphertext'))
            ->assertDontSee(self::PAT)
            ->assertDontSee($token->ciphertext);
        $this->assertArrayNotHasKey('ciphertext', $token->toArray());
    }

    public function test_token_rejected_by_azure_is_not_saved(): void
    {
        $this->fakeAzure(connectionStatus: 401);

        $this->confirmed(User::factory()->create())
            ->from('/settings/azure-tokens')
            ->post('/settings/azure-tokens', ['label' => 'X', 'organization' => 'MinhaOrg', 'token' => self::PAT])
            ->assertSessionHasErrors('token');

        $this->assertDatabaseCount('azure_tokens', 0);
    }

    public function test_warns_when_token_cannot_read_code(): void
    {
        $this->fakeAzure(prStatus: 403);

        $this->confirmed(User::factory()->create())
            ->post('/settings/azure-tokens', ['label' => 'X', 'organization' => 'MinhaOrg', 'token' => self::PAT])
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('azure_tokens', 1);
    }

    public function test_rejects_malformed_input_without_calling_azure(): void
    {
        $this->confirmed(User::factory()->create())
            ->post('/settings/azure-tokens', ['label' => '', 'organization' => '../evil', 'token' => 'curto'])
            ->assertSessionHasErrors(['label', 'organization', 'token']);

        Http::assertNothingSent();
    }

    public function test_users_cannot_touch_other_users_tokens(): void
    {
        $owner = User::factory()->create();
        $token = app(TokenVault::class)->store($owner, [
            'label' => 'Do dono', 'organization' => 'MinhaOrg', 'azure_user_id' => self::AZURE_ID,
        ], self::PAT);

        $intruder = User::factory()->create();
        $this->confirmed($intruder)->delete("/settings/azure-tokens/{$token->id}")->assertNotFound();
        $this->confirmed($intruder)->patch("/settings/azure-tokens/{$token->id}/default")->assertNotFound();
        $this->confirmed($intruder)->post("/settings/azure-tokens/{$token->id}/check")->assertNotFound();

        $this->assertModelExists($token);
    }

    public function test_ciphertext_moved_to_another_user_cannot_be_decrypted(): void
    {
        $vault = app(TokenVault::class);
        $a = $vault->store(User::factory()->create(), ['label' => 'A', 'organization' => 'MinhaOrg', 'azure_user_id' => self::AZURE_ID], self::PAT);
        $b = $vault->store(User::factory()->create(), ['label' => 'B', 'organization' => 'MinhaOrg', 'azure_user_id' => self::AZURE_ID], 'outro'.self::PAT);

        $b->ciphertext = $a->ciphertext;

        $this->expectException(TokenDecryptionException::class);
        $vault->reveal($b);
    }

    public function test_deleting_default_promotes_another(): void
    {
        $user = User::factory()->create();
        $vault = app(TokenVault::class);
        $first = $vault->store($user, ['label' => 'A', 'organization' => 'MinhaOrg', 'azure_user_id' => self::AZURE_ID, 'is_default' => true], self::PAT);
        $second = $vault->store($user, ['label' => 'B', 'organization' => 'MinhaOrg', 'azure_user_id' => self::AZURE_ID], self::PAT);

        $this->confirmed($user)->delete("/settings/azure-tokens/{$first->id}")->assertRedirect();

        $this->assertModelMissing($first);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_rotate_command_reencrypts_with_current_key(): void
    {
        $user = User::factory()->create();
        $token = app(TokenVault::class)->store($user, ['label' => 'A', 'organization' => 'MinhaOrg', 'azure_user_id' => self::AZURE_ID], self::PAT);
        $this->assertSame(1, $token->key_version);

        config(['azure.token_encryption.current' => 2]);
        $this->app->forgetInstance(TokenCipher::class);
        $this->app->forgetInstance(TokenVault::class);

        $this->artisan('azure:rotate-tokens')->assertSuccessful();

        $token->refresh();
        $this->assertSame(2, $token->key_version);
        $this->assertSame(self::PAT, app(TokenVault::class)->reveal($token));
    }
}
