<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Database\ExportDatabaseBackupRequest;
use App\Http\Requests\Admin\V2\Database\OptimizeDatabaseTablesRequest;
use App\Http\Requests\Admin\V2\Database\ShowDatabaseStatusRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminDatabaseStatusResource;
use App\Services\System\DatabaseStatusService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseStatusController extends Controller
{
    public function __construct(
        private readonly DatabaseStatusService $databaseStatus,
    ) {}

    public function status(ShowDatabaseStatusRequest $request): JsonResponse
    {
        return $this->success(
            AdminDatabaseStatusResource::make($this->databaseStatus->status())->resolve()
        );
    }

    public function optimize(OptimizeDatabaseTablesRequest $request): JsonResponse
    {
        try {
            $result = $this->databaseStatus->optimize(
                $request->tables(),
                $request->user()?->id ? (int) $request->user()->id : null,
                $request->ip(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error(42200, $exception->getMessage());
        } catch (RuntimeException $exception) {
            return $this->error(50000, $exception->getMessage());
        }

        return $this->success(
            AdminActionResultResource::make($result)->resolve(),
            (string) $result['message']
        );
    }

    public function exportBackup(ExportDatabaseBackupRequest $request): BinaryFileResponse|JsonResponse
    {
        try {
            $backup = $this->databaseStatus->createBackup(
                $request->user()?->id ? (int) $request->user()->id : null,
                $request->ip(),
            );
        } catch (RuntimeException $exception) {
            return $this->error(50000, $exception->getMessage());
        }

        return response()
            ->download($backup['absolute_path'], $backup['filename'], [
                'Content-Type' => 'application/sql',
                'File_name' => $backup['filename'],
            ])
            ->deleteFileAfterSend(true);
    }
}
