<?php

namespace App\Http\Controllers;

use App\Services\Bitbucket;
use Http\Client\Exception;
use Illuminate\Contracts\View\View;

class ReviewController extends Controller
{
    /**
     * @throws Exception
     */
    public function index(Bitbucket $bitbucket): View
    {
        return view('reviews.index', [
            'users' => $bitbucket->getUsersWithAssignedPullRequests($bitbucket->getAllUsers()),
            'pullRequests' => $bitbucket->getAllPullRequests()
        ]);
    }
}
