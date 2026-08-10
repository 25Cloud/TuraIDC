<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\MediaLibraryOrganizer;
use Illuminate\Console\Command;

class OrganizeMediaUploadsCommand extends Command
{
    protected $signature = 'media:organize-uploads {--dry-run : 仅预览将要复制和更新的媒体路径}';

    protected $description = '将受管媒体复制到统一媒体库目录，并更新主要引用路径';

    public function handle(MediaLibraryOrganizer $organizer): int
    {
        $result = $organizer->organize((bool) $this->option('dry-run'));

        $this->info((bool) $this->option('dry-run') ? '媒体路径整理 dry-run 完成' : '媒体路径整理完成');
        $this->line('复制文件: '.(int) $result['copied_files']);
        $this->line('更新 media_files: '.(int) $result['updated_media_rows']);
        $this->line('新增首页视频记录: '.(int) $result['created_hero_video_rows']);
        $this->line('更新 settings 引用: '.(int) $result['updated_setting_rows']);
        $this->line('更新内容封面引用: '.(int) $result['updated_article_rows']);

        return self::SUCCESS;
    }
}
