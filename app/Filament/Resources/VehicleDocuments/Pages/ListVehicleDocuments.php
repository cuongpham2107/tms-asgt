<?php

namespace App\Filament\Resources\VehicleDocuments\Pages;

use App\Filament\Resources\VehicleDocuments\VehicleDocumentResource;
use App\Models\VehicleDocument;
use App\Services\Import\VehicleDocumentImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListVehicleDocuments extends ListRecords
{
    protected static string $resource = VehicleDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importAction(),
            CreateAction::make()
                ->label('Tạo giấy tờ xe')
                ->icon('heroicon-o-plus'),
        ];
    }

    private function importAction(): Action
    {
        return Action::make('importDocuments')
            ->label('Import Excel')
            ->icon('heroicon-m-arrow-up-tray')
            ->color('info')
            ->authorize(fn (): bool => Auth::user()?->can('create', VehicleDocument::class) ?? false)
            ->modalHeading('Import giấy tờ xe')
            ->modalDescription('Tải file mẫu, nhập mỗi dòng một giấy tờ rồi tải lên. Cùng xe + loại + số chứng nhận đã có thì cập nhật, ngược lại tạo mới; trạng thái tự tính theo ngày hết hạn.')
            ->modalSubmitActionLabel('Import')
            ->extraModalFooterActions([
                Action::make('downloadTemplate')
                    ->label('Tải file mẫu')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->action(fn (): StreamedResponse => $this->downloadTemplate()),
            ])
            ->schema([
                FileUpload::make('file')
                    ->label('File Excel')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                    ])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var TemporaryUploadedFile $file */
                $file = $data['file'];
                $result = app(VehicleDocumentImporter::class)->import($file->getRealPath(), (int) Auth::id());
                $errors = $result['errors'];

                Notification::make()
                    ->title("Đã tạo {$result['created']} và cập nhật {$result['updated']} giấy tờ xe")
                    ->body($errors === [] ? null : count($errors).' dòng bị bỏ qua: '.implode(' ', array_slice($errors, 0, 15)).(count($errors) > 15 ? ' …' : ''))
                    ->status($errors === [] ? 'success' : 'warning')
                    ->persistent($errors !== [])
                    ->send();
            });
    }

    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = app(VehicleDocumentImporter::class)->template();

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'Mau-import-giay-to-xe.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
