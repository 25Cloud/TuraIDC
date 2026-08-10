<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Site\SiteHomeService;

class SiteHomeController extends Controller
{
    public function __construct(
        private readonly SiteHomeService $siteHomeService,
    ) {}

    public function index()
    {
        return $this->success($this->siteHomeService->overview());
    }

    public function hero()
    {
        return $this->success($this->siteHomeService->hero());
    }
}
