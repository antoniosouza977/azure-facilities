<?php

namespace App\Services\Azure;

use App\Models\AzureToken;
use App\Services\Azure\Security\TokenVault;
use SensitiveParameter;

class AzureClientFactory
{
    public function __construct(private readonly TokenVault $vault) {}

    public function forToken(AzureToken $token): AzureDevOpsClient
    {
        $client = $this->make($token->organization, $this->vault->reveal($token));

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $client;
    }

    public function make(string $organization, #[SensitiveParameter] string $pat): AzureDevOpsClient
    {
        return new AzureDevOpsClient($organization, $pat);
    }
}
