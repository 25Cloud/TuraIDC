<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Requests\Admin\V2\UserService\CreateServiceNatForwardingRequest;
use App\Http\Requests\Client\V2\Service\CreateNatForwardingRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 魔方云的「TCP+UDP」协议值是 `tcp+udp`，曾被请求校验的正则（不含 `+`）拦下，提示协议无效。
 */
class NatForwardingRequestProtocolTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function requests(): array
    {
        return [
            'client' => [CreateNatForwardingRequest::class],
            'admin' => [CreateServiceNatForwardingRequest::class],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('requests')]
    public function test_protocol_values_used_by_upstreams_pass_validation(string $class): void
    {
        foreach (['tcp', 'udp', 'tcp+udp', 'tcp_udp'] as $protocol) {
            $this->assertTrue($this->validate($class, $protocol)->passes(), "协议 {$protocol} 应通过校验");
        }
    }

    /** @param class-string $class */
    #[DataProvider('requests')]
    public function test_protocol_with_unsafe_characters_is_still_rejected(string $class): void
    {
        foreach (['tcp udp', 'tcp;rm', 'tcp&udp', '<tcp>', "tcp\nudp"] as $protocol) {
            $this->assertTrue($this->validate($class, $protocol)->fails(), '不安全字符必须被拒绝');
        }
    }

    /** @param class-string $class */
    private function validate(string $class, string $protocol): \Illuminate\Validation\Validator
    {
        $rules = (new $class)->rules();

        return Validator::make(
            ['name' => 'web', 'ext_port' => 40000, 'int_port' => 80, 'protocol' => $protocol],
            $rules,
        );
    }
}
