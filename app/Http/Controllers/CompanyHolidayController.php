<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyHolidayRequest;
use App\Http\Requests\UpdateCompanyHolidayRequest;
use App\Http\Resources\CompanyHolidayResource;
use App\Managers\CompanyHolidayManager;
use App\Models\CompanyHoliday;
use App\Support\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CompanyHolidayController extends Controller
{
    public function __construct(
        private readonly CompanyHolidayManager $companyHolidayManager,
    ) {}

    /**
     * <summary>
     *  Return paginated CompanyHoliday rows ordered by date.
     * </summary>
     *
     * @param Request $request Incoming HTTP request
     * @return AnonymousResourceCollection
     */
    public function getAgileCompanyHolidays(Request $request): AnonymousResourceCollection
    {
        // Act (Manager)
        $holidays = $this->companyHolidayManager->getAgileCompanyHolidays(QueryParams::fromRequest($request));

        // Return (Controller)
        return CompanyHolidayResource::collection($holidays);
    }

    /**
     * <summary>
     *  Create a CompanyHoliday.
     * </summary>
     *
     * @param StoreCompanyHolidayRequest $request Validated payload
     * @return CompanyHolidayResource HTTP 201
     */
    public function createCompanyHoliday(StoreCompanyHolidayRequest $request): CompanyHolidayResource
    {
        // Act (Manager)
        $holiday = $this->companyHolidayManager->createCompanyHoliday($request->validated());

        // Return (Controller)
        return new CompanyHolidayResource($holiday);
    }

    /**
     * <summary>
     *  Update a CompanyHoliday.
     * </summary>
     *
     * @param UpdateCompanyHolidayRequest $request Validated payload
     * @param CompanyHoliday $companyHoliday Route-model bound holiday
     * @return CompanyHolidayResource
     */
    public function updateCompanyHoliday(UpdateCompanyHolidayRequest $request, CompanyHoliday $companyHoliday): CompanyHolidayResource
    {
        // Act (Manager)
        $holiday = $this->companyHolidayManager->updateCompanyHoliday($companyHoliday, $request->validated());

        // Return (Controller)
        return new CompanyHolidayResource($holiday);
    }

    /**
     * <summary>
     *  Hard-delete a CompanyHoliday.
     * </summary>
     *
     * @param CompanyHoliday $companyHoliday Route-model bound holiday
     * @return JsonResponse HTTP 204 No Content
     */
    public function deleteCompanyHoliday(CompanyHoliday $companyHoliday): JsonResponse
    {
        // Act (Manager)
        $this->companyHolidayManager->deleteCompanyHoliday($companyHoliday);

        // Return (Controller)
        return response()->json(null, 204);
    }
}
