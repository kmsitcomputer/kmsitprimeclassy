<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Self-service profile: name/phone/email, avatar, password — every authenticated role, never another account. */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    public function test_any_role_can_update_their_own_name_phone_and_email(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $response = $this->actingAs($agen)->patchJson('/api/v1/profile', [
            'name' => 'Nama Baru', 'phone' => '081299998888', 'email' => 'baru@example.com',
        ]);

        $response->assertOk();
        $this->assertSame('Nama Baru', $response->json('data.name'));
        $this->assertSame('baru@example.com', $response->json('data.email'));
        $this->assertDatabaseHas('users', ['id' => $agen->id, 'email' => 'baru@example.com']);
    }

    public function test_email_must_stay_unique_across_accounts(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $other = User::factory()->agen()->create(['email' => 'taken@example.com']);

        $this->actingAs($agen)->patchJson('/api/v1/profile', ['email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_avatar_can_be_uploaded_and_attached_to_own_profile(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);

        $upload = $this->actingAs($agen)->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('me.jpg', 300, 300),
            'collection' => 'user_avatar',
        ]);
        $upload->assertCreated();
        $mediaId = $upload->json('data.id');

        $response = $this->actingAs($agen)->patchJson('/api/v1/profile', ['avatar_media_id' => $mediaId]);
        $response->assertOk();
        $this->assertNotNull($response->json('data.avatar_url'));
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $agen = User::factory()->agen()->create(['password' => 'oldpassword123']);
        $agen->update(['agent_id' => $agen->id]);

        $this->actingAs($agen)->patchJson('/api/v1/profile/password', [
            'current_password' => 'wrongpassword', 'password' => 'newpassword123', 'password_confirmation' => 'newpassword123',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $ok = $this->actingAs($agen)->patchJson('/api/v1/profile/password', [
            'current_password' => 'oldpassword123', 'password' => 'newpassword123', 'password_confirmation' => 'newpassword123',
        ]);
        $ok->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpassword123', $agen->fresh()->password));
    }

    public function test_every_role_can_reach_the_profile_endpoints_not_just_office_roles(): void
    {
        $agen = User::factory()->agen()->create();
        $agen->update(['agent_id' => $agen->id]);
        $kurir = User::factory()->kurir()->create(['agent_id' => $agen->id]);

        $this->actingAs($kurir)->patchJson('/api/v1/profile', ['name' => 'Kurir Baru'])->assertOk();
    }
}
