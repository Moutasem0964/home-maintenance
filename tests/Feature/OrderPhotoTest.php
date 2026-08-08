<?php

namespace Tests\Feature;

use App\Enums\OrderPhotoKind;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderPhoto;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
        Storage::fake('local');
    }

    private function fundedClient(string $balance = '100.00'): User
    {
        $user = User::factory()->verified()->create();
        Wallet::create(['user_id' => $user->id]);
        app(WalletService::class)->topUp($user, $balance, 'seed-'.$user->id);

        return $user->refresh();
    }

    private function leafCategory(): ServiceCategory
    {
        $parent = ServiceCategory::factory()->create();

        return ServiceCategory::factory()->childOf($parent)->create();
    }

    public function test_client_can_attach_flaw_photos_on_order_creation(): void
    {
        $user = $this->fundedClient();
        $address = Address::factory()->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')->post('/api/orders', [
            'operation_id' => (string) Str::uuid(),
            'service_category_id' => $this->leafCategory()->id,
            'address_id' => $address->id,
            'type' => 'urgent',
            'photos' => [UploadedFile::fake()->image('flaw1.jpg'), UploadedFile::fake()->image('flaw2.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonCount(2, 'data.photos')
            ->assertJsonPath('data.photos.0.kind', 'flaw');

        $this->assertSame(2, OrderPhoto::where('order_id', $response->json('data.id'))->where('kind', 'flaw')->count());
    }

    public function test_rejects_more_than_three_photos(): void
    {
        $user = $this->fundedClient();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->post('/api/orders', [
            'operation_id' => (string) Str::uuid(),
            'service_category_id' => $this->leafCategory()->id,
            'address_id' => $address->id,
            'type' => 'urgent',
            'photos' => [
                UploadedFile::fake()->image('1.jpg'),
                UploadedFile::fake()->image('2.jpg'),
                UploadedFile::fake()->image('3.jpg'),
                UploadedFile::fake()->image('4.jpg'),
            ],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['photos']);
    }

    public function test_order_participant_can_view_a_photo_but_a_stranger_cannot(): void
    {
        $client = User::factory()->verified()->create();
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create(['client_id' => $client->id, 'technician_id' => $tech->id]);

        $photo = OrderPhoto::create([
            'order_id' => $order->id,
            'kind' => OrderPhotoKind::Flaw,
            'path' => UploadedFile::fake()->image('p.jpg')->store("orders/{$order->id}/flaw", 'local'),
            'uploaded_by' => $client->id,
        ]);

        // Participants can view.
        $this->actingAs($client, 'sanctum')->get("/api/order-photos/{$photo->id}")->assertOk();
        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')->get("/api/order-photos/{$photo->id}")->assertOk();

        // A stranger cannot; unauthenticated cannot.
        $stranger = User::factory()->verified()->create();
        $this->actingAs($stranger, 'sanctum')->get("/api/order-photos/{$photo->id}")->assertForbidden();
        $this->getJson("/api/order-photos/{$photo->id}")->assertUnauthorized();
    }
}
