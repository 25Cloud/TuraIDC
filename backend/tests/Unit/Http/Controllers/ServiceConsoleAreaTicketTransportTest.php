<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\Client\V2\ServiceConsoleAreaController;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * 自定义控制台区域票据传输边界。
 *
 * 票据不能再从 query string 读取：iframe 的 URL、历史记录、访问日志和 Referer
 * 都可能携带 query。新流程使用按服务路径限定的 HttpOnly cookie，Bearer 头保留给
 * fetch/服务端调用方，便于无 cookie 的兼容场景。
 */
class ServiceConsoleAreaTicketTransportTest extends TestCase
{
    #[Test]
    public function bearer_header_takes_precedence_over_service_cookie(): void
    {
        $request = Request::create(
            '/api/v2/client/services/3458/console-area/content?ticket=query-ticket',
            'GET',
            [],
            ['tura_console_area_ticket_3458' => 'cookie-ticket'],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer bearer-ticket']
        );

        $this->assertSame('bearer-ticket', $this->extractTicket($request, 3458));
    }

    #[Test]
    public function query_string_ticket_is_ignored_when_no_header_or_cookie_exists(): void
    {
        $request = Request::create(
            '/api/v2/client/services/3458/console-area/content?ticket=query-ticket',
            'GET'
        );

        $this->assertSame('', $this->extractTicket($request, 3458));
    }

    #[Test]
    public function cookie_name_and_path_are_bound_to_the_service(): void
    {
        $controller = (new ReflectionClass(ServiceConsoleAreaController::class))->newInstanceWithoutConstructor();

        $name = new ReflectionMethod(ServiceConsoleAreaController::class, 'ticketCookieName');
        $name->setAccessible(true);
        $path = new ReflectionMethod(ServiceConsoleAreaController::class, 'ticketCookiePath');
        $path->setAccessible(true);

        $this->assertSame('tura_console_area_ticket_3458', $name->invoke($controller, 3458));
        $this->assertSame('/api/v2/client/services/3458/console-area', $path->invoke($controller, 3458));
    }

    private function extractTicket(Request $request, int $service): string
    {
        $controller = (new ReflectionClass(ServiceConsoleAreaController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ServiceConsoleAreaController::class, 'ticketFromRequest');
        $method->setAccessible(true);

        return (string) $method->invoke($controller, $request, $service);
    }
}
