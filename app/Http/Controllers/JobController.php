<?php

namespace App\Http\Controllers;

use App\Models\SystemJob;

class JobController extends Controller
{
    public function index()
    {
        return view('jobs.index', [
            'jobs' => SystemJob::with('creator')->latest()->paginate(20),
        ]);
    }

    public function show(SystemJob $job)
    {
        $job->load('logs', 'creator');

        return view('jobs.show', compact('job'));
    }
}
