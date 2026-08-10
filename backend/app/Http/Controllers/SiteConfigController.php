<?php

namespace App\Http\Controllers;

use App\Support\SiteConfigPayload;

class SiteConfigController extends Controller
{
    public function index()
    {
        return $this->success(SiteConfigPayload::payload());
    }
}
