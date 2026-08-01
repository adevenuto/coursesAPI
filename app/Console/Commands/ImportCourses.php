<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCourses extends Command
{
    /**
     * @var string
     */
    protected $signature = 'courses:import
        {file=import_data/courses_rows.csv : Path to the CSV, relative to storage/app}
        {--chunk=200 : Number of rows per bulk insert}';

    /**
     * @var string
     */
    protected $description = 'Import courses from the source CSV into the courses table (truncate then load).';

    /**
     * Migration columns we import (city_id / state_id intentionally excluded).
     *
     * @var list<string>
     */
    private array $columns = [
        'course_name', 'club_name', 'address', 'postal_code',
        'lat', 'lng', 'phone', 'website', 'layout_data',
        'created_at', 'updated_at',
    ];

    public function handle(): int
    {
        $path = storage_path('app/'.$this->argument('file'));

        if (! is_readable($path)) {
            $this->error("CSV not found or unreadable: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Unable to open: {$path}");

            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            $this->error('CSV is empty.');
            fclose($handle);

            return self::FAILURE;
        }

        // Map header name => column index so we are position-independent.
        $index = array_flip(array_map('trim', $header));
        $required = ['course_name'];
        foreach ($required as $col) {
            if (! isset($index[$col])) {
                $this->error("CSV is missing required column: {$col}");
                fclose($handle);

                return self::FAILURE;
            }
        }

        $this->info('Truncating courses table...');
        DB::table('courses')->truncate();

        $chunkSize = max(1, (int) $this->option('chunk'));
        $buffer = [];
        $imported = 0;
        $skipped = 0;
        $line = 1; // header consumed

        DB::disableQueryLog();

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            $get = fn (string $key) => isset($index[$key]) ? ($row[$index[$key]] ?? null) : null;

            $name = $this->nullIfBlank($get('course_name'));
            if ($name === null) {
                $skipped++;

                continue;
            }

            $buffer[] = [
                'course_name' => $name,
                'club_name' => $this->nullIfBlank($get('club_name')),
                'address' => $this->nullIfBlank($get('street')),
                'postal_code' => $this->nullIfBlank($get('postal_code')),
                'lat' => $this->numericOrNull($get('lat')),
                'lng' => $this->numericOrNull($get('lng')),
                'phone' => $this->nullIfBlank($get('phone')),
                'website' => $this->nullIfBlank($get('website')),
                'layout_data' => $this->nullIfBlank($get('layout_data')),
                'created_at' => $this->parseTimestamp($get('created_at')),
                'updated_at' => $this->parseTimestamp($get('updated_at')),
            ];

            if (count($buffer) >= $chunkSize) {
                DB::table('courses')->insert($buffer);
                $imported += count($buffer);
                $buffer = [];
                $this->output->write("\rImported: {$imported}");
            }
        }

        if ($buffer !== []) {
            DB::table('courses')->insert($buffer);
            $imported += count($buffer);
        }

        fclose($handle);

        $this->newLine();
        $this->info("Done. Imported {$imported} courses, skipped {$skipped} (missing course_name).");

        return self::SUCCESS;
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function numericOrNull(?string $value): ?float
    {
        $value = $this->nullIfBlank($value);

        return $value !== null && is_numeric($value) ? (float) $value : null;
    }

    private function parseTimestamp(?string $value): ?string
    {
        $value = $this->nullIfBlank($value);
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
