<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class PostLikeController extends Controller
{
    public function store(Request $request, Post $post)
    {
        $post->likes()->firstOrCreate(['user_id' => $request->user()->id]);

        return back();
    }
}
