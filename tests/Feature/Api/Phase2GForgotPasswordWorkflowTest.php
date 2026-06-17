<?php

namespace Tests\Feature\Api;

use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class Phase2GForgotPasswordWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_returns_generic_success_for_existing_email(): void
    {
        Mail::fake();

        User::factory()->create([
            'role' => 'student',
            'email' => 'student@example.com',
        ]);

        $this->postJson('/api/forgot-password', [
            'email' => 'student@example.com',
        ])->assertOk()
            ->assertJsonPath('message', 'If this email exists, a reset code has been sent.');

        Mail::assertSent(PasswordResetOtpMail::class);
        $this->assertSame(1, PasswordResetOtp::count());
    }

    public function test_forgot_password_returns_generic_success_for_missing_email(): void
    {
        Mail::fake();

        $this->postJson('/api/forgot-password', [
            'email' => 'missing@example.com',
        ])->assertOk()
            ->assertJsonPath('message', 'If this email exists, a reset code has been sent.');

        Mail::assertNothingSent();
        $this->assertSame(0, PasswordResetOtp::count());
    }

    public function test_forgot_password_does_not_directly_change_password(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => 'student',
            'email' => 'student@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $this->postJson('/api/forgot-password', [
            'email' => 'student@example.com',
            'new_password' => 'new-password',
            'new_password_confirmation' => 'new-password',
        ])->assertOk()
            ->assertJsonPath('message', 'If this email exists, a reset code has been sent.');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
        $this->assertFalse(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_otp_is_stored_hashed_not_plain_text(): void
    {
        Mail::fake();

        User::factory()->create([
            'role' => 'student',
            'email' => 'student@example.com',
        ]);

        $sentOtp = null;
        $this->postJson('/api/forgot-password', [
            'email' => 'student@example.com',
        ])->assertOk();

        Mail::assertSent(PasswordResetOtpMail::class, function (PasswordResetOtpMail $mail) use (&$sentOtp) {
            $sentOtp = $mail->otp;
            return true;
        });

        $resetOtp = PasswordResetOtp::firstOrFail();

        $this->assertNotSame($sentOtp, $resetOtp->otp_hash);
        $this->assertTrue(Hash::check($sentOtp, $resetOtp->otp_hash));
        $this->assertNull($resetOtp->used_at);
    }

    public function test_reset_password_succeeds_with_valid_otp(): void
    {
        $user = $this->createUserAndRequestOtp($otp);

        $this->postJson('/api/reset-password', [
            'email' => 'student@example.com',
            'otp' => $otp,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()
            ->assertJsonPath('message', 'Password reset successfully. You can now sign in.');

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertNotNull(PasswordResetOtp::firstOrFail()->used_at);
    }

    public function test_reset_password_fails_with_wrong_otp(): void
    {
        $user = $this->createUserAndRequestOtp($otp);

        $this->postJson('/api/reset-password', [
            'email' => 'student@example.com',
            'otp' => '000000',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
        $this->assertNull(PasswordResetOtp::firstOrFail()->used_at);
    }

    public function test_reset_password_fails_with_expired_otp(): void
    {
        $user = $this->createUserAndRequestOtp($otp);
        PasswordResetOtp::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/reset-password', [
            'email' => 'student@example.com',
            'otp' => $otp,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_reset_password_fails_if_otp_already_used(): void
    {
        $user = $this->createUserAndRequestOtp($otp);
        PasswordResetOtp::query()->update(['used_at' => now()]);

        $this->postJson('/api/reset-password', [
            'email' => 'student@example.com',
            'otp' => $otp,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_user_can_login_with_new_password_and_old_password_fails_after_reset(): void
    {
        $this->createUserAndRequestOtp($otp);

        $this->postJson('/api/reset-password', [
            'email' => 'student@example.com',
            'otp' => $otp,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => 'student@example.com',
            'password' => 'new-password',
        ])->assertOk()
            ->assertJsonPath('message', 'Login successful');

        $this->postJson('/api/login', [
            'email' => 'student@example.com',
            'password' => 'old-password',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    private function createUserAndRequestOtp(?string &$otp): User
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => 'student',
            'email' => 'student@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $this->postJson('/api/forgot-password', [
            'email' => 'student@example.com',
        ])->assertOk();

        Mail::assertSent(PasswordResetOtpMail::class, function (PasswordResetOtpMail $mail) use (&$otp) {
            $otp = $mail->otp;
            return true;
        });

        return $user;
    }
}
