<?php

namespace App\Http\Controllers;

use App\Services\System\LogReaderService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogController extends Controller
{
    public function __construct(protected LogReaderService $logs)
    {
    }

    public function index(Request $request)
    {
        $sources = $this->logs->sources();
        $source = (string) $request->query('source', 'panel');
        if (! isset($sources[$source])) {
            $source = 'panel';
        }

        $lines = (int) $request->query('lines', 200);
        $search = (string) $request->query('q', '');

        return view('logs.index', [
            'sources' => $sources,
            'source'  => $source,
            'lines'   => $lines,
            'search'  => $search,
            'content' => $this->logs->read($source, $lines, $search),
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        $source = (string) $request->query('source', 'panel');
        $content = $this->logs->read($source, 1000);

        $filename = 'librestack-' . preg_replace('/[^a-z0-9_-]/i', '', $source) . '-' . now()->format('Ymd_His') . '.log';

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, ['Content-Type' => 'text/plain']);
    }
}
