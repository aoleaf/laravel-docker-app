<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationRequest;
use App\Models\Event;
use App\Models\Reservation;

class ReservationController extends Controller
{
    // 予約一覧
    public function index()
    {
        $reservations = Reservation::with('event')
            ->latest()
            ->paginate(10);

        return view('reservations.index', compact('reservations'));
    }

    // 予約フォーム
    public function create(Event $event)
    {
        return view('reservations.create', compact('event'));
    }

    // 予約登録
    public function store(ReservationRequest $request, Event $event)
    {
        $reservation = $event->reservations()->create($request->validated());

        return redirect()
            ->route('events.show', $event)
            ->with('success', "予約を受け付けました（受付番号: {$reservation->id}）");
    }

    // 予約キャンセル
    public function destroy(Reservation $reservation)
    {
        $reservation->delete();

        return redirect()
            ->route('reservations.index')
            ->with('success', '予約をキャンセルしました');
    }
}
