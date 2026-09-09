<?php

namespace Tests\Feature;

use App\Mail\WelcomeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WelcomeMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_会員登録するとウェルカムメールが送られる(): void
    {
        Mail::fake();

        $this->post('/register', [
            'name'                  => 'テスト太郎',
            'email'                 => 'taro@example.com',
            'password'              => 'password',
            'password_confirmation' => 'password',
        ]);

        Mail::assertSent(WelcomeMail::class, fn ($mail) => $mail->hasTo('taro@example.com'));
    }
}
