<?php

namespace Tests\Unit;

use App\Repositories\TaskRepositoryInterface;
use App\Models\User;
use App\Models\Task;
use App\Enums\TaskStatus;
use App\Services\TaskService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class TaskServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;   // once() 等を機能させるための後始末を自動化

    private TaskRepositoryInterface $repo;   // 偽物を後から設定するので保持しておく
    private TaskService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
   
        $this->repo = Mockery::mock(TaskRepositoryInterface::class);
        $this->service = new TaskService($this->repo);        // 偽物を注入
    }
    
    public function test_populates_all_status_keys()
    {
        $user = new User();
        
        $this->repo->shouldReceive('countByStatusFor')
            ->once()
            ->with($user)
            ->andReturn(['todo' => 2]);
        
        $result =$this->service->summaryFor($user);

        $this->assertSame(['todo' => 2, 'doing' => 0, 'done' => 0], $result);
    }

    public function test_create_starts_with_todo()
    {
        $user = new User();
        $created = new Task();

        $this->repo->shouldReceive('createFor')
            ->once()
            ->with($user, Mockery::on(function (array $attributes) {
                return $attributes['status'] === 'todo' && $attributes['completed_at'] === null;
            }))
            ->andReturn($created);

        $result = $this->service->createFor($user, [
        'title' => 'テスト',
        'status' => 'done',
        'completed_at' => now(),
        ]);

        $this->assertSame($created, $result);
    }

    public function test_edit_does_not_change_status()
    {
        $task = new Task();
        $updated = new Task();

        $this->repo->shouldReceive('update')
            ->once()
            ->with($task, Mockery::on(function (array $attributes) {
                return ! array_key_exists('status', $attributes)
                && ! array_key_exists('completed_at', $attributes)
                && $attributes['title'] === '新タイトル';
            }))
            ->andReturn($updated);

        $result = $this->service->update($task, [
            'title' => '新タイトル',
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $this->assertSame($updated, $result);
    }

    public function test_complete_twice_no_update()
    {
        $task = new Task();
        $task->status = TaskStatus::Done;

        $this->repo->shouldNotReceive('update');

        $result = $this->service->complete($task);

        $this->assertSame($task, $result);
    }

    public function test_reopen_twice_no_update()
    {
        $task = new Task();
        $task->status = TaskStatus::Todo;

        $this->repo->shouldNotReceive('update');

        $result = $this->service->reopen($task);

        $this->assertSame($task, $result);
    }
    
}