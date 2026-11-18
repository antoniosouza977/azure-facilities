<?php

namespace App\Services\Azure\Exceptions;

/**
 * O Azure recusou o PAT (inválido, expirado, revogado ou sem acesso à organização).
 */
class AzureAuthenticationException extends AzureApiException {}
