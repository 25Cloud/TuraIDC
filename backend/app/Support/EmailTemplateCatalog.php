<?php

declare(strict_types=1);

namespace App\Support;

final class EmailTemplateCatalog
{
    public static function subjectSettingKey(string $code): string
    {
        return 'email_template_subject_'.trim($code);
    }

    public static function contentSettingKey(string $code): string
    {
        return 'email_template_content_'.trim($code);
    }

    public static function enabledSettingKey(string $code): string
    {
        return 'email_template_enabled_'.trim($code);
    }
}
