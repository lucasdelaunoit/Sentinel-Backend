<?php

namespace App\Http\Requests;

use App\Enums\RuleScope;
use App\Enums\RuleType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:255'],
            'type'       => ['required', Rule::in(RuleType::values())],
            'scope_type' => ['required', Rule::in(RuleScope::values())],
            'scope_id'   => ['nullable', 'integer'],
            'enabled'    => ['sometimes', 'boolean'],
            'params'     => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $type   = $this->input('type');
            $params = $this->input('params', []);
            foreach ($this->paramRules($type) as $key => $rules) {
                if (!array_key_exists($key, $params)) {
                    $v->errors()->add("params.$key", "Missing param '$key' for rule type '$type'.");
                }
            }
        });
    }

    /** @return array<string, string> */
    private function paramRules(?string $type): array
    {
        return match ($type) {
            RuleType::BusFactor->value      => ['max_bus_factor' => 'int'],
            RuleType::MinSkill->value       => ['skill_id' => 'int', 'min_level' => 'int', 'min_count' => 'int'],
            RuleType::MinCoverage->value    => ['skill_id' => 'int', 'min_pct' => 'int'],
            RuleType::RoleRedundancy->value => ['role' => 'string', 'min_count' => 'int'],
            default                         => [],
        };
    }
}
