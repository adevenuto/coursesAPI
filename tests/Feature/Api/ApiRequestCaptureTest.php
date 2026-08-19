<?php

namespace Tests\Feature\Api;

use App\Support\ApiRequestRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

class ApiRequestCaptureTest extends ApiTestCase
{
    /**
     * @return object|null
     */
    private function lastRequest()
    {
        return DB::table('api_requests')->latest('id')->first();
    }

    public function test_it_records_a_row_per_api_request(): void
    {
        Sanctum::actingAs($this->proUser);

        $this->getJson('/api/v1/courses')->assertOk();

        $row = $this->lastRequest();

        $this->assertSame($this->proUser->id, (int) $row->user_id);
        $this->assertSame('GET', $row->method);
        $this->assertSame('api/v1/courses', $row->endpoint);
        $this->assertSame(200, (int) $row->status);
        $this->assertNotNull($row->duration_ms);
        $this->assertGreaterThan(0, (int) $row->response_bytes);
    }

    public function test_it_records_throttled_requests_without_counting_them_as_usage(): void
    {
        // The headline behaviour: the detail log sees the 429 because it sits
        // above the throttler, while the billing rollup never reaches it. If
        // these two ever agree on a throttled call, billing has changed.
        config(['api.plans.pro.per_minute' => 2]);

        Sanctum::actingAs($this->proUser);

        $this->getJson('/api/v1/courses')->assertOk();
        $this->getJson('/api/v1/courses')->assertOk();
        $this->getJson('/api/v1/courses')->assertStatus(429);

        $this->assertSame(3, DB::table('api_requests')->count());
        $this->assertSame(1, DB::table('api_requests')->where('status', 429)->count());

        $this->assertSame(2, (int) DB::table('api_usage')
            ->where('user_id', $this->proUser->id)
            ->value('requests'));
    }

    public function test_it_null_guards_a_session_token(): void
    {
        // Sanctum's guard => ['web'] means acting as a user yields a
        // TransientToken with no key. Nothing may explode on that.
        Sanctum::actingAs($this->proUser);

        $this->getJson('/api/v1/courses')->assertOk();

        $row = $this->lastRequest();

        $this->assertNull($row->token_id);
        $this->assertNull($row->token_name);
    }

    public function test_it_attributes_a_personal_access_token(): void
    {
        $token = $this->proUser->createToken('Production');

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/courses')
            ->assertOk();

        $row = $this->lastRequest();

        $this->assertSame((int) $token->accessToken->id, (int) $row->token_id);
        $this->assertSame('Production', $row->token_name);
    }

    public function test_it_captures_the_search_term_and_result_count(): void
    {
        Sanctum::actingAs($this->proUser);

        $this->getJson('/api/v1/courses?q=Bowling')->assertOk();

        $row = $this->lastRequest();

        // search_term is normalised at write time so top-terms stays a plain
        // GROUP BY; the query blob keeps what was actually sent.
        $this->assertSame('bowling', $row->search_term);
        $this->assertSame('Bowling', json_decode($row->query, true)['q']);
        $this->assertSame(1, (int) $row->result_count);
    }

    public function test_it_records_a_single_resource_as_one_result(): void
    {
        Sanctum::actingAs($this->proUser);

        $this->getJson("/api/v1/courses/{$this->bgCourse->id}")->assertOk();

        $this->assertSame(1, (int) $this->lastRequest()->result_count);
    }

    public function test_it_stores_only_whitelisted_query_params(): void
    {
        Sanctum::actingAs($this->proUser);

        $this->getJson('/api/v1/courses?q=x&per_page=25&evil=DROP+TABLE')->assertOk();

        $query = json_decode($this->lastRequest()->query, true);

        $this->assertSame(['q', 'per_page'], array_keys($query));
        $this->assertArrayNotHasKey('evil', $query);
    }

    public function test_it_rounds_coordinates_before_storing_them(): void
    {
        Sanctum::actingAs($this->proUser);

        $this->getJson('/api/v1/courses?lat=37.0132456&lng=-86.4337891&radius=10')->assertOk();

        $query = json_decode($this->lastRequest()->query, true);

        // ~1km precision: enough to see near-me usage, not enough to locate anyone.
        $this->assertSame(37.01, $query['lat']);
        $this->assertSame(-86.43, $query['lng']);
    }

    public function test_it_anonymizes_the_client_ip_by_default(): void
    {
        Sanctum::actingAs($this->proUser);

        $this->getJson('/api/v1/courses')->assertOk();

        // Laravel's test client reports 127.0.0.1.
        $this->assertSame('127.0.0.0', $this->lastRequest()->ip);
    }

    public function test_it_tracks_the_previously_unmeasured_user_endpoint(): void
    {
        Sanctum::actingAs($this->proUser);

        $this->getJson('/api/user')->assertOk();

        $this->assertSame('api/user', $this->lastRequest()->endpoint);
    }

    public function test_it_records_a_premium_rejection(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson("/api/v1/courses/{$this->bgCourse->id}/green-centers")->assertStatus(403);

        $row = $this->lastRequest();

        $this->assertSame(403, (int) $row->status);
        $this->assertSame('api/v1/courses/{course}/green-centers', $row->endpoint);
    }

    public function test_a_capture_failure_never_breaks_the_api_response(): void
    {
        $this->app->instance(ApiRequestRecorder::class, new class extends ApiRequestRecorder
        {
            public function extract(Request $request, Response $response, float $startedAt): array
            {
                throw new \RuntimeException('analytics exploded');
            }
        });

        Sanctum::actingAs($this->proUser);

        // The caller must never pay for an analytics bug.
        $this->getJson('/api/v1/courses')->assertOk();

        $this->assertSame(0, DB::table('api_requests')->count());
    }
}
