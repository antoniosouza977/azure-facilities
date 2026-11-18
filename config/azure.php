<?php

/*
 * Converte "1:base64,2:base64" em [1 => 'base64', 2 => 'base64'].
 * Feito aqui (e não em runtime) para funcionar com `config:cache`.
 */
$keys = [];
foreach (array_filter(array_map('trim', explode(',', (string) env('AZURE_TOKEN_KEYS', '')))) as $entry) {
    [$version, $key] = array_pad(explode(':', $entry, 2), 2, '');
    if (ctype_digit($version) && $key !== '') {
        $keys[(int) $version] = $key;
    }
}

return [

    'base_url' => env('AZURE_DEVOPS_BASE_URL', 'https://dev.azure.com'),

    'api_version' => '7.1',

    'timeout' => (int) env('AZURE_HTTP_TIMEOUT', 15),

    // Tempo (segundos) que a listagem de PRs fica em cache por token/filtro.
    // Menor que o intervalo do polling da tela (10s) para cada ciclo trazer dados novos.
    'pr_cache_ttl' => (int) env('AZURE_PR_CACHE_TTL', 5),

    // Tempo (segundos) que a listagem de PBIs/Bugs fica em cache por token/filtro.
    'work_item_cache_ttl' => (int) env('AZURE_WI_CACHE_TTL', 60),

    // Máximo de ids de work items (PBIs/Bugs) por consulta. Só os ids vêm de
    // uma vez; os detalhes são buscados por página. A WIQL aceita até 20.000.
    'work_item_limit' => (int) env('AZURE_WI_LIMIT', 5000),

    // Máximo de PRs buscados por consulta (por papel: autor / revisor).
    'pr_limit' => (int) env('AZURE_PR_LIMIT', 200),

    'token_encryption' => [
        'keys' => $keys,
        'current' => (int) env('AZURE_TOKEN_KEY_CURRENT', 1),
    ],

    'allowed_email_domains' => array_values(array_filter(array_map(
        fn ($d) => strtolower(trim($d)),
        explode(',', (string) env('ALLOWED_EMAIL_DOMAINS', ''))
    ))),

];
