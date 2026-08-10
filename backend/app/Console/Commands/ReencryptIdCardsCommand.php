<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Casts\LegacyEncrypted;
use App\Models\User;
use App\Models\VerificationHistory;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * 扫描 users.id_card 与 verification_histories.id_card，
 * 把历史遗留的明文记录通过 LegacyEncrypted cast 重新加密保存。
 *
 * 默认 dry-run，只统计分布。要实际写入必须加 --apply。
 *
 * 用法：
 *   php artisan verification:reencrypt-id-cards
 *   php artisan verification:reencrypt-id-cards --apply
 *   php artisan verification:reencrypt-id-cards --apply --chunk=50 --table=users
 */
class ReencryptIdCardsCommand extends Command
{
    protected $signature = 'verification:reencrypt-id-cards
        {--apply : 真正写入；不指定时仅 dry-run 统计分布}
        {--chunk=100 : 单批处理记录数}
        {--table=all : 作用范围（users / histories / all）}';

    protected $description = '将历史遗留的明文 id_card 通过 LegacyEncrypted cast 重新加密回写';

    /** 密文理论长度下限，低于此值一律按明文处理 */
    private const PLAINTEXT_LENGTH_THRESHOLD = 30;

    /** 异常区间上限，长度在 [threshold, AMBIGUOUS_UPPER) 内无法确定是否密文 */
    private const AMBIGUOUS_UPPER = 100;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $targets = $this->resolveTargets();

        if ($targets === []) {
            $this->error('未识别的 --table 值。可选：users / histories / all');

            return self::FAILURE;
        }

        $this->line($apply ? '<fg=red>MODE: APPLY（真正写入）</>' : '<fg=yellow>MODE: DRY-RUN（仅统计）</>');

        $grandTotal = [
            'scanned' => 0,
            'already_encrypted' => 0,
            'converted' => 0,
            'ambiguous' => 0,
            'unreadable' => 0,
        ];

        foreach ($targets as $label => $config) {
            $this->newLine();
            $this->info("[{$label}] 扫描 {$config['table']}.{$config['column']} ...");

            $stats = $this->processTable($config['model'], $config['column'], $chunkSize, $apply);

            $this->table(
                ['指标', '数量'],
                [
                    ['扫描记录', $stats['scanned']],
                    ['已加密（跳过）', $stats['already_encrypted']],
                    ['视为明文（'.($apply ? '已回写' : '待回写').'）', $stats['converted']],
                    ['长度可疑（人工确认）', $stats['ambiguous']],
                    ['解密失败 + 超长（跳过）', $stats['unreadable']],
                ]
            );

            foreach ($grandTotal as $metric => $_) {
                $grandTotal[$metric] += $stats[$metric];
            }
        }

        $this->newLine();
        $this->info('汇总：');
        $this->table(
            ['指标', '数量'],
            [
                ['扫描记录', $grandTotal['scanned']],
                ['已加密', $grandTotal['already_encrypted']],
                ['视为明文（'.($apply ? '已回写' : '待回写').'）', $grandTotal['converted']],
                ['长度可疑', $grandTotal['ambiguous']],
                ['解密失败 + 超长', $grandTotal['unreadable']],
            ]
        );

        if (! $apply && $grandTotal['converted'] > 0) {
            $this->newLine();
            $this->warn('以上为 dry-run 预估。确认无误后，追加 --apply 实际执行。');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{model: class-string<Model>, table: string, column: string}>
     */
    private function resolveTargets(): array
    {
        $targets = [];
        $option = strtolower(trim((string) $this->option('table'))) ?: 'all';

        if (in_array($option, ['all', 'users'], true)) {
            $targets['users'] = [
                'model' => User::class,
                'table' => 'users',
                'column' => 'id_card',
            ];
        }

        if (in_array($option, ['all', 'histories'], true)) {
            $targets['verification_histories'] = [
                'model' => VerificationHistory::class,
                'table' => 'verification_histories',
                'column' => 'id_card',
            ];
        }

        return $targets;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{scanned: int, already_encrypted: int, converted: int, ambiguous: int, unreadable: int}
     */
    private function processTable(string $modelClass, string $column, int $chunkSize, bool $apply): array
    {
        $stats = [
            'scanned' => 0,
            'already_encrypted' => 0,
            'converted' => 0,
            'ambiguous' => 0,
            'unreadable' => 0,
        ];

        /** @var Builder $query */
        $query = $modelClass::query()
            ->where($column, '!=', '')
            ->orderBy('id');

        $total = (int) (clone $query)->count();
        if ($total === 0) {
            return $stats;
        }

        $progress = $this->output->createProgressBar($total);
        $progress->start();

        $query->chunkById($chunkSize, function ($records) use ($column, $apply, &$stats, $progress) {
            foreach ($records as $record) {
                $stats['scanned']++;
                $raw = (string) $record->getRawOriginal($column);

                if ($raw === '') {
                    $progress->advance();

                    continue;
                }

                $decryptable = $this->isDecryptable($raw);
                if ($decryptable) {
                    $stats['already_encrypted']++;
                    $progress->advance();

                    continue;
                }

                $length = strlen($raw);

                if ($length < self::PLAINTEXT_LENGTH_THRESHOLD) {
                    if ($apply) {
                        $this->reencryptRecord($record, $column, $raw);
                    }
                    $stats['converted']++;
                } elseif ($length < self::AMBIGUOUS_UPPER) {
                    $this->line(sprintf(
                        "\n  <fg=yellow>[可疑]</> %s#%s %s 长度 %d，既非典型明文也非合法密文，已跳过，请人工确认",
                        class_basename($record),
                        $record->getKey(),
                        $column,
                        $length,
                    ));
                    $stats['ambiguous']++;
                } else {
                    $stats['unreadable']++;
                }

                $progress->advance();
            }
        }, 'id');

        $progress->finish();
        $this->newLine();

        return $stats;
    }

    /**
     * 明确"这条值是加密过的密文"：用 Crypt 解密能成功即视为密文。
     */
    private function isDecryptable(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    /**
     * 单条记录独立事务：单条失败不影响其他。
     *
     * 直接用 LegacyEncrypted::set 手动加密后走 DB::table 直写。
     * 原因：User 模型没有把 id_card 注册到 casts() 数组（只有读 accessor），
     * 依赖 forceFill + save 的自动 cast 在 User 表上不会生效。
     * 两个表统一走直写路径，避免依赖各模型的 cast 配置差异。
     */
    private function reencryptRecord(Model $record, string $column, string $plaintext): void
    {
        try {
            $encrypted = (new LegacyEncrypted)->set(
                $record,
                $column,
                $plaintext,
                $record->getAttributes()
            );

            DB::transaction(function () use ($record, $column, $encrypted) {
                DB::table($record->getTable())
                    ->where($record->getKeyName(), $record->getKey())
                    ->update([$column => $encrypted]);
            });
        } catch (\Throwable $exception) {
            $this->line(sprintf(
                "\n  <fg=red>[失败]</> %s#%s：%s",
                class_basename($record),
                $record->getKey(),
                $exception->getMessage(),
            ));
        }
    }
}
