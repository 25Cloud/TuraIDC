<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\V2\SiteConfigRequest;
use App\Http\Requests\Site\V2\SiteHomeHeroRequest;
use App\Http\Requests\Site\V2\SiteHomeRequest;
use App\Http\Resources\Site\V2\SiteConfigResource;
use App\Http\Resources\Site\V2\SiteHomeHeroResource;
use App\Http\Resources\Site\V2\SiteHomeOverviewResource;
use App\Services\Site\SiteHomeService;
use App\Support\SiteConfigPayload;

class HomeController extends Controller
{
    public function __construct(
        private readonly SiteHomeService $siteHomeService,
    ) {}

    public function config(SiteConfigRequest $request)
    {
        return $this->success(SiteConfigResource::make(SiteConfigPayload::payload())->resolve());
    }

    public function home(SiteHomeRequest $request)
    {
        return $this->success(SiteHomeOverviewResource::make($this->siteHomeService->overview(
            groupLimit: $request->groupLimit(),
            noticeLimit: $request->noticeLimit(),
            helpLimit: $request->helpLimit(),
        ))->resolve());
    }

    public function hero(SiteHomeHeroRequest $request)
    {
        return $this->success(SiteHomeHeroResource::make($this->siteHomeService->hero())->resolve());
    }
}
