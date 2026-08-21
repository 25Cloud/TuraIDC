<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Models\VerificationHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 实名认证迁移（幂等）：
 * 从旧库（source_connection）shd_certifi_person / shd_certifi_company 迁移到
 * users（real_name / id_card / is_verified / verification_status / verified_at）与
 * verification_histories（全量留痕）。
 *
 * 状态映射（老 → 新）：
 *   1 通过 → is_verified=1 / verification_status=2
 *   2 待审 → is_verified=0 / verification_status=1
 *   3/4 驳回/失败 → is_verified=0 / verification_status=3
 *
 * 同一用户 person/company 重叠时取 update_time 最新写 users，其余写 histories。
 */
class MigrateRealNameCommand extends Command
{
    protected $signature = 'app:migrate-real-name
        {--dry-run : 只统计，不写入数据库}
        {--force : 允许覆盖已认证用户的实名信息}
        {--json : 以 JSON 输出结果}';

    protected $description = '从旧库迁移实名认证数据到 users / verification_histories';

    private const VERIFICATION_TYPE_PERSON = 'IDENTITY_CARD';

    private const VERIFICATION_TYPE_COMPANY = 'COMPANY';

    public function handle(): int
    {
        $sourceConnection = (string) config('catalog_migration.source_connection', 'mysql');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $json = (bool) $this->option('json');

        $sourceSchema = DB::connection($sourceConnection)->getSchemaBuilder();
        $hasPerson = $sourceSchema->hasTable('shd_certifi_person');
        $hasCompany = $sourceSchema->hasTable('shd_certifi_company');
        if (! $hasPerson && ! $hasCompany) {
            $this->error('旧库不存在 shd_certifi_person / shd_certifi_company');

            return self::FAILURE;
        }

        // 1. 汇总所有实名记录（person + company），按用户分组
        $records = [];
        if ($hasPerson) {
            foreach ($this->fetchRecords($sourceConnection, 'shd_certifi_person', self::VERIFICATION_TYPE_PERSON) as $record) {
                $records[] = $record;
            }
        }
        if ($hasCompany) {
            foreach ($this->fetchRecords($sourceConnection, 'shd_certifi_company', self::VERIFICATION_TYPE_COMPANY) as $record) {
                $records[] = $record;
            }
        }

        $byUser = [];
        foreach ($records as $record) {
            $byUser[(int) $record['user_id']][] = $record;
        }

        $updatedUsers = 0;
        $writtenHistories = 0;
        $skipped = 0;

        foreach ($byUser as $userId => $userRecords) {
            if ($userId <= 0) {
                continue;
            }

            $user = User::query()->find($userId);
            if (! $user instanceof User) {
                $skipped++;

                continue;
            }

            // 取 update_time 最新的记录作为权威
            usort($userRecords, static fn (array $left, array $right): int => strcmp((string) $right['updated_at'], (string) $left['updated_at']));
            $authoritative = $userRecords[0];

            // 已认证且非 --force：跳过
            if (! $force && (int) ($user->is_verified ?? 0) === 1 && trim((string) ($user->real_name ?? '')) !== '') {
                $skipped++;

                continue;
            }

            $this->applyToUser($user, $authoritative, $dryRun);
            $updatedUsers++;

            foreach ($userRecords as $record) {
                $this->writeHistory($record, $dryRun);
                $writtenHistories++;
            }
        }

        if ($json) {
            $this->line(json_encode([
                'source_records' => count($records),
                'users_updated' => $updatedUsers,
                'histories_written' => $writtenHistories,
                'skipped' => $skipped,
                'dry_run' => $dryRun,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info($dryRun ? '=== 实名迁移预检（--dry-run）===' : '=== 实名迁移完成 ===');
        $this->line('源记录数: '.count($records));
        $this->line('用户更新: '.$updatedUsers);
        $this->line('历史写入: '.$writtenHistories);
        $this->line('跳过: '.$skipped);

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function fetchRecords(string $connection, string $table, string $type): iterable
    {
        $rows = DB::connection($connection)->table($table)->orderBy('update_time')->get();

        foreach ($rows as $row) {
            yield [
                'user_id' => (int) ($row->auth_user_id ?? $row->uid ?? 0),
                'type' => $type,
                'real_name' => trim((string) ($row->realname ?? $row->real_name ?? '')),
                'id_card' => trim((string) ($row->idcard ?? $row->id_card ?? '')),
                'status' => (int) ($row->status ?? 0),
                'certify_id' => trim((string) ($row->certify_id ?? '')),
                'message' => trim((string) ($row->reason ?? $row->message ?? '')),
                'update_time' => trim((string) ($row->update_time ?? $row->updated_at ?? '')),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function applyToUser(User $user, array $record, bool $dryRun): void
    {
        [$isVerified, $verificationStatus] = $this->mapStatus((int) $record['status']);

        if ($dryRun) {
            return;
        }

        $payload = [
            'real_name' => $record['real_name'],
            'id_card' => $record['id_card'],
            'is_verified' => $isVerified,
            'verification_status' => $verificationStatus,
            'verification_message' => $record['message'],
            'verification_certify_id' => $record['certify_id'] !== '' ? $record['certify_id'] : null,
            'verified_at' => $isVerified === 1 && $record['update_time'] !== ''
                ? $this->normalizeTimestamp($record['update_time'])
                : null,
        ];

        $user->forceFill($payload);
        $user->save();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeHistory(array $record, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        [$isVerified, $verificationStatus] = $this->mapStatus((int) $record['status']);

        VerificationHistory::query()->create([
            'user_id' => (int) $record['user_id'],
            'real_name' => $record['real_name'],
            'id_card' => $record['id_card'],
            'verification_status' => $verificationStatus,
            'verification_message' => $record['message'],
            'verification_certify_id' => $record['certify_id'] !== '' ? $record['certify_id'] : null,
            'verification_biz_code' => 'MIGRATE',
            'verification_type' => $record['type'],
            'submitted_at' => $record['update_time'] !== '' ? $this->normalizeTimestamp($record['update_time']) : now(),
            'completed_at' => $isVerified === 1 && $record['update_time'] !== ''
                ? $this->normalizeTimestamp($record['update_time'])
                : null,
        ]);
    }

    /**
     * @return array{0: int, 1: int} [is_verified, verification_status]
     */
    private function mapStatus(int $legacyStatus): array
    {
        switch ($legacyStatus) {
            case 1:
                return [1, 2];
            case 2:
                return [0, 1];
            default:
                return [0, 3];
        }
    }

    private function normalizeTimestamp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{10}$/', $value) === 1) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        return str_contains($value, ' ') ? $value : $value.' 00:00:00';
    }
}
