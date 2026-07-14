<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DriverImportSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $password = Hash::make('password');
        $csvPath = base_path('data-taixe.csv');

        if (! file_exists($csvPath)) {
            $this->command?->error("CSV file not found: {$csvPath}");

            return;
        }

        $rows = array_map(fn ($line) => str_getcsv($line, escape: '\\'), file($csvPath));

        $drivers = [];
        $platePrimary = []; // track first driver per plate
        $seen = [];

        for ($i = 2; $i < count($rows); $i++) {
            $name = trim($rows[$i][2] ?? '');
            $plate = trim($rows[$i][4] ?? '');

            if ($name === '') {
                continue;
            }

            $station = trim($rows[$i][1] ?? '');
            $station = preg_replace('/[\x00-\x1F\x7F\xC2\xA0]/u', '', $station); // strip NBSP
            $station = match ($station) {
                'NB' => 'NBA',
                'BN', 'TN' => $station,
                default => null,
            };

            $phone = trim($rows[$i][3] ?? '');
            $phone = $phone === '' || $phone === "\xC2\xA0" ? null : preg_replace('/\s+/', '', $phone);

            $dob = $this->parseDate(trim($rows[$i][5] ?? ''));
            $licenseNumber = $this->parseLicenseNumber(trim($rows[$i][6] ?? ''));
            $licenseClass = $this->parseLicenseClass(trim($rows[$i][7] ?? ''));
            $licenseIssue = $this->parseDate(trim($rows[$i][8] ?? ''));
            $licenseExpiry = $this->parseDate(trim($rows[$i][9] ?? ''));
            $address = trim($rows[$i][10] ?? '');
            $cccd = trim($rows[$i][12] ?? '');
            $cccdIssue = $this->parseDate(trim($rows[$i][13] ?? ''));

            $email = $this->generateEmail($name, $dob);

            // Skip duplicates by email
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;

            // Track first driver per plate as primary
            $cleanPlate = preg_replace('/[\s.]/', '', $plate);
            if (! isset($platePrimary[$cleanPlate])) {
                $platePrimary[$cleanPlate] = $email;
            }

            $drivers[] = [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'phone' => $phone,
                'station' => $station,
                'plate' => $plate,
                'date_of_birth' => $dob,
                'license_number' => $licenseNumber,
                'license_class' => $licenseClass,
                'license_issue_date' => $licenseIssue,
                'license_expiry_date' => $licenseExpiry,
                'address' => $address,
                'cccd' => $cccd,
                'cccd_issue_date' => $cccdIssue,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Insert drivers
        foreach ($drivers as $d) {
            $plate = $d['plate'];
            unset($d['plate']);

            DB::table('users')->upsert(
                [$d],
                ['email'],
                ['name', 'phone', 'station', 'date_of_birth', 'license_number', 'license_class', 'license_issue_date', 'license_expiry_date', 'address', 'cccd', 'cccd_issue_date', 'is_active', 'updated_at'],
            );
        }

        // Assign vehicles to primary drivers (first driver per plate)
        foreach ($platePrimary as $plate => $email) {
            $driver = DB::table('users')->where('email', $email)->first();
            $vehicle = DB::table('vehicles')->where('plate_number', $plate)->first();
            if ($driver && $vehicle) {
                DB::table('vehicles')->where('id', $vehicle->id)->update(['current_driver_id' => $driver->id]);
            }
        }

        // Assign "driver" role to all imported drivers
        $driverRoleId = DB::table('roles')->where('name', 'driver')->value('id');
        if ($driverRoleId) {
            $imported = DB::table('users')->whereIn('email', array_keys($seen))->get();
            foreach ($imported as $user) {
                DB::table('model_has_roles')->upsert(
                    [['role_id' => $driverRoleId, 'model_type' => 'App\Models\User', 'model_id' => $user->id]],
                    ['role_id', 'model_id', 'model_type'],
                    ['role_id', 'model_id', 'model_type'],
                );
            }
        }

        $this->command?->info('Imported '.count($drivers).' drivers from CSV.');
    }

    private function parseDate(string $val): ?string
    {
        if ($val === '') {
            return null;
        }

        // dd-mm-yy → Y-m-d
        $parts = explode('-', $val);
        if (count($parts) === 3) {
            $year = (int) $parts[2];
            // Handle 2-digit years: 70-99 → 19xx, 00-69 → 20xx
            if ($year < 100) {
                $year += $year >= 70 ? 1900 : 2000;
            }

            return sprintf('%04d-%02d-%02d', $year, (int) $parts[1], (int) $parts[0]);
        }

        return null;
    }

    private function parseLicenseNumber(string $val): ?string
    {
        if ($val === '') {
            return null;
        }

        // Handle scientific notation like "2.70E+11" → "270000000000"
        if (stripos($val, 'E') !== false) {
            $float = (float) $val;

            return number_format($float, 0, '', '');
        }

        return $val;
    }

    private function parseLicenseClass(string $val): ?string
    {
        if ($val === '') {
            return null;
        }

        // Take the highest class: "A1,C" → "C", "A1,E" → "E", "E,FC" → "FC"
        $parts = explode(',', $val);
        $parts = array_map('trim', $parts);

        // Filter out A1, keep highest
        $filtered = array_filter($parts, fn ($c) => ! in_array($c, ['A1']));

        return ! empty($filtered) ? end($filtered) : ($parts[0] ?? null);
    }

    private function generateEmail(string $name, ?string $dob): string
    {
        // Generate initials: Phùng Đắc Hoàn → pdh
        $words = explode(' ', $name);
        $initials = '';
        foreach ($words as $word) {
            $first = mb_substr($word, 0, 1);
            $initials .= Str::ascii($first);
        }
        $initials = strtolower($initials);

        // Append ddmm from birth date
        $suffix = '';
        if ($dob) {
            $suffix = substr($dob, 8, 2).substr($dob, 5, 2);
        } else {
            $suffix = '0000';
        }

        $email = "{$initials}{$suffix}@tms.local";

        return $email;
    }
}
