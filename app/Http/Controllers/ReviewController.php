<?php

namespace App\Http\Controllers;

use App\Services\Bitbucket;
use Http\Client\Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /**
     * @throws Exception
     */
    public function index(Request $request, Bitbucket $bitbucket): View
    {
        $reviewer = $request->get('reviewer');

        $pullRequests = $bitbucket->getAllPullRequests();

        if ($reviewer) {
            $pullRequests = array_filter($pullRequests, function ($pullRequest) use ($reviewer) {

                foreach ($pullRequest->getAssignees() as $assignee) {
                    if ($assignee['uuid'] === $reviewer) {
                        return true;
                    }
                }

                return false;
            });
        }

        return view('reviews.index', [
            'users' => $bitbucket->getUsersWithAssignedPullRequests($bitbucket->getAllUsers()),
            'pullRequests' => $pullRequests,
        ]);
    }
}
