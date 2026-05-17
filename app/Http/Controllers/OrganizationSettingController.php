<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationSettingRequest;
use App\Http\Resources\OrganizationSettingResource;
use App\Managers\OrganizationSettingManager;

class OrganizationSettingController extends Controller
{
    public function __construct(
        private readonly OrganizationSettingManager $organizationSettingManager,
    ) {}

    /**
     * <summary>
     *  Return the singleton OrganizationSetting row (identity + operational profile + calendar).
     * </summary>
     *
     * @return OrganizationSettingResource
     */
    public function getOrganizationSetting(): OrganizationSettingResource
    {
        // Act (Manager)
        $setting = $this->organizationSettingManager->getOrganizationSetting();

        // Return (Controller)
        return new OrganizationSettingResource($setting);
    }

    /**
     * <summary>
     *  Update identity + operational profile fields on the singleton settings row.
     * </summary>
     *
     * @param UpdateOrganizationSettingRequest $request Validated payload
     * @return OrganizationSettingResource Updated settings row
     */
    public function updateOrganizationSetting(UpdateOrganizationSettingRequest $request): OrganizationSettingResource
    {
        // Act (Manager)
        $setting = $this->organizationSettingManager->updateOrganizationSetting($request->validated());

        // Return (Controller)
        return new OrganizationSettingResource($setting);
    }
}
