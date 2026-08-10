<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminDatabaseStatusResource extends JsonResource
{
    /**
     * @return array{
     *     database: string,
     *     list: list<array{name: string, rows: int, size_mb: float, update_time: ?string}>,
     *     total_count: int,
     *     total_rows: int,
     *     total_size_mb: float,
     *     optimization: array{candidate_count: int, estimated_reclaimable_mb: float, candidates: list<array{name: string, reclaimable_mb: float, fragmentation_ratio: float}>, cooldown_remaining_seconds: int, last_optimized_at: ?string}
     * }
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];
        $list = collect($payload['list'] ?? [])
            ->map(static function (mixed $row): array {
                $item = is_array($row) ? $row : [];

                return [
                    'name' => (string) ($item['name'] ?? ''),
                    'rows' => (int) ($item['rows'] ?? 0),
                    'size_mb' => round((float) ($item['size_mb'] ?? 0), 2),
                    'update_time' => isset($item['update_time'])
                        ? (string) $item['update_time']
                        : null,
                ];
            })
            ->values()
            ->all();
        $optimization = is_array($payload['optimization'] ?? null) ? $payload['optimization'] : [];
        $candidates = collect($optimization['candidates'] ?? [])
            ->map(static function (mixed $candidate): array {
                $item = is_array($candidate) ? $candidate : [];

                return [
                    'name' => (string) ($item['name'] ?? ''),
                    'reclaimable_mb' => round((float) ($item['reclaimable_mb'] ?? 0), 2),
                    'fragmentation_ratio' => round((float) ($item['fragmentation_ratio'] ?? 0), 4),
                ];
            })
            ->filter(static fn (array $candidate): bool => $candidate['name'] !== '')
            ->values()
            ->all();

        return [
            'database' => (string) ($payload['database'] ?? ''),
            'list' => $list,
            'total_count' => (int) ($payload['total_count'] ?? count($list)),
            'total_rows' => (int) ($payload['total_rows'] ?? 0),
            'total_size_mb' => round((float) ($payload['total_size_mb'] ?? 0), 2),
            'optimization' => [
                'candidate_count' => (int) ($optimization['candidate_count'] ?? count($candidates)),
                'estimated_reclaimable_mb' => round((float) ($optimization['estimated_reclaimable_mb'] ?? 0), 2),
                'candidates' => $candidates,
                'cooldown_remaining_seconds' => max(0, (int) ($optimization['cooldown_remaining_seconds'] ?? 0)),
                'last_optimized_at' => isset($optimization['last_optimized_at'])
                    ? (string) $optimization['last_optimized_at']
                    : null,
            ],
        ];
    }
}
