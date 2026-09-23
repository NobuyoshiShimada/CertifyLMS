<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Throwable;

/**
 * コーチ本人の Google カレンダー連携(開始 / コールバック / 解除)。ルートは role:coach で保護(他ロールは 403)。
 *
 * 認可リクエストの state に「ログイン中のコーチ ID」と「連携完了後の戻り先パス」を載せ、
 * コールバックでログイン中コーチと照合する(不一致は 400)。リフレッシュトークンが返らない連携も 400 で拒否する。
 */
class GoogleCalendarController extends Controller
{
    private const DEFAULT_REDIRECT_PATH = '/settings/availability';

    public function __construct(private readonly GoogleCalendarGateway $gateway) {}

    public function redirect(Request $request): RedirectResponse
    {
        /** @var User $coach */
        $coach = $request->user();

        $state = $this->encodeState([
            'coach_id' => $coach->id,
            'redirect_path' => $this->safeRedirectPath($request->query('redirect_path')),
        ]);

        return redirect()->away($this->gateway->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        /** @var User $coach */
        $coach = $request->user();

        $state = $this->decodeState($request->query('state'));
        if ($state === null || ($state['coach_id'] ?? null) !== $coach->id) {
            throw new BadRequestHttpException('Google カレンダー連携の検証に失敗しました。もう一度連携してください。');
        }

        $redirectPath = $this->safeRedirectPath($state['redirect_path'] ?? null);

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect($redirectPath)->with('error', 'Google カレンダー連携がキャンセルされました。');
        }

        try {
            $token = $this->gateway->exchangeAuthorizationCode((string) $request->query('code'));
        } catch (Throwable $e) {
            Log::warning('Google カレンダー連携のトークン取得に失敗しました', ['coach_id' => $coach->id, 'message' => $e->getMessage()]);

            return redirect($redirectPath)->with('error', 'Google カレンダーとの連携に失敗しました。時間をおいて再度お試しください。');
        }

        if ($token->refreshToken === null || $token->refreshToken === '') {
            throw new BadRequestHttpException('Google から継続利用のための認可が得られませんでした。もう一度連携してください。');
        }

        GoogleCredential::query()->updateOrCreate(
            ['user_id' => $coach->id],
            [
                'access_token' => $token->accessToken,
                'refresh_token' => $token->refreshToken,
                'token_expires_at' => $token->expiresAt,
                'calendar_id' => GoogleCredential::PRIMARY_CALENDAR,
                'connected_at' => now(),
            ],
        );

        return redirect($redirectPath)->with('success', 'Google カレンダーと連携しました。');
    }

    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $coach */
        $coach = $request->user();

        // 既存の Google 側 Event と面談の google_event_id は残す(再連携後のキャンセルで削除できるようにする)
        $coach->googleCredential()->delete();

        return redirect(self::DEFAULT_REDIRECT_PATH)->with('success', 'Google カレンダーとの連携を解除しました。');
    }

    /**
     * 戻り先はサイト内の相対パスのみ許可する(オープンリダイレクト防止)。
     */
    private function safeRedirectPath(mixed $path): string
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return self::DEFAULT_REDIRECT_PATH;
        }

        return $path;
    }

    /**
     * @param array<string, string> $payload
     */
    private function encodeState(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeState(mixed $state): ?array
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        $json = base64_decode(strtr($state, '-_', '+/'), true);
        $decoded = $json === false ? null : json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
