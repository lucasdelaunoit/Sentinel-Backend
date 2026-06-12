<?php

namespace App\Managers;

use App\Models\OrganizationSetting;
use App\Models\Project;
use App\Services\OrganizationSettingService;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrganizationSettingManager
{
    use Concerns\DispatchesRecalculations;

    public function __construct(
        private readonly OrganizationSettingService $organizationSettingService,
        private readonly ProjectService $projectService,
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

        $this->projectService->getNonArchivedProjects()->each(
            fn(Project $p) => $this->dispatchProjectRecalculation($p)
        );

        return $setting;
    }
}
