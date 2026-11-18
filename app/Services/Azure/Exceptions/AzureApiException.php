<?php

namespace App\Services\Azure\Exceptions;

use RuntimeException;

/**
 * Erro ao falar com a API do Azure DevOps. A mensagem é segura para exibir ao
 * usuário (nunca contém o token).
 */
class AzureApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
