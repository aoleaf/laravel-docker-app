<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'    => $this->id,
            'title' => $this->title,

            // TODO BE-(1): content と category を同じ形で追加する
            'content' => $this->content,
            'category' => $this->category,

            // リレーション経由で著者名を出す。Post は belongsTo(User) を持っている。
            // PostRepository::paginateLatest() が Post::with('user') で先読みしているので
            // 一覧でも N+1 にならない。
            'author' => $this->user?->name,

            // created_at は Carbon なので好きな形に整形できる
            'created_at' => $this->created_at->format('Y-m-d'),
        ];
    }
}
