<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRuleRequest;
use App\Http\Requests\UpdateRuleRequest;
use App\Http\Resources\RuleResource;
use App\Managers\RuleManager;
use App\Models\Rule;
use App\Support\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RuleController extends Controller
{
    public function __construct(
        private readonly RuleManager $ruleManager,
    ) {}

    /**
     * <summary>
     *  Paginated list of Rule rows.
     * </summary>
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function getAgileRules(Request $request): AnonymousResourceCollection
    {
        // Act (Manager)
        $rules = $this->ruleManager->getAgileRules(QueryParams::fromRequest($request));

        // Return (Controller)
        return RuleResource::collection($rules);
    }

    /**
     * <summary>
     *  Create a Rule.
     * </summary>
     *
     * @param StoreRuleRequest $request
     * @return RuleResource
     */
    public function createRule(StoreRuleRequest $request): RuleResource
    {
        // Act (Manager)
        $rule = $this->ruleManager->createRule($request->validated());

        // Return (Controller)
        return new RuleResource($rule);
    }

    /**
     * <summary>
     *  Update a Rule.
     * </summary>
     */
    public function updateRule(UpdateRuleRequest $request, Rule $rule): RuleResource
    {
        // Act (Manager)
        $rule = $this->ruleManager->updateRule($rule, $request->validated());

        // Return (Controller)
        return new RuleResource($rule);
    }

    /**
     * <summary>
     *  Hard-delete a Rule.
     * </summary>
     */
    public function deleteRule(Rule $rule): JsonResponse
    {
        // Act (Manager)
        $this->ruleManager->deleteRule($rule);

        // Return (Controller)
        return response()->json(null, 204);
    }

    /**
     * <summary>
     *  Return current org-wide rule violations (cached snapshot).
     * </summary>
     */
    public function getRuleViolations(): JsonResponse
    {
        // Act (Manager)
        $violations = $this->ruleManager->getOrganizationViolations();

        // Return (Controller)
        return response()->json(['data' => $violations]);
    }

}
