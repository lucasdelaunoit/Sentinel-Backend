<?php

namespace App\Managers;

use App\Jobs\RecalculateProjectRiskJob;
use App\Models\OrganizationSetting;
use App\Models\Project;
use App\Services\OrganizationSettingService;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrganizationSettingManager
{
    public function __construct(
        private readonly OrganizationSettingService $organizationSettingService,
    ) {}

    /**
     * <summary>
     *  Return the singleton OrganizationSetting row, creating it on first call.
     * </summary>
     *
     * @return OrganizationSetting Singleton settings row
     */
    public function getOrganizationSetting(): OrganizationSetting
    {
        return $this->organizationSettingService->getOrganizationSetting();
    }

    /**
     * <summary>
     *  Update identity + operational profile fields on the singleton settings row inside a transaction.
     * </summary>
     *
     * @param array $data Validated payload
     * @return OrganizationSetting Freshly reloaded settings row
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function updateOrganizationSetting(array $data): OrganizationSetting
    {
        $setting = DB::transaction(fn() => $this->organizationSettingService->updateOrganizationSetting($data));

        Project::query()->whereNull('archived_at')->get(['id'])->each(
            fn(Project $p) => RecalculateProjectRiskJob::dispatch($p)
        );

        return $setting;
    }
}
