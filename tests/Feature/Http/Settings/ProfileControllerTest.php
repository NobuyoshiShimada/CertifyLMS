<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Models\User;
use App\UseCases\Settings\AvatarStorageHelper;
use App\UseCases\Settings\StoreAvatarAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 設定画面(プロフィール / 固定面談 URL / パスワード / アバター)の振る舞いとタブ付きリダイレクトを検証する機能テスト。
 */
class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private function profileUrl(string $tab): string
    {
        return route('settings.profile.edit', ['tab' => $tab]);
    }

    /**
     * @dataProvider allUserProvider
     */
    public function test_every_role_including_graduated_student_can_view_settings(string $state): void
    {
        $user = match ($state) {
            'student' => User::factory()->student()->inProgress()->create(),
            'graduated' => User::factory()->student()->graduated()->create(),
            'coach' => User::factory()->coach()->create(),
            'admin' => User::factory()->admin()->create(),
        };

        $this->actingAs($user)
            ->get(route('settings.profile.edit'))
            ->assertOk()
            ->assertViewIs('settings.profile')
            ->assertSee($user->email)
            ->assertSee($user->role->label())
            ->assertSee($user->status->label());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allUserProvider(): array
    {
        return ['受講生' => ['student'], '修了済受講生' => ['graduated'], 'コーチ' => ['coach'], '管理者' => ['admin']];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.profile.edit'))->assertRedirect(route('login'));
        $this->patch(route('settings.profile.update'), ['name' => 'x'])->assertRedirect(route('login'));
        $this->put(route('settings.password.update'))->assertRedirect(route('login'));
    }

    public function test_password_tab_is_rendered_by_query(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get($this->profileUrl('password'))
            ->assertOk()
            ->assertSee('current_password', false)
            ->assertSee('password_confirmation', false);
    }

    public function test_update_profile_changes_name_and_bio_but_not_email(): void
    {
        $user = User::factory()->student()->create(['email' => 'me@example.com']);

        $response = $this->actingAs($user)->patch(route('settings.profile.update'), [
            'name' => '新しい氏名',
            'bio' => '自己紹介',
            'email' => 'hacked@example.com',
        ]);

        $response->assertRedirect($this->profileUrl('profile'));
        $response->assertSessionHas('success');
        $user->refresh();
        $this->assertSame('新しい氏名', $user->name);
        $this->assertSame('自己紹介', $user->bio);
        $this->assertSame('me@example.com', $user->email);
    }

    public function test_graduated_student_can_update_profile(): void
    {
        $user = User::factory()->student()->graduated()->create();

        $this->actingAs($user)
            ->patch(route('settings.profile.update'), ['name' => '修了生'])
            ->assertRedirect($this->profileUrl('profile'));

        $this->assertSame('修了生', $user->fresh()->name);
    }

    /**
     * @dataProvider invalidProfileProvider
     *
     * @param array<string, mixed> $input
     */
    public function test_update_profile_validation_returns_to_profile_tab_with_old_input(array $input, string $field): void
    {
        $user = User::factory()->coach()->create(['name' => '元の氏名']);

        $response = $this->actingAs($user)
            ->from($this->profileUrl('password'))
            ->patch(route('settings.profile.update'), $input);

        $response->assertRedirect($this->profileUrl('profile'));
        $response->assertSessionHasErrors($field);
        $response->assertSessionHasInput('bio', $input['bio'] ?? null);
        $this->assertSame('元の氏名', $user->fresh()->name);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidProfileProvider(): array
    {
        return [
            '氏名未入力' => [['name' => '', 'bio' => '残る入力'], 'name'],
            '氏名 51 文字' => [['name' => str_repeat('あ', 51), 'bio' => '残る入力'], 'name'],
            '自己紹介 1001 文字' => [['name' => '氏名', 'bio' => str_repeat('あ', 1001)], 'bio'],
            '固定面談 URL が URL でない' => [['name' => '氏名', 'bio' => '残る入力', 'meeting_url' => 'not-url'], 'meeting_url'],
            '固定面談 URL 501 文字' => [['name' => '氏名', 'bio' => '残る入力', 'meeting_url' => 'https://example.com/'.str_repeat('a', 481)], 'meeting_url'],
        ];
    }

    public function test_update_profile_accepts_boundary_lengths(): void
    {
        $user = User::factory()->student()->create();

        $this->actingAs($user)->patch(route('settings.profile.update'), [
            'name' => str_repeat('あ', 50),
            'bio' => str_repeat('あ', 1000),
        ])->assertSessionHasNoErrors();

        $this->assertSame(str_repeat('あ', 50), $user->fresh()->name);
    }

    public function test_coach_can_update_and_clear_meeting_url(): void
    {
        $coach = User::factory()->coach()->create(['meeting_url' => 'https://meet.google.com/old']);

        $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => $coach->name,
            'meeting_url' => 'https://zoom.us/j/123',
        ])->assertSessionHasNoErrors();
        $this->assertSame('https://zoom.us/j/123', $coach->fresh()->meeting_url);

        $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => $coach->name,
            'meeting_url' => '',
        ])->assertSessionHasNoErrors();
        $this->assertNull($coach->fresh()->meeting_url);
    }

    public function test_meeting_url_field_is_shown_only_to_coach(): void
    {
        $this->actingAs(User::factory()->coach()->create())
            ->get($this->profileUrl('profile'))
            ->assertSee('name="meeting_url"', false);

        foreach ([User::factory()->student()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)
                ->get($this->profileUrl('profile'))
                ->assertDontSee('name="meeting_url"', false);
        }
    }

    /**
     * @dataProvider nonCoachProvider
     */
    public function test_forged_meeting_url_from_non_coach_is_ignored_without_error(string $role): void
    {
        $user = User::factory()->{$role}()->create(['meeting_url' => null]);

        $this->actingAs($user)->patch(route('settings.profile.update'), [
            'name' => '更新後',
            'meeting_url' => 'not-even-a-url',
        ])->assertRedirect($this->profileUrl('profile'))->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('更新後', $user->name);
        $this->assertNull($user->meeting_url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonCoachProvider(): array
    {
        return ['受講生' => ['student'], '管理者' => ['admin']];
    }

    public function test_password_can_be_changed_and_redirects_to_password_tab(): void
    {
        $user = User::factory()->student()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($user)->put(route('settings.password.update'), [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertRedirect($this->profileUrl('password'));
        $response->assertSessionHas('success');
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    /**
     * @dataProvider invalidPasswordProvider
     *
     * @param array<string, string> $input
     */
    public function test_password_is_not_changed_when_invalid(array $input, string $field): void
    {
        $user = User::factory()->student()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($user)
            ->from($this->profileUrl('profile'))
            ->put(route('settings.password.update'), $input);

        $response->assertRedirect($this->profileUrl('password'));
        $response->assertSessionHasErrorsIn('updatePassword', $field);
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidPasswordProvider(): array
    {
        return [
            '現在のパスワード不一致' => [['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'], 'current_password'],
            '新パスワード 7 文字' => [['current_password' => 'old-password', 'password' => 'short77', 'password_confirmation' => 'short77'], 'password'],
            '確認不一致' => [['current_password' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'different'], 'password'],
        ];
    }

    public function test_avatar_upload_stores_file_and_deletes_old_one(): void
    {
        Storage::fake(AvatarStorageHelper::DISK);
        $user = User::factory()->student()->create();
        Storage::disk(AvatarStorageHelper::DISK)->put('avatars/old.png', 'old');
        $user->update(['avatar_url' => Storage::disk(AvatarStorageHelper::DISK)->url('avatars/old.png')]);

        $response = $this->actingAs($user)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('me.png')->size(500),
        ]);

        $response->assertRedirect($this->profileUrl('profile'));
        $response->assertSessionHas('success');
        $newUrl = $user->fresh()->avatar_url;
        $this->assertNotNull($newUrl);
        $this->assertNotSame(Storage::disk(AvatarStorageHelper::DISK)->url('avatars/old.png'), $newUrl);
        Storage::disk(AvatarStorageHelper::DISK)->assertMissing('avatars/old.png');
        $this->assertCount(1, Storage::disk(AvatarStorageHelper::DISK)->files('avatars'));
    }

    /**
     * @dataProvider allowedImageProvider
     */
    public function test_avatar_accepts_png_jpeg_webp(string $filename): void
    {
        Storage::fake(AvatarStorageHelper::DISK);
        $user = User::factory()->student()->create();

        $this->actingAs($user)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image($filename),
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->avatar_url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedImageProvider(): array
    {
        return ['PNG' => ['a.png'], 'JPEG' => ['a.jpg'], 'WebP' => ['a.webp']];
    }

    /**
     * @dataProvider invalidAvatarProvider
     */
    public function test_invalid_avatar_is_rejected_and_existing_avatar_is_kept(string $kind): void
    {
        Storage::fake(AvatarStorageHelper::DISK);
        $user = User::factory()->student()->create(['avatar_url' => 'https://example.com/keep.png']);

        $file = match ($kind) {
            'too_large' => UploadedFile::fake()->image('big.png')->size(2049),
            'gif' => UploadedFile::fake()->image('anim.gif'),
            'pdf' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        };

        $this->actingAs($user)
            ->from($this->profileUrl('password'))
            ->post(route('settings.avatar.store'), ['avatar' => $file])
            ->assertRedirect($this->profileUrl('profile'))
            ->assertSessionHasErrors('avatar');

        $this->assertSame('https://example.com/keep.png', $user->fresh()->avatar_url);
        $this->assertCount(0, Storage::disk(AvatarStorageHelper::DISK)->allFiles());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAvatarProvider(): array
    {
        return ['2MB 超' => ['too_large'], 'GIF' => ['gif'], '画像以外' => ['pdf']];
    }

    public function test_avatar_accepts_exactly_2mb(): void
    {
        Storage::fake(AvatarStorageHelper::DISK);
        $user = User::factory()->student()->create();

        $this->actingAs($user)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('max.png')->size(2048),
        ])->assertSessionHasNoErrors();
    }

    public function test_avatar_is_unchanged_when_new_file_cannot_be_saved(): void
    {
        Storage::fake(AvatarStorageHelper::DISK);
        $user = User::factory()->student()->create(['avatar_url' => 'https://example.com/keep.png']);

        $file = Mockery::mock(UploadedFile::fake()->image('me.png'))->makePartial();
        $file->shouldReceive('store')->andReturn(false);

        try {
            app(StoreAvatarAction::class)($user, $file);
            $this->fail('例外が発生するべき');
        } catch (RuntimeException) {
            // 期待どおり
        }

        $this->assertSame('https://example.com/keep.png', $user->fresh()->avatar_url);
    }

    public function test_avatar_can_be_deleted_and_falls_back_to_initial(): void
    {
        Storage::fake(AvatarStorageHelper::DISK);
        $user = User::factory()->student()->create(['name' => '山田太郎']);
        Storage::disk(AvatarStorageHelper::DISK)->put('avatars/current.png', 'img');
        $user->update(['avatar_url' => Storage::disk(AvatarStorageHelper::DISK)->url('avatars/current.png')]);

        $this->actingAs($user)
            ->delete(route('settings.avatar.destroy'))
            ->assertRedirect($this->profileUrl('profile'))
            ->assertSessionHas('success');

        $this->assertNull($user->fresh()->avatar_url);
        Storage::disk(AvatarStorageHelper::DISK)->assertMissing('avatars/current.png');

        $this->actingAs($user->fresh())
            ->get($this->profileUrl('profile'))
            ->assertSee('aria-label="山田太郎 のアバター"', false);
    }

    public function test_external_avatar_url_is_not_touched_on_storage(): void
    {
        Storage::fake(AvatarStorageHelper::DISK);
        Storage::disk(AvatarStorageHelper::DISK)->put('other/keep.png', 'img');
        $user = User::factory()->student()->create(['avatar_url' => 'https://example.com/other/keep.png']);

        $this->actingAs($user)->delete(route('settings.avatar.destroy'));

        $this->assertNull($user->fresh()->avatar_url);
        Storage::disk(AvatarStorageHelper::DISK)->assertExists('other/keep.png');
    }

    public function test_updates_apply_only_to_authenticated_user(): void
    {
        $me = User::factory()->student()->create();
        $other = User::factory()->student()->create(['name' => '他人']);

        $this->actingAs($me)->patch(route('settings.profile.update'), [
            'name' => '私',
            'id' => $other->id,
            'user_id' => $other->id,
        ]);

        $this->assertSame('私', $me->fresh()->name);
        $this->assertSame('他人', $other->fresh()->name);
    }
}
