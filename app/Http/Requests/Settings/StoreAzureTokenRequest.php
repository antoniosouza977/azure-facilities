<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAzureTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Aceita "InfortechMS", "https://dev.azure.com/InfortechMS/..." ou
     * "https://infortechms.visualstudio.com".
     */
    protected function prepareForValidation(): void
    {
        $org = trim((string) $this->input('organization'));

        if (preg_match('#dev\.azure\.com/([^/?\s]+)#i', $org, $m)) {
            $org = $m[1];
        } elseif (preg_match('#^(?:https?://)?([^./\s]+)\.visualstudio\.com#i', $org, $m)) {
            $org = $m[1];
        }

        $this->merge([
            'organization' => rawurldecode($org),
            'label' => trim((string) $this->input('label')),
            'token' => trim((string) $this->input('token')),
        ]);
    }

    public function rules(): array
    {
        return [
            'label' => [
                'required', 'string', 'max:60',
                Rule::unique('azure_tokens', 'label')->where('user_id', $this->user()->id),
            ],
            'organization' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'token' => ['required', 'string', 'min:20', 'max:200', 'regex:/^[A-Za-z0-9]+$/'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'is_default' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'label.required' => 'Dê um nome para identificar o token.',
            'label.unique' => 'Você já tem um token com esse nome.',
            'organization.required' => 'Informe a organização do Azure DevOps.',
            'organization.regex' => 'Organização inválida. Use o nome (ex.: MinhaOrg) ou a URL https://dev.azure.com/MinhaOrg.',
            'token.required' => 'Cole o Personal Access Token.',
            'token.min' => 'Esse valor não parece um PAT do Azure DevOps.',
            'token.max' => 'Esse valor não parece um PAT do Azure DevOps.',
            'token.regex' => 'Esse valor não parece um PAT do Azure DevOps (só letras e números).',
            'expires_at.after_or_equal' => 'A data de expiração já passou.',
        ];
    }
}
