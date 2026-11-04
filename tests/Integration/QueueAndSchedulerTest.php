<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Platform\Queue\JobContext;
use App\Platform\Queue\JobHandlerInterface;
use App\Platform\Queue\JobQueue;
use App\Platform\Queue\Worker;
use App\Platform\Scheduler\Scheduler;
use DateTimeImmutable;
use Yiisoft\Db\Query\Query;

final class QueueAndSchedulerTest extends IntegrationTestCase
{
    private JobQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->get(JobQueue::class);
        $this->db->createCommand("DELETE FROM jobs WHERE queue LIKE 'test-%'")->execute();
        $this->db->createCommand("DELETE FROM queue_state WHERE queue LIKE 'test-%'")->execute();
    }

    public function testPushReserveCompleteAndIdempotency(): void
    {
        $id = $this->queue->push('platform.queue.reap', ['x' => 1], ['queue' => 'test-a', 'idempotency_key' => 'k-' . uniqid()]);
        $key = (new Query($this->db))->select('idempotency_key')->from('jobs')->where(['id' => $id])->scalar();
        self::assertSame($id, $this->queue->push('platform.queue.reap', ['x' => 2], ['queue' => 'test-a', 'idempotency_key' => $key]), 'same key → same job');

        $job = $this->queue->reserve(['test-a'], 'w1');
        self::assertSame($id, $job['id']);
        self::assertSame('running', $job['status']);
        self::assertNull($this->queue->reserve(['test-a'], 'w2'), 'a running job is not handed out twice');

        $this->queue->complete($id, 5, 'w1');
        self::assertSame('succeeded', $this->queue->find($id)['status']);
    }

    public function testFailureBackoffThenDead(): void
    {
        $id = $this->queue->push('platform.queue.reap', [], ['queue' => 'test-b', 'max_attempts' => 2, 'backoff' => 1]);
        $job = $this->queue->reserve(['test-b'], 'w1');
        self::assertSame('pending', $this->queue->fail($job, new \RuntimeException('boom'), 1, 'w1'));
        $row = $this->queue->find($id);
        self::assertGreaterThan(new DateTimeImmutable(), new DateTimeImmutable($row['available_at']), 'retry is delayed');

        $this->db->createCommand()->update('jobs', ['available_at' => (new DateTimeImmutable('-1 second'))->format(DATE_ATOM)], ['id' => $id])->execute();
        $job = $this->queue->reserve(['test-b'], 'w1');
        self::assertSame(2, (int) $job['attempts']);
        self::assertSame('dead', $this->queue->fail($job, new \RuntimeException('boom again'), 1, 'w1'));

        $this->queue->retry($id);
        self::assertSame('pending', $this->queue->find($id)['status']);
        self::assertSame(2, (int) (new Query($this->db))->from('job_attempts')->where(['job_id' => $id])->count());
    }

    public function testPausedQueueIsNotConsumedAndCancelWorks(): void
    {
        $id = $this->queue->push('platform.queue.reap', [], ['queue' => 'test-c']);
        $this->queue->pauseQueue('test-c', true);
        self::assertNull($this->queue->reserve(['test-c'], 'w1'));
        $this->queue->pauseQueue('test-c', false);
        $this->queue->cancel($id);
        self::assertSame('cancelled', $this->queue->find($id)['status']);
        self::assertNull($this->queue->reserve(['test-c'], 'w1'));
    }

    public function testReaperRecoversExpiredReservation(): void
    {
        $id = $this->queue->push('platform.queue.reap', [], ['queue' => 'test-d']);
        $this->queue->reserve(['test-d'], 'crashed-worker');
        $this->db->createCommand()->update('jobs', ['reserved_until' => (new DateTimeImmutable('-1 minute'))->format(DATE_ATOM)], ['id' => $id])->execute();
        self::assertGreaterThanOrEqual(1, $this->queue->reap()['requeued']);
        self::assertSame('pending', $this->queue->find($id)['status']);
    }

    public function testWorkerExecutesRealHandler(): void
    {
        $id = $this->queue->push('platform.maintenance.cleanup', [], ['queue' => 'test-e']);
        /** @var Worker $worker */
        $worker = $this->get(Worker::class);
        self::assertSame(1, $worker->run(['test-e'], 0.1, 10, 512, true));
        self::assertSame('succeeded', $this->queue->find($id)['status']);
        self::assertSame('stopped', (new Query($this->db))->select('status')->from('workers')->where(['id' => $worker->id()])->scalar());
    }

    public function testSchedulerEnqueuesExactlyOncePerTick(): void
    {
        $code = 'test.sched.' . uniqid();
        $this->db->createCommand()->insert('schedules', [
            'code' => $code, 'name' => 'test', 'cron_expression' => '* * * * *', 'job_type' => 'platform.queue.reap',
            'queue' => 'test-f', 'enabled' => true, 'next_run_at' => (new DateTimeImmutable('-10 seconds'))->format(DATE_ATOM),
        ])->execute();
        $sid = (new Query($this->db))->select('id')->from('schedules')->where(['code' => $code])->scalar();
        $nextRun = (new Query($this->db))->select('next_run_at')->from('schedules')->where(['id' => $sid])->scalar();

        /** @var Scheduler $s */
        $s = $this->get(Scheduler::class);
        $s->tick();
        // Simulate a second scheduler that read the same due row before the first one advanced it
        $this->db->createCommand()->update('schedules', ['next_run_at' => $nextRun], ['id' => $sid])->execute();
        $s->tick();

        self::assertSame(1, (int) (new Query($this->db))->from('schedule_runs')->where(['schedule_id' => $sid])->count());
        self::assertSame(1, (int) (new Query($this->db))->from('jobs')->where(['queue' => 'test-f'])->count());
        self::assertGreaterThan(new DateTimeImmutable(), new DateTimeImmutable((string) (new Query($this->db))->select('next_run_at')->from('schedules')->where(['id' => $sid])->scalar()));
        $this->db->createCommand()->delete('schedules', ['id' => $sid])->execute();
    }

    public function testCronPreview(): void
    {
        $runs = $this->get(Scheduler::class)->preview('0 9 * * 1-5', 'Asia/Tashkent', 3);
        self::assertCount(3, $runs);
        self::assertStringContainsString('09:00', $runs[0]);
    }
}
