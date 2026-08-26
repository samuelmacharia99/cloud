<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FilterAdminReportsRequest;
use App\Services\AdminBookkeepingService;

class AdminReportsController extends Controller
{
    public function index(FilterAdminReportsRequest $request, AdminBookkeepingService $bookkeeping)
    {
        $report = $bookkeeping->build(
            $request->year(),
            $request->month(),
        );

        return view('admin.reports.index', $report);
    }
}
