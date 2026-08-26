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
            $request->category(),
            max(1, $request->integer('page', 1)),
        );

        return view('admin.reports.index', $report);
    }
}
