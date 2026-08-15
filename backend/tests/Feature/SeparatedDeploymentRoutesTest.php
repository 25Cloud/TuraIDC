<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class SeparatedDeploymentRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.example.test',
            'app.frontend_url' => 'https://www.example.test',
        ]);
    }

    public function test_api_host_does_not_serve_frontend_spa_entries(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertJsonPath('message', '图拉云 API');

        $this->get('/products')->assertNotFound();
        $this->get('/client/dashboard')->assertNotFound();
        $this->get('/admin/dashboard')->assertNotFound();
    }

    public function test_legacy_register_link_redirects_to_the_website_origin(): void
    {
        $this->get('/client/register?invite_code=invite-123')
            ->assertRedirect('https://www.example.test/client/register?invite_code=invite-123');
    }
}
