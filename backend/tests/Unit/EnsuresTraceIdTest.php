<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Concerns\EnsuresTraceId;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

class EnsuresTraceIdTest extends TestCase
{
    public function test_it_generates_a_trace_id_when_creating_model_without_one(): void
    {
        $model = new TraceIdGenerationStub;

        $model->dispatchCreating();

        $this->assertMatchesRegularExpression('/^auto:[0-9a-f-]{36}$/', (string) $model->getAttribute('trace_id'));
    }

    public function test_it_preserves_the_supplied_trace_id(): void
    {
        $model = new TraceIdGenerationStub;
        $model->setAttribute('trace_id', 'request:abc123');

        $model->dispatchCreating();

        $this->assertSame('request:abc123', $model->getAttribute('trace_id'));
    }
}

class TraceIdGenerationStub extends Model
{
    use EnsuresTraceId;

    public function dispatchCreating(): void
    {
        $this->fireModelEvent('creating');
    }
}
