<?php

namespace Tests\Feature;

use App\Services\BitbucketUserApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BitbucketUserApiCommentsTest extends TestCase
{
    /**
     * Bitbucket's "next" link carries the page number and the other query parameters: it has to be followed as given.
     * Fetching it without its query string returns the first page again, with another "next", for ever.
     */
    public function test_every_page_of_comments_is_read_once(): void
    {
        $base = 'https://api.bitbucket.org/2.0/repositories/acme/api/pullrequests/7/comments';
        $secondPage = "$base?pagelen=100&sort=created_on&fields=%2Bvalues.resolution.%2A&page=2";
        Http::fake(function (Request $request) use ($base, $secondPage) {
            if ($request->url() === $secondPage) {
                return Http::response(['values' => [['id' => 2]]]);
            }
            if (str_starts_with($request->url(), "$base?")) {
                return Http::response(['values' => [['id' => 1]], 'next' => $secondPage]);
            }
            $this->fail('Unexpected request: ' . $request->url());
        });

        $comments = BitbucketUserApi::withCredentials('bot@example.com', 'token')->getComments('acme', 'api', 7);

        $this->assertSame([['id' => 1], ['id' => 2]], $comments);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === $secondPage);
    }

    public function test_a_next_link_that_repeats_a_page_fails_instead_of_looping(): void
    {
        $base = 'https://api.bitbucket.org/2.0/repositories/acme/api/pullrequests/7/comments';
        Http::fake(fn (Request $request) => Http::response(['values' => [['id' => 1]], 'next' => $request->url()]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('same page of comments');

        BitbucketUserApi::withCredentials('bot@example.com', 'token')->getComments('acme', 'api', 7);
    }
}
