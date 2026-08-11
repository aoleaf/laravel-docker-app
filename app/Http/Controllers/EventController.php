<?php

namespace App\Http\Controllers;

use App\Models\Event;

class EventController extends Controller
{
    // イベント一覧
    public function index()
    {
        $events = Event::withSum('reservations', 'number_of_people')
            ->orderBy('starts_at')
            ->paginate(10);

        return view('events.index', compact('events'));
    }

    // イベント詳細
    public function show(Event $event)
    {
        $event->load('reservations');

        return view('events.show', compact('event'));
    }
}
