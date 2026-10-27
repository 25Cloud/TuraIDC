<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools;

use App\Exceptions\BusinessException;

/**
 * 轻量 JSON Schema 校验：只支持本项目工具会用到的子集。
 *
 * 校验失败一律抛 42200 中文提示，绝不把模型给的多余字段带进业务层。
 */
final class AiSchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function validate(array $schema, array $arguments): array
    {
        $properties = (array) ($schema['properties'] ?? []);
        $required = array_map('strval', (array) ($schema['required'] ?? []));
        $allowExtra = (bool) ($schema['additionalProperties'] ?? false);

        foreach ($required as $key) {
            if (! array_key_exists($key, $arguments) || $arguments[$key] === null) {
                throw new BusinessException('参数缺失：' . $key, 42200, 422);
            }
        }

        if (! $allowExtra) {
            foreach (array_keys($arguments) as $key) {
                if (! array_key_exists($key, $properties)) {
                    throw new BusinessException('工具不接受参数：' . (string) $key, 42200, 422);
                }
            }
        }

        $normalized = [];
        foreach ($properties as $key => $definition) {
            if (! array_key_exists($key, $arguments) || $arguments[$key] === null) {
                continue;
            }
            $normalized[$key] = $this->validateValue((string) $key, (array) $definition, $arguments[$key]);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function validateValue(string $key, array $definition, mixed $value): mixed
    {
        $type = (string) ($definition['type'] ?? 'string');

        switch ($type) {
            case 'integer':
                if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
                    throw new BusinessException('参数类型错误：' . $key . ' 必须是整数', 42200, 422);
                }
                $int = (int) $value;
                if (isset($definition['minimum']) && $int < (int) $definition['minimum']) {
                    throw new BusinessException('参数超出范围：' . $key, 42200, 422);
                }
                if (isset($definition['maximum']) && $int > (int) $definition['maximum']) {
                    throw new BusinessException('参数超出范围：' . $key, 42200, 422);
                }

                return $int;

            case 'number':
                if (! is_numeric($value)) {
                    throw new BusinessException('参数类型错误：' . $key . ' 必须是数字', 42200, 422);
                }

                return (float) $value;

            case 'boolean':
                if (! is_bool($value)) {
                    throw new BusinessException('参数类型错误：' . $key . ' 必须是布尔值', 42200, 422);
                }

                return $value;

            case 'array':
                if (! is_array($value)) {
                    throw new BusinessException('参数类型错误：' . $key . ' 必须是数组', 42200, 422);
                }

                return array_values($value);

            case 'object':
                if (! is_array($value)) {
                    throw new BusinessException('参数类型错误：' . $key . ' 必须是对象', 42200, 422);
                }

                return $value;

            default:
                if (! is_scalar($value)) {
                    throw new BusinessException('参数类型错误：' . $key . ' 必须是字符串', 42200, 422);
                }
                $string = (string) $value;
                if (isset($definition['enum']) && is_array($definition['enum'])
                    && ! in_array($string, array_map('strval', $definition['enum']), true)) {
                    throw new BusinessException('参数取值不支持：' . $key, 42200, 422);
                }
                $maxLength = (int) ($definition['maxLength'] ?? 0);
                if ($maxLength > 0 && mb_strlen($string) > $maxLength) {
                    throw new BusinessException('参数过长：' . $key, 42200, 422);
                }

                return $string;
        }
    }
}
