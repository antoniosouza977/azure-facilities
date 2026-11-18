<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Metadados de um PAT do Azure DevOps. O segredo em si fica criptografado em
 * `ciphertext` e só pode ser lido via App\Services\Azure\Security\TokenVault.
 *
 * @property string $id
 * @property int $user_id
 * @property string $label
 * @property string $organization
 * @property string $ciphertext
 * @property int $key_version
 * @property string $token_hint
 * @property string $azure_user_id
 * @property string|null $azure_display_name
 * @property bool $is_default
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $last_validated_at
 */
class AzureToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'label',
        'organization',
        'azure_user_id',
        'azure_display_name',
        'is_default',
        'expires_at',
    ];

    /** Nunca serializar o segredo (Inertia props, JSON, logs). */
    protected $hidden = [
        'ciphertext',
        'key_version',
    ];

    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'is_default' => 'boolean',
            'expires_at' => 'date',
            'last_used_at' => 'datetime',
            'last_validated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->endOfDay()->isPast();
    }

    /**
     * Representação segura para o front.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'organization' => $this->organization,
            'token_hint' => $this->token_hint,
            'azure_display_name' => $this->azure_display_name,
            'is_default' => $this->is_default,
            'is_expired' => $this->isExpired(),
            'expires_at' => $this->expires_at?->toDateString(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_validated_at' => $this->last_validated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
