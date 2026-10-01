<?php

namespace Tests\Feature;

use App\Jobs\ExecuteRunJob;
use App\Models\AutomationTrigger;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/**
 * A push can start a test by itself. The rule owns a webhook URL, so a leaked URL
 * reaches one rule; and because runs are rows behind a single worker, two pushes
 * arriving together line up instead of fighting over the device.
 */
class GitlabAutomationTest extends TestCase
{
    use RefreshDatabase;
    use SignsIn;

    private function rule(string $token, string $branchPattern = 'main'): AutomationTrigger
    {
        $this->unlockSettings($token);

        $response = $this->asSession($token)->postJson('/automation/triggers', [
            'projectId' => 54,
            'projectPath' => 'fusion-food-tech/mobile-apps/pos',
            'branchPattern' => $branchPattern,
            'tests' => ['reports'],
            'platform' => 'android',
            'orientation' => 'vertical',
        ])->assertCreated();

        $this->assertNotEmpty($response->json('trigger.webhookUrl'));

        return AutomationTrigger::query()->firstOrFail();
    }

    private function push(string $token, array $payload, ?string $event = 'Push Hook')
    {
        return $this->withHeaders($event === null ? [] : ['X-Gitlab-Event' => $event])
            ->postJson('/hooks/gitlab/'.$token, $payload);
    }

    #[Test]
    public function the_rules_are_behind_the_settings_password(): void
    {
        $token = $this->loginToken();

        $this->asSession($token)->getJson('/automation/triggers')->assertStatus(403);

        $this->unlockSettings($token);
        $this->asSession($token)->getJson('/automation/triggers')->assertSuccessful();
    }

    #[Test]
    public function a_push_to_the_watched_branch_queues_a_run(): void
    {
        Queue::fake();

        $token = $this->loginToken();
        $rule = $this->rule($token);
        $secret = (new \App\Repositories\Automation\AutomationTriggerRepository)->token($rule);

        $this->push($secret, [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'after' => str_repeat('a', 40),
        ])->assertStatus(202)->assertJsonPath('status', 'queued');

        $run = Run::query()->firstOrFail();

        $this->assertSame('main', $run->getBranch());
        $this->assertSame('fusion-food-tech/mobile-apps/pos', $run->getProjectPath());
        $this->assertSame(['reports'], $run->getTests());
        Queue::assertPushed(ExecuteRunJob::class);

        // The push is remembered, so a retry does not queue a second run.
        $this->assertSame(str_repeat('a', 40), $rule->refresh()->getLastFiredSha());
        $this->assertSame(1, $rule->getFireCount());
    }

    #[Test]
    public function another_branch_is_left_alone(): void
    {
        Queue::fake();

        $token = $this->loginToken();
        $rule = $this->rule($token, 'release/*');
        $secret = (new \App\Repositories\Automation\AutomationTriggerRepository)->token($rule);

        $this->push($secret, ['ref' => 'refs/heads/main', 'after' => str_repeat('b', 40)])
            ->assertStatus(202)
            ->assertJsonPath('status', 'no_match');

        $this->assertSame(0, Run::query()->count());

        $this->push($secret, ['ref' => 'refs/heads/release/1.4', 'after' => str_repeat('c', 40)])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        $this->assertSame(1, Run::query()->count());
    }

    #[Test]
    public function the_same_push_delivered_twice_queues_one_run(): void
    {
        Queue::fake();

        $token = $this->loginToken();
        $rule = $this->rule($token);
        $secret = (new \App\Repositories\Automation\AutomationTriggerRepository)->token($rule);

        $push = ['ref' => 'refs/heads/main', 'after' => str_repeat('d', 40)];

        $this->push($secret, $push)->assertJsonPath('status', 'queued');
        $this->push($secret, $push)->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, Run::query()->count());
    }

    #[Test]
    public function a_paused_rule_is_skipped_and_an_unknown_url_is_refused(): void
    {
        Queue::fake();

        $token = $this->loginToken();
        $rule = $this->rule($token);
        $repository = new \App\Repositories\Automation\AutomationTriggerRepository;
        $secret = $repository->token($rule);

        $this->asSession($token)
            ->patchJson('/automation/triggers/'.$rule->getId(), ['enabled' => false])
            ->assertSuccessful();

        $this->push($secret, ['ref' => 'refs/heads/main', 'after' => str_repeat('e', 40)])
            ->assertStatus(202)
            ->assertJsonPath('status', 'disabled');

        $this->assertSame(0, Run::query()->count());

        $this->postJson('/hooks/gitlab/not-a-real-token', ['ref' => 'refs/heads/main'])
            ->assertStatus(404);
    }

    #[Test]
    public function branch_removals_tags_and_other_events_are_ignored(): void
    {
        Queue::fake();

        $token = $this->loginToken();
        $rule = $this->rule($token);
        $secret = (new \App\Repositories\Automation\AutomationTriggerRepository)->token($rule);

        $this->push($secret, ['ref' => 'refs/heads/main', 'after' => str_repeat('0', 40)])
            ->assertJsonPath('ignored', 'branch_removed');

        $this->push($secret, ['ref' => 'refs/tags/v1.0.0', 'after' => str_repeat('f', 40)])
            ->assertJsonPath('ignored', 'not_a_branch');

        $this->push($secret, ['object_kind' => 'merge_request'], 'Merge Request Hook')
            ->assertJsonPath('ignored', 'not_a_push');

        $this->assertSame(0, Run::query()->count());
    }

    #[Test]
    public function two_pushes_arrive_as_two_queued_runs(): void
    {
        Queue::fake();

        $token = $this->loginToken();
        $rule = $this->rule($token);
        $secret = (new \App\Repositories\Automation\AutomationTriggerRepository)->token($rule);

        $this->push($secret, ['ref' => 'refs/heads/main', 'after' => str_repeat('1', 40)]);
        $this->push($secret, ['ref' => 'refs/heads/main', 'after' => str_repeat('2', 40)]);

        $this->assertSame(2, Run::query()->count());
        Queue::assertPushed(ExecuteRunJob::class, 2);
    }

    #[Test]
    public function deleting_a_rule_stops_it(): void
    {
        Queue::fake();

        $token = $this->loginToken();
        $rule = $this->rule($token);
        $secret = (new \App\Repositories\Automation\AutomationTriggerRepository)->token($rule);

        $this->asSession($token)->deleteJson('/automation/triggers/'.$rule->getId())->assertSuccessful();

        $this->postJson('/hooks/gitlab/'.$secret, ['ref' => 'refs/heads/main'])->assertStatus(404);
    }
}
