<?php

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Actions\ViewTripPhotosAction;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripPhoto;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $this->driver = User::factory()->create([
        'name' => 'Lái Xe Thử Nghiệm',
        'phone' => '0912345678',
    ]);
    $this->driver->assignRole($this->driverRole);

    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'load_capacity' => 5.0,
        'registration_number' => 'DK-888999',
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-PHOTO-TEST-001',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Started,
    ]);
});

test('ViewTripPhotosAction creates action with proper config', function () {
    $action = ViewTripPhotosAction::make();

    expect($action->getName())->toBe('view_photos')
        ->and($action->getLabel())->toBe('Ảnh chuyến đi')
        ->and($action->getIcon())->toBe('heroicon-o-camera')
        ->and($action->getColor())->toBe('info');
});

test('Trip model has photos and tripPhotos HasManyThrough relationship', function () {
    $checkpoint = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'occurred_at' => now(),
    ]);

    $photo1 = TripPhoto::create([
        'trip_checkpoint_id' => $checkpoint->id,
        'photo_path' => 'trip_photos/test1.jpg',
        'photo_url' => 'https://tms.asgl.net.vn/storage/trip_photos/test1.jpg',
    ]);

    $photo2 = TripPhoto::create([
        'trip_checkpoint_id' => $checkpoint->id,
        'photo_path' => 'trip_photos/test2.jpg',
        'photo_url' => 'https://tms.asgl.net.vn/storage/trip_photos/test2.jpg',
    ]);

    expect($this->trip->photos()->count())->toBe(2)
        ->and($this->trip->tripPhotos()->count())->toBe(2)
        ->and($this->trip->photos->pluck('id')->all())->toBe([$photo1->id, $photo2->id]);
});

test('ViewTripPhotosAction badge displays count when photos exist and null when empty', function () {
    $action = ViewTripPhotosAction::make();

    // Empty trip
    expect($action->record($this->trip)->getBadge())->toBeNull();

    // Add checkpoint and photo
    $checkpoint = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => now(),
    ]);

    TripPhoto::create([
        'trip_checkpoint_id' => $checkpoint->id,
        'photo_path' => 'trip_photos/test_badge.jpg',
    ]);

    $this->trip->unsetRelation('photos');
    expect($action->record($this->trip)->getBadge())->toBe('1');
});

test('trip-photos-modal blade view renders photos correctly', function () {
    $checkpoint = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => now(),
        'km_reading' => 125.5,
    ]);

    TripPhoto::create([
        'trip_checkpoint_id' => $checkpoint->id,
        'photo_path' => 'trip_photos/test_modal.jpg',
        'photo_url' => 'https://tms.asgl.net.vn/storage/trip_photos/test_modal.jpg',
    ]);

    $html = view('filament.resources.trips.components.trip-photos-modal', [
        'trip' => $this->trip,
    ])->render();

    expect($html)->toContain('TRIP-PHOTO-TEST-001')
        ->and($html)->toContain('29C-123.45')
        ->and($html)->toContain('Lái Xe Thử Nghiệm')
        ->and($html)->toContain('Hoàn thành')
        ->and($html)->toContain('1 ảnh');
});

test('trip-photos-modal blade view renders empty state when trip has no photos', function () {
    $html = view('filament.resources.trips.components.trip-photos-modal', [
        'trip' => $this->trip,
    ])->render();

    expect($html)->toContain('Chưa có ảnh nào')
        ->and($html)->toContain('TRIP-PHOTO-TEST-001');
});

test('view_photos action is present in trip table action group and renders modal content', function () {
    $admin = User::factory()->create();
    $adminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin->assignRole($adminRole);

    $this->actingAs($admin);

    Livewire::test(ListTrips::class)->loadTable()
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->assertTableActionExists('view_photos', record: $this->trip);

    $action = ViewTripPhotosAction::make();
    $modalContent = $action->record($this->trip)->getModalContent();
    expect($modalContent)->not->toBeNull()
        ->and($modalContent->render())->toContain('TRIP-PHOTO-TEST-001');
});

test('modal content includes download all photos link when photos exist', function () {
    $checkpoint = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => now(),
    ]);

    TripPhoto::create([
        'trip_checkpoint_id' => $checkpoint->id,
        'photo_path' => 'trip_photos/test_download.jpg',
    ]);

    $html = view('filament.resources.trips.components.trip-photos-modal', [
        'trip' => $this->trip,
    ])->render();

    expect($html)->toContain(route('trips.photos.download-zip', $this->trip))
        ->and($html)->toContain('Tải tất cả ảnh (.zip)');
});

test('ViewTripPhotosAction includes download_all in extraModalFooterActions', function () {
    $checkpoint = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => now(),
    ]);

    TripPhoto::create([
        'trip_checkpoint_id' => $checkpoint->id,
        'photo_path' => 'trip_photos/test_footer.jpg',
    ]);

    $action = ViewTripPhotosAction::make();
    $action->livewire(new class extends Component {});
    $footerActions = $action->record($this->trip)->getExtraModalFooterActions();

    expect($footerActions)->toHaveKey('download_all');
    $downloadAction = $footerActions['download_all'];
    expect($downloadAction)->not->toBeNull()
        ->and($downloadAction->getLabel())->toBe('Tải tất cả ảnh (.zip)')
        ->and($downloadAction->getUrl())->toBe(route('trips.photos.download-zip', $this->trip));
});

test('trips.photos.download-zip endpoint requires authentication', function () {
    $response = $this->get(route('trips.photos.download-zip', $this->trip));

    $response->assertRedirect(route('filament.app.auth.login'));
});

test('trips.photos.download-zip returns 404 when trip has no photos', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    $response = $this->get(route('trips.photos.download-zip', $this->trip));

    $response->assertNotFound();
});

test('trips.photos.download-zip generates zip and downloads all valid photos', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    Storage::fake('public');
    Storage::disk('public')->put('trip_photos/unit_test.jpg', 'fake-image-content-for-test');

    $checkpoint = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'occurred_at' => now(),
    ]);

    TripPhoto::create([
        'trip_checkpoint_id' => $checkpoint->id,
        'photo_path' => 'trip_photos/unit_test.jpg',
    ]);

    $response = $this->get(route('trips.photos.download-zip', $this->trip));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/zip')
        ->and($response->headers->get('Content-Disposition'))->toContain('.zip');
});
