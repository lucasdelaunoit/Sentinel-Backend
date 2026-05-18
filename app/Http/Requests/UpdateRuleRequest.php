<?php

namespace App\Http\Requests;

use App\Enums\RuleScope;
use App\Enums\RuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'       => ['sometimes', 'required', 'string', 'max:255'],
            'type'       => ['sometimes', 'required', Rule::in(RuleType::values())],
            'scope_type' => ['sometimes', 'required', Rule::in(RuleScope::values())],
            'scope_id'   => ['sometimes', 'nullable', 'integer'],
            'enabled'    => ['sometimes', 'boolean'],
            'params'     => ['sometimes', 'array'],
        ];
    }
}
