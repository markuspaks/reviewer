<?php

namespace Tests\Feature;

use App\Services\BitbucketUserApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BitbucketUserApiDiffTest extends TestCase
{
    public function test_the_diff_is_asked_for_as_is_by_default(): void
    {
        Http::fake(['api.bitbucket.org/*' => Http::response("diff --git a/x b/x\n")]);

        $diff = BitbucketUserApi::withCredentials('bot@example.com', 'token')->getDiff('acme', 'api', 7);

        $this->assertSame("diff --git a/x b/x\n", $diff);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.bitbucket.org/2.0/repositories/acme/api/pullrequests/7/diff');
    }

    public function test_ignoring_whitespace_is_left_to_bitbucket(): void
    {
        Http::fake(['api.bitbucket.org/*' => Http::response('')]);

        BitbucketUserApi::withCredentials('bot@example.com', 'token')->getDiff('acme', 'api', 7, ignoreWhitespace: true);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.bitbucket.org/2.0/repositories/acme/api/pullrequests/7/diff?ignore_whitespace=true');
    }
}
