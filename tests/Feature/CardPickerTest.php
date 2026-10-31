<?php

use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use Livewire\Livewire;

class PickerTestForm extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static string $picker = 'vehicle';

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        $picker = static::$picker === 'driver'
            ? DriverPicker::make('driver_id')
                ->leadingIcon('heroicon-o-user')
                ->cards([
                    ['value' => 7, 'title' => 'Nguyễn Văn A', 'subtitle' => 'Tài xế'],
                ])
            : VehiclePicker::make('vehicle_id')
                ->leadingIcon('heroicon-o-truck')
                ->cards([
                    [
                        'value' => 1,
                        'title' => '20C-12345',
                        'type' => 'company',
                        'details' => [
                            ['icon' => 'heroicon-m-truck', 'label' => 'Tải trọng', 'value' => '5 tấn'],
                        ],
                    ],
                    ['value' => 2, 'title' => '29H-99999', 'type' => 'rent'],
                ]);

        return $schema->components([$picker])->statePath('data');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}

function pickerConfiguration(string $mode): array
{
    PickerTestForm::$picker = $mode;

    return collect(Livewire::test(PickerTestForm::class)->instance()->getSchema('form')->getComponents())
        ->first()
        ->getRendererConfiguration();
}

test('vehicle picker sends cards, icons and the create-vehicle action to the react field', function () {
    $config = pickerConfiguration('vehicle');

    expect($config['mode'])->toBe('vehicle')
        ->and($config['defaultTab'])->toBe('company')
        ->and($config['hasTypes'])->toBeTrue()
        ->and($config['cards'])->toHaveCount(2)
        ->and($config['leadingIcon'])->toContain('<svg')
        ->and($config['icons']['heroicon-m-truck'])->toContain('<svg')
        ->and($config['createAction'])->toMatchArray([
            'name' => 'createVehicle',
            'label' => 'Tạo phương tiện',
            'schemaComponent' => 'form.vehicle_id',
        ]);
});

test('driver picker has no tabs and no create action', function () {
    $config = pickerConfiguration('driver');

    expect($config['mode'])->toBe('driver')
        ->and($config['defaultTab'])->toBe('all')
        ->and($config['hasSuggestions'])->toBeFalse()
        ->and($config['hasTypes'])->toBeFalse()
        ->and($config)->not->toHaveKey('createAction');
});

test('picker renders the react field host', function () {
    PickerTestForm::$picker = 'vehicle';

    Livewire::test(PickerTestForm::class)
        ->assertSuccessful()
        ->assertSeeHtml('jsFieldFormComponent')
        ->assertSeeHtml('card-picker');
});
